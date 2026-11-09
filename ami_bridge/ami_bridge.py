#!/usr/bin/env python3
"""
Quali-D AMI Bridge — connects Asterisk AMI to the Quali-D relay server.

Runs as a systemd service on the PBX (on-premise).
Opens two persistent connections:
  1. Inbound: TCP to Asterisk AMI on 127.0.0.1:5038
  2. Outbound: WebSocket to relay server on ws://<relay_host>:3000/ami-bridge

Forwards queue call events (AgentCalled, AgentConnect, AgentComplete)
to the relay so the owner can monitor live calls from the Vue dashboard.

Stdlib only — no pip dependencies.
"""

import base64
import hashlib
import json
import logging
import os
import random
import socket
import ssl
import string
import threading
import time

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

CONFIG_PATH    = '/etc/qualid/config.json'
AMI_HOST       = '127.0.0.1'
AMI_PORT       = 5038
AMI_USERNAME   = 'qualid-monitor'
RELAY_WS_PATH  = '/ami-bridge'
RELAY_WS_PORT  = 3000
RECONNECT_MIN  = 5    # seconds
RECONNECT_MAX  = 60   # seconds
PING_INTERVAL  = 30   # seconds

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [ami-bridge] %(levelname)s %(message)s',
    handlers=[logging.StreamHandler()],
)
log = logging.getLogger('ami-bridge')


def load_config():
    """Read /etc/qualid/config.json and return relevant keys."""
    try:
        with open(CONFIG_PATH, 'r') as f:
            cfg = json.load(f)
    except FileNotFoundError:
        log.error("Config file not found: %s — waiting for provisioning", CONFIG_PATH)
        return None
    except json.JSONDecodeError as e:
        log.error("Config file is invalid JSON: %s", e)
        return None

    token = cfg.get('token', '')
    ami_secret = cfg.get('ami_monitor_secret', '')
    relay_host = cfg.get('relay_host', '13.140.143.85')
    relay_port = int(cfg.get('relay_ws_port', RELAY_WS_PORT))

    if not token:
        log.warning("No token in config — not provisioned yet")
        return None
    if not ami_secret:
        log.warning("No ami_monitor_secret in config — run sync to generate it")
        return None

    device_id = cfg.get('device_id', '')
    if not device_id:
        # Derive a stable device_id from the token so existing deployments
        # without an explicit device_id in config still work.
        device_id = 'dev_' + hashlib.sha256(token.encode()).hexdigest()[:16]

    return {
        'token':      token,
        'ami_secret': ami_secret,
        'relay_host': relay_host,
        'relay_port': relay_port,
        'device_id':  device_id,
    }


# ---------------------------------------------------------------------------
# Minimal WebSocket client (stdlib only)
# ---------------------------------------------------------------------------

def _recv_exact(sock, n):
    """Receive exactly n bytes from socket."""
    buf = b''
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise ConnectionError("Socket closed")
        buf += chunk
    return buf


def ws_connect(host, port, path, headers=None):
    """
    Open a WebSocket connection (plain TCP or TLS depending on port).
    Port 443 → WSS (TLS); anything else → plain WS.
    """
    raw_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    raw_sock.settimeout(15)
    raw_sock.connect((host, port))

    if port == 443:
        ctx = ssl.create_default_context()
        sock = ctx.wrap_socket(raw_sock, server_hostname=host)
    else:
        sock = raw_sock

    # Generate a random Sec-WebSocket-Key
    ws_key = base64.b64encode(os.urandom(16)).decode()

    # Build HTTP upgrade request
    extra_headers = ''
    if headers:
        for k, v in headers.items():
            extra_headers += f'{k}: {v}\r\n'

    host_header = host if port in (80, 443) else f'{host}:{port}'
    request = (
        f'GET {path} HTTP/1.1\r\n'
        f'Host: {host_header}\r\n'
        f'Upgrade: websocket\r\n'
        f'Connection: Upgrade\r\n'
        f'Sec-WebSocket-Key: {ws_key}\r\n'
        f'Sec-WebSocket-Version: 13\r\n'
        f'{extra_headers}'
        f'\r\n'
    )
    sock.sendall(request.encode())

    # Read HTTP response headers
    response = b''
    while b'\r\n\r\n' not in response:
        chunk = sock.recv(4096)
        if not chunk:
            raise ConnectionError("No response from server")
        response += chunk

    status_line = response.split(b'\r\n', 1)[0].decode()
    if '101' not in status_line:
        raise ConnectionError(f"WebSocket upgrade failed: {status_line}")

    # Verify Sec-WebSocket-Accept
    expected_accept = base64.b64encode(
        hashlib.sha1((ws_key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()
    ).decode()
    if expected_accept.encode() not in response:
        raise ConnectionError("WebSocket accept key mismatch")

    sock.settimeout(None)   # Switch to blocking mode
    log.info("WebSocket connected to %s:%s%s", host, port, path)
    return sock


def ws_send(sock, data):
    """Send a masked text frame."""
    payload = data.encode('utf-8')
    n = len(payload)
    mask = os.urandom(4)
    masked = bytes(payload[i] ^ mask[i % 4] for i in range(n))

    header = bytearray([0x81])   # FIN=1, opcode=text
    if n <= 125:
        header.append(0x80 | n)
    elif n <= 65535:
        header.append(0x80 | 126)
        header += n.to_bytes(2, 'big')
    else:
        header.append(0x80 | 127)
        header += n.to_bytes(8, 'big')
    header += mask
    sock.sendall(bytes(header) + masked)


def ws_send_ping(sock):
    """Send a WebSocket ping frame."""
    sock.sendall(bytes([0x89, 0x80]) + os.urandom(4))   # FIN+ping, masked, 0-length


def ws_recv(sock):
    """
    Receive one WebSocket frame.
    Returns (opcode, payload_str) or (None, None) on ping.
    Raises ConnectionError on close frame or disconnect.
    """
    b = _recv_exact(sock, 2)
    opcode = b[0] & 0x0F
    masked = (b[1] & 0x80) != 0
    length = b[1] & 0x7F

    if length == 126:
        length = int.from_bytes(_recv_exact(sock, 2), 'big')
    elif length == 127:
        length = int.from_bytes(_recv_exact(sock, 8), 'big')

    if masked:
        mask_key = _recv_exact(sock, 4)
        raw = bytearray(_recv_exact(sock, length))
        payload = bytes(raw[i] ^ mask_key[i % 4] for i in range(length))
    else:
        payload = _recv_exact(sock, length)

    if opcode == 0x08:   # Close
        raise ConnectionError("Server sent close frame")
    if opcode == 0x09:   # Ping — reply with pong
        pong = bytes([0x8A, 0x80]) + os.urandom(4)
        try:
            sock.sendall(pong)
        except Exception:
            pass
        return None, None
    if opcode == 0x0A:   # Pong
        return None, None

    return opcode, payload.decode('utf-8', errors='replace')


# ---------------------------------------------------------------------------
# AMI client
# ---------------------------------------------------------------------------

class AmiClient:
    """
    Minimal Asterisk Manager Interface client.
    Uses plain TCP (AMI is plaintext on port 5038 — localhost only).
    """

    def __init__(self, host, port, username, secret):
        self.host     = host
        self.port     = port
        self.username = username
        self.secret   = secret
        self._sock    = None
        self._buf     = ''

    def connect(self):
        log.info("Connecting to AMI at %s:%s", self.host, self.port)
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._sock.settimeout(10)
        self._sock.connect((self.host, self.port))
        # Read the AMI greeting line
        greeting = b''
        while b'\n' not in greeting:
            greeting += self._sock.recv(256)
        log.debug("AMI greeting: %s", greeting.decode().strip())
        self._sock.settimeout(None)

        self._send({'Action': 'Login', 'Username': self.username, 'Secret': self.secret,
                    'Events': 'system,call,agent'})
        resp = self._read_msg()
        if resp.get('Response') != 'Success':
            raise ConnectionError(f"AMI login failed: {resp.get('Message', resp)}")
        log.info("AMI login successful")

    def _send(self, fields):
        msg = ''.join(f'{k}: {v}\r\n' for k, v in fields.items()) + '\r\n'
        self._sock.sendall(msg.encode())

    def _read_msg(self):
        """Read one AMI message block (terminated by \\r\\n\\r\\n)."""
        while '\r\n\r\n' not in self._buf:
            chunk = self._sock.recv(4096).decode('utf-8', errors='replace')
            if not chunk:
                raise ConnectionError("AMI socket disconnected")
            self._buf += chunk
        raw, self._buf = self._buf.split('\r\n\r\n', 1)
        result = {}
        for line in raw.strip().splitlines():
            if ': ' in line:
                k, v = line.split(': ', 1)
                result[k.strip()] = v.strip()
        return result

    def events(self):
        """Generator — yield AMI event dicts indefinitely."""
        while True:
            yield self._read_msg()

    def close(self):
        if self._sock:
            try:
                self._sock.close()
            except Exception:
                pass
            self._sock = None


# ---------------------------------------------------------------------------
# State — in-memory active calls (keyed by Asterisk Uniqueid)
# ---------------------------------------------------------------------------

_active_calls    = {}   # uniqueid → { queue, caller, agent_ext, started_at, answered_at, channel }
_state_lock      = threading.Lock()
_monitor_threads = {}   # call_id → threading.Event (stop signal for FIFO reader)


def _now_iso():
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


# ---------------------------------------------------------------------------
# Event handler
# ---------------------------------------------------------------------------

def handle_event(event, ws_sock, ws_lock):
    """Process one AMI event and push to relay if relevant."""
    evt = event.get('Event', '')

    if evt == 'AgentCalled':
        uniqueid    = event.get('Uniqueid', '')
        queue       = event.get('Queue', '')
        caller_num  = event.get('CallerIDNum', '')
        caller_name = event.get('CallerIDName', '')
        member      = event.get('Interface', event.get('MemberName', ''))
        # Extract extension from PJSIP/3001 → 3001
        agent_ext = member.split('/')[-1] if '/' in member else member

        with _state_lock:
            _active_calls[uniqueid] = {
                'uniqueid':    uniqueid,
                'queue':       queue,
                'caller_num':  caller_num,
                'caller_name': caller_name,
                'agent_ext':   agent_ext,
                'started_at':  _now_iso(),
                'answered_at': None,
            }
        payload = json.dumps({
            'type':         'call_start',
            'call_id':      uniqueid,
            'queue':        queue,
            'caller_number': caller_num,
            'caller_name':  caller_name,
            'agent_ext':    agent_ext,
            'timestamp':    _now_iso(),
        })
        _ws_push(ws_sock, ws_lock, payload)

    elif evt == 'AgentConnect':
        uniqueid = event.get('Uniqueid', '')
        channel  = event.get('Channel', '')
        with _state_lock:
            if uniqueid in _active_calls:
                _active_calls[uniqueid]['answered_at'] = _now_iso()
                if channel:
                    _active_calls[uniqueid]['channel'] = channel
        payload = json.dumps({
            'type':      'call_answered',
            'call_id':   uniqueid,
            'timestamp': _now_iso(),
        })
        _ws_push(ws_sock, ws_lock, payload)

    elif evt in ('AgentComplete', 'AgentDump', 'AgentRingNoAnswer'):
        uniqueid = event.get('Uniqueid', '')
        with _state_lock:
            call = _active_calls.pop(uniqueid, {})
        # Signal any active monitor reader to stop (Asterisk also closes the FIFO
        # automatically when the channel hangs up, but set the event for safety).
        _stop_mixmonitor(uniqueid, call.get('channel', ''), ami=None)
        payload = json.dumps({
            'type':      'call_end',
            'call_id':   uniqueid,
            'reason':    evt,
            'timestamp': _now_iso(),
        })
        _ws_push(ws_sock, ws_lock, payload)


def _ws_push(ws_sock, ws_lock, payload):
    """Thread-safe WebSocket send. Silently drops if not connected."""
    if ws_sock[0] is None:
        return
    try:
        with ws_lock:
            ws_send(ws_sock[0], payload)
    except Exception as e:
        log.warning("WS send failed: %s", e)
        ws_sock[0] = None   # Mark as disconnected; relay thread will reconnect


# ---------------------------------------------------------------------------
# Live audio monitoring — MixMonitor + named FIFO
# ---------------------------------------------------------------------------

def _start_mixmonitor(call_id, channel, ami, ws_sock, ws_lock):
    """Start MixMonitor for call_id on channel, stream PCM chunks to relay."""
    if call_id in _monitor_threads:
        return   # already monitoring this call
    fifo_path = f'/tmp/qualid_monitor_{call_id}.slin'
    try:
        if os.path.exists(fifo_path):
            os.unlink(fifo_path)
        os.mkfifo(fifo_path)
    except Exception as e:
        log.warning("Could not create FIFO %s: %s", fifo_path, e)
        return

    stop_evt = threading.Event()
    _monitor_threads[call_id] = stop_evt

    def _reader():
        try:
            # open() blocks until Asterisk opens the FIFO for writing (MixMonitor start)
            with open(fifo_path, 'rb') as f:
                while not stop_evt.is_set():
                    chunk = f.read(320)   # 20 ms @ 8 kHz 16-bit slin
                    if not chunk:
                        break
                    payload = json.dumps({
                        'type':    'audio_chunk',
                        'call_id': call_id,
                        'data':    base64.b64encode(chunk).decode(),
                    })
                    _ws_push(ws_sock, ws_lock, payload)
        except Exception as e:
            log.warning("Monitor reader error (call %s): %s", call_id, e)
        finally:
            try:
                os.unlink(fifo_path)
            except Exception:
                pass
            _monitor_threads.pop(call_id, None)
            log.info("Monitor reader exited — call %s", call_id)

    t = threading.Thread(target=_reader, daemon=True)
    t.start()
    # Send MixMonitor AFTER the reader thread is started — the reader's open()
    # call will block until Asterisk opens the write end of the FIFO.
    ami._send({
        'Action':  'MixMonitor',
        'Channel': channel,
        'File':    fifo_path,
        'Options': 'b',   # mix both directions into one stream
    })
    log.info("MixMonitor started — call %s channel %s", call_id, channel)


def _stop_mixmonitor(call_id, channel, ami):
    """Signal the reader thread to stop and optionally send StopMixMonitor to Asterisk."""
    stop_evt = _monitor_threads.pop(call_id, None)
    if stop_evt:
        stop_evt.set()
    if ami and channel:
        try:
            ami._send({'Action': 'StopMixMonitor', 'Channel': channel})
        except Exception:
            pass
    log.info("MixMonitor stopped — call %s", call_id)


def handle_relay_command(msg, ami_ref, ws_sock, ws_lock):
    """Handle incoming commands pushed from the relay to ami_bridge."""
    cmd = msg.get('type', '')
    if cmd == 'start_monitor':
        call_id = msg.get('call_id', '')
        with _state_lock:
            call = _active_calls.get(call_id, {})
        channel = call.get('channel', '')
        ami     = ami_ref[0]
        if channel and ami:
            _start_mixmonitor(call_id, channel, ami, ws_sock, ws_lock)
        else:
            log.warning("start_monitor: no channel or AMI for call %s", call_id)
    elif cmd == 'stop_monitor':
        call_id = msg.get('call_id', '')
        with _state_lock:
            call = _active_calls.get(call_id, {})
        channel = call.get('channel', '')
        _stop_mixmonitor(call_id, channel, ami_ref[0])


# ---------------------------------------------------------------------------
# Relay WebSocket thread
# ---------------------------------------------------------------------------

def relay_thread(cfg, ws_sock, ws_lock, ami_ref):
    """
    Background thread: maintains the WebSocket connection to the relay.
    ws_sock is a list of [socket_or_None] shared with the main thread.
    """
    backoff = RECONNECT_MIN
    while True:
        try:
            path = f'{RELAY_WS_PATH}?device_id={cfg["device_id"]}'
            sock = ws_connect(
                cfg['relay_host'],
                cfg['relay_port'],
                path,
                headers={'Authorization': f'Bearer {cfg["token"]}'},
            )
            with ws_lock:
                ws_sock[0] = sock
            backoff = RECONNECT_MIN
            log.info("Relay WebSocket connected (device_id=%s)", cfg['device_id'])

            # Keep-alive loop: relay may push commands or pings
            last_ping = time.time()
            while True:
                # Non-blocking check for incoming frames
                sock.settimeout(1.0)
                try:
                    opcode, text = ws_recv(sock)
                    if opcode == 0x01 and text:
                        try:
                            msg = json.loads(text)
                            handle_relay_command(msg, ami_ref, ws_sock, ws_lock)
                        except Exception as e:
                            log.warning("Relay command error: %s", e)
                except socket.timeout:
                    pass
                except ConnectionError as e:
                    log.info("Relay WS closed: %s", e)
                    break
                finally:
                    sock.settimeout(None)

                # Periodic ping
                if time.time() - last_ping > PING_INTERVAL:
                    try:
                        with ws_lock:
                            ws_send_ping(sock)
                        last_ping = time.time()
                    except Exception:
                        break

        except Exception as e:
            log.warning("Relay WS connect failed: %s — retry in %ds", e, backoff)

        with ws_lock:
            if ws_sock[0]:
                try:
                    ws_sock[0].close()
                except Exception:
                    pass
                ws_sock[0] = None

        time.sleep(backoff)
        backoff = min(backoff * 2, RECONNECT_MAX)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    log.info("Quali-D AMI Bridge starting")

    while True:
        cfg = load_config()
        if cfg is None:
            log.info("Waiting for provisioning (60s)...")
            time.sleep(60)
            continue

        # Shared mutable socket reference — relay thread writes, main thread reads
        ws_sock  = [None]
        ws_lock  = threading.Lock()
        ami_ref  = [None]   # relay_thread uses this to send AMI actions for monitoring

        # Start relay WebSocket thread
        t = threading.Thread(target=relay_thread, args=(cfg, ws_sock, ws_lock, ami_ref), daemon=True)
        t.start()

        # Wait for initial relay connection (up to 30s)
        for _ in range(30):
            if ws_sock[0] is not None:
                break
            time.sleep(1)

        # AMI connection loop
        ami = AmiClient(AMI_HOST, AMI_PORT, AMI_USERNAME, cfg['ami_secret'])
        ami_backoff = RECONNECT_MIN
        while True:
            try:
                ami.connect()
                ami_ref[0] = ami   # relay_thread can now send AMI actions
                ami_backoff = RECONNECT_MIN
                for event in ami.events():
                    handle_event(event, ws_sock, ws_lock)
            except Exception as e:
                log.warning("AMI error: %s — retry in %ds", e, ami_backoff)
                ami_ref[0] = None  # prevent relay_thread from using a dead connection
                ami.close()
                time.sleep(ami_backoff)
                ami_backoff = min(ami_backoff * 2, RECONNECT_MAX)

                # Reload config on reconnect (token may have changed)
                new_cfg = load_config()
                if new_cfg:
                    cfg = new_cfg


if __name__ == '__main__':
    main()
