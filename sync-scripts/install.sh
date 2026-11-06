#!/usr/bin/env bash
# =============================================================================
# Quali-D PBX OS — Install / Update Script
#
# Runs in two contexts:
#   A. First-boot activation  → called by setup wizard after the device is
#      provisioned; installs everything from scratch.
#   B. Auto-update            → called by update.sh with a new version argument;
#      copies updated files and reloads Asterisk without touching config.json.
#
# Usage:
#   ./install.sh [new_version]
#
# The script is intentionally idempotent — safe to run multiple times.
# =============================================================================

set -euo pipefail

NEW_VERSION="${1:-}"
QUALID_DIR="/usr/share/qualid"
CONFIG_DIR="/etc/qualid"
ASTERISK_ETC="/etc/asterisk"
AGI_BIN="/var/lib/asterisk/agi-bin"
SYSTEMD_DIR="/etc/systemd/system"
LOG_TAG="qualid-install"

log()  { logger -t "${LOG_TAG}" "$*"; echo "$*"; }
warn() { logger -t "${LOG_TAG}" "WARN: $*"; echo "WARN: $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# 1. Ensure required directories exist
# ---------------------------------------------------------------------------
mkdir -p "${QUALID_DIR}/sync-scripts"
mkdir -p "${CONFIG_DIR}"
mkdir -p "${AGI_BIN}"

# ---------------------------------------------------------------------------
# 2. Copy sync scripts
# ---------------------------------------------------------------------------
log "Installing sync scripts..."
# Skip copy when running from the installed location (first-boot).
# On auto-update SCRIPT_DIR is a temp extraction dir, so the paths differ.
if [ "${SCRIPT_DIR}" != "${QUALID_DIR}/sync-scripts" ]; then
    cp -f "${SCRIPT_DIR}/functions.inc.php" "${QUALID_DIR}/sync-scripts/"
    cp -f "${SCRIPT_DIR}/sync.php"          "${QUALID_DIR}/sync-scripts/"
    if [ -f "${SCRIPT_DIR}/deferred_rtp.php" ]; then
        cp -f "${SCRIPT_DIR}/deferred_rtp.php" "${QUALID_DIR}/sync-scripts/"
    fi
fi

# Always ensure update.sh is at the top-level path the updater service expects
# (/usr/share/qualid/update.sh). On first boot it lives in sync-scripts/;
# on auto-update it arrives from the tarball root — either way, copy it up.
_update_src="${SCRIPT_DIR}/update.sh"
[ -f "${_update_src}" ] || _update_src="${QUALID_DIR}/sync-scripts/update.sh"
if [ -f "${_update_src}" ]; then
    cp -f "${_update_src}" "${QUALID_DIR}/update.sh"
    chmod +x "${QUALID_DIR}/update.sh"
fi

# Copy AGI scripts if present in the release.
# They are deployed to two locations:
#   1. /var/lib/asterisk/agi-bin/      — for Asterisk to execute directly
#   2. /usr/share/qualid/sync-scripts/agi/ — so qualid_write_agi_files() can
#                                            redeploy them on demand (e.g. after
#                                            an update wipes agi-bin)
if ls "${SCRIPT_DIR}/../agi-bin/"*.php &>/dev/null 2>&1; then
    cp -f "${SCRIPT_DIR}/../agi-bin/"*.php "${AGI_BIN}/"
    chown asterisk:asterisk "${AGI_BIN}/"*.php 2>/dev/null || true
    chmod 755 "${AGI_BIN}/"*.php
    # Also install to sync-scripts/agi/ so qualid_write_agi_files() finds them
    mkdir -p "${QUALID_DIR}/sync-scripts/agi"
    cp -f "${SCRIPT_DIR}/../agi-bin/"*.php "${QUALID_DIR}/sync-scripts/agi/"
    chown asterisk:asterisk "${QUALID_DIR}/sync-scripts/agi/"*.php 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# 2a. Package checklist — runs on every first boot.
#     post-install.sh installs nginx/PHP/UFW during the Debian installer; if
#     the network was unavailable then, we catch and fix it here instead.
#     Asterisk is NOT in Debian 12 standard repos — installed here via the
#     official FreePBX Debian repository (deb.freepbx.org).
# ---------------------------------------------------------------------------
_APT_UPDATED=0
_ensure_pkg() {
    local bin="$1" pkg="$2" desc="$3"
    if ! command -v "${bin}" &>/dev/null; then
        log "MISSING: ${desc} — installing ${pkg}..."
        if [[ "${_APT_UPDATED}" -eq 0 ]]; then
            DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>/dev/null || true
            _APT_UPDATED=1
        fi
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q ${pkg} && log "${desc} installed." || \
            warn "${pkg} install failed — fix manually: apt-get install -y ${pkg}"
    else
        log "OK: ${desc}"
    fi
}

# Asterisk is not in Debian 12 standard repos.
# Install from the official FreePBX Debian repository (deb.freepbx.org).
if ! command -v asterisk &>/dev/null; then
    log "MISSING: Asterisk PBX — setting up FreePBX repo..."
    # Ensure gnupg is available for gpg --dearmor
    command -v gpg &>/dev/null || \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q gnupg 2>/dev/null || true
    if [[ ! -f /etc/apt/sources.list.d/freepbx.list ]]; then
        wget -qO /tmp/freepbx-aptly.asc http://deb.freepbx.org/gpg/aptly-pubkey.asc \
            && gpg --dearmor --yes -o /etc/apt/trusted.gpg.d/freepbx.gpg /tmp/freepbx-aptly.asc \
            && rm -f /tmp/freepbx-aptly.asc \
            && printf 'deb [ arch=amd64 ] http://deb.freepbx.org/freepbx17-prod bookworm main\n' \
                   > /etc/apt/sources.list.d/freepbx.list \
            && log "FreePBX repo added." \
            || warn "FreePBX repo setup failed — Asterisk will not install"
        DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>/dev/null || true
        _APT_UPDATED=1
    fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
        asterisk22 asterisk22-core asterisk22-configs libxslt1.1 liburiparser1 \
        && log "Asterisk installed." \
        || warn "Asterisk install failed — fix manually: apt-get install asterisk22"
    # The FreePBX package disables the init script during install — re-enable it
    systemctl enable asterisk 2>/dev/null || true
    systemctl start  asterisk 2>/dev/null || true
else
    log "OK: Asterisk PBX"
fi

_ensure_pkg nginx                "nginx"                 "nginx web server"
_ensure_pkg php                  "php-fpm php-curl"      "PHP-FPM"
_ensure_pkg ufw                  "ufw"                   "UFW firewall"

# ---------------------------------------------------------------------------
# 2b. Copy setup wizard files
# ---------------------------------------------------------------------------
WIZARD_SRC="${SCRIPT_DIR}/../setup-wizard"
WIZARD_DST="${QUALID_DIR}/setup-wizard"
if [[ -d "${WIZARD_SRC}" ]]; then
    log "Installing setup wizard..."

    mkdir -p "${WIZARD_DST}"
    # Only copy files when running from a different location (auto-update scenario).
    # On first-boot SCRIPT_DIR is already inside QUALID_DIR so src == dst.
    if [ "${SCRIPT_DIR}" != "${QUALID_DIR}/sync-scripts" ]; then
        cp -f "${WIZARD_SRC}/index.html"         "${WIZARD_DST}/"
        cp -f "${WIZARD_SRC}/active.html"        "${WIZARD_DST}/"
        cp -f "${WIZARD_SRC}/api.php"            "${WIZARD_DST}/"
        cp -f "${WIZARD_SRC}/nginx-wizard.conf"  "${WIZARD_DST}/"
        cp -f "${WIZARD_SRC}/nginx-active.conf"  "${WIZARD_DST}/"
        LOGO_SRC="${SCRIPT_DIR}/../QualiD_PBX_Logo.png"
        ICON_SRC="${SCRIPT_DIR}/../Icon.png"
        [[ -f "${LOGO_SRC}" ]] && cp -f "${LOGO_SRC}" "${WIZARD_DST}/"
        [[ -f "${ICON_SRC}" ]] && cp -f "${ICON_SRC}" "${WIZARD_DST}/"
    fi

    # Install nginx site — wizard (pre-activation) unless already activated
    if [[ ! -f "${CONFIG_DIR}/activated" ]]; then
        if [[ -d /etc/nginx/sites-available ]]; then
            cp -f "${WIZARD_SRC}/nginx-wizard.conf" /etc/nginx/sites-available/qualid
            ln -sfn /etc/nginx/sites-available/qualid /etc/nginx/sites-enabled/qualid
            # Remove default nginx site if present
            rm -f /etc/nginx/sites-enabled/default
            log "Nginx wizard site enabled."
        else
            warn "nginx not installed — skipping wizard site setup"
        fi
    fi

    # PHP-FPM pool for wizard (runs as www-data, socket at /run/php/php-fpm-qualid.sock)
    PHP_FPM_POOL="/etc/php/*/fpm/pool.d/qualid.conf"
    # Find the installed PHP version dynamically
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.2")
    PHP_POOL_DIR="/etc/php/${PHP_VER}/fpm/pool.d"
    if [[ -d "${PHP_POOL_DIR}" ]]; then
        cat > "${PHP_POOL_DIR}/qualid.conf" <<'POOL'
[qualid]
user  = www-data
group = www-data
listen = /run/php/php-fpm-qualid.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660
pm = dynamic
pm.max_children      = 5
pm.start_servers     = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
php_admin_value[error_log] = /var/log/qualid-wizard.log
php_admin_flag[log_errors] = on
; Allow shell_exec — Debian php.ini may disable it; needed for Asterisk CLI calls
php_admin_value[disable_functions] =
POOL
        sed -i 's/\r//' "${PHP_POOL_DIR}/qualid.conf"
        # Create error log and ensure www-data can write to /etc/qualid
        touch /var/log/qualid-wizard.log
        chown www-data:www-data /var/log/qualid-wizard.log
        chown www-data:www-data /etc/qualid
        # www-data must be able to write Asterisk config on first activation.
        # Add it to the asterisk group and make /etc/asterisk group-writable.
        if id -u asterisk &>/dev/null; then
            usermod -aG asterisk www-data 2>/dev/null || true
            chown root:asterisk /etc/asterisk 2>/dev/null || true
            chmod g+w /etc/asterisk 2>/dev/null || true
        fi
        systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
        log "PHP-FPM pool configured."
    fi

    # Sudoers rule so www-data can enable timers, reload nginx, and reload
    # Asterisk after activation.  The asterisk.ctl socket is root-owned so
    # www-data needs sudo to reach it.
    cat > /etc/sudoers.d/qualid-wizard <<'SUDO'
www-data ALL=(root) NOPASSWD: /bin/systemctl enable qualid-sync.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl start  qualid-sync.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl enable --now qualid-sync.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl enable qualid-updater.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl start  qualid-updater.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl enable --now qualid-updater.timer
www-data ALL=(root) NOPASSWD: /bin/systemctl reload nginx
www-data ALL=(root) NOPASSWD: /bin/cp /usr/share/qualid/setup-wizard/nginx-active.conf /etc/nginx/sites-available/qualid
www-data ALL=(root) NOPASSWD: /usr/sbin/asterisk
asterisk  ALL=(root) NOPASSWD: /usr/sbin/asterisk
SUDO
    chmod 440 /etc/sudoers.d/qualid-wizard
    log "Sudoers rules written."

    systemctl enable nginx 2>/dev/null || true
    systemctl reload nginx 2>/dev/null || systemctl start nginx 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# 3. Install / update systemd unit files
# ---------------------------------------------------------------------------
log "Installing systemd units..."
UNIT_SRC="${SCRIPT_DIR}/../systemd"
if [[ -d "${UNIT_SRC}" ]]; then
    cp -f "${UNIT_SRC}/qualid-sync.service"    "${SYSTEMD_DIR}/"
    cp -f "${UNIT_SRC}/qualid-sync.timer"      "${SYSTEMD_DIR}/"
    cp -f "${UNIT_SRC}/qualid-updater.service" "${SYSTEMD_DIR}/"
    cp -f "${UNIT_SRC}/qualid-updater.timer"   "${SYSTEMD_DIR}/"
    systemctl daemon-reload
    # Only start timers if already provisioned (config.json exists).
    # On first boot config.json doesn't exist yet — the setup wizard creates it.
    # On auto-updates config.json is already present so timers restart normally.
    if [[ -f "${CONFIG_DIR}/config.json" ]]; then
        systemctl enable --now qualid-sync.timer
        systemctl enable --now qualid-updater.timer
        log "Systemd timers enabled."
    else
        systemctl enable qualid-sync.timer
        systemctl enable qualid-updater.timer
        log "Systemd timers registered (will start after provisioning)."
    fi
fi

# ---------------------------------------------------------------------------
# 4. Write /etc/qualid/version
# ---------------------------------------------------------------------------
if [[ -n "${NEW_VERSION}" ]]; then
    echo "${NEW_VERSION}" > "${CONFIG_DIR}/version"
    log "Version set to ${NEW_VERSION}."
elif [[ ! -f "${CONFIG_DIR}/version" && -f "${SCRIPT_DIR}/../VERSION" ]]; then
    cp -f "${SCRIPT_DIR}/../VERSION" "${CONFIG_DIR}/version"
fi

# ---------------------------------------------------------------------------
# 5. Fix ownership
# ---------------------------------------------------------------------------
# asterisk user only exists after asterisk is installed — guard all chowns
if id -u asterisk &>/dev/null; then
    chown -R asterisk:asterisk "${QUALID_DIR}/sync-scripts" || true
    chown asterisk:asterisk "${CONFIG_DIR}/version" 2>/dev/null || true
    if [[ -f "${CONFIG_DIR}/config.json" ]]; then
        chown asterisk:asterisk "${CONFIG_DIR}/config.json"
        chmod 660 "${CONFIG_DIR}/config.json"
    fi
    # The sync service runs as asterisk and needs to write config files to
    # /etc/asterisk (TURN creds, queues, extensions).  Make the directory
    # group-writable by the asterisk group and fix any existing qualid files.
    chown root:asterisk /etc/asterisk 2>/dev/null || true
    chmod g+w /etc/asterisk 2>/dev/null || true
    for f in /etc/asterisk/rtp_custom.conf \
              /etc/asterisk/pjsip_qualid.conf \
              /etc/asterisk/pjsip_qualid_extensions.conf \
              /etc/asterisk/extensions_qualid.conf \
              /etc/asterisk/extensions_qualid_outbound.conf \
              /etc/asterisk/queues_qualid.conf; do
        touch "$f" 2>/dev/null || true
        chown root:asterisk "$f" 2>/dev/null || true
        chmod 664 "$f" 2>/dev/null || true
    done

    # Pre-seed #include lines into the main Asterisk config files so the
    # setup wizard only needs to CREATE/WRITE pjsip_qualid.conf etc. rather
    # than also modifying the main configs (which may have tighter permissions).
    for _conf in pjsip extensions queues; do
        _main="${ASTERISK_ETC}/${_conf}.conf"
        _qualid="${_conf}_qualid.conf"
        if [[ -f "${_main}" ]] && ! grep -q "${_qualid}" "${_main}"; then
            echo "#include \"${_qualid}\"" >> "${_main}"
            log "Added #include ${_qualid} to ${_main}"
        fi
    done
else
    warn "asterisk user not found — skipping asterisk ownership (install asterisk to fix)"
fi

# ---------------------------------------------------------------------------
# 6. Reload Asterisk dialplan (non-fatal if Asterisk is not up yet)
# ---------------------------------------------------------------------------
if /usr/sbin/asterisk -rx "core show version" &>/dev/null; then
    /usr/sbin/asterisk -rx "dialplan reload"      &>/dev/null || true
    /usr/sbin/asterisk -rx "module reload res_pjsip.so" &>/dev/null || true
    log "Asterisk reloaded."
fi

# ---------------------------------------------------------------------------
# 7. Hardening — run once on first install (skip on auto-updates)
# ---------------------------------------------------------------------------
HARDEN_SCRIPT="${SCRIPT_DIR}/../hardening/harden.sh"
HARDEN_DONE="${CONFIG_DIR}/hardened"
if [[ -f "${HARDEN_SCRIPT}" && ! -f "${HARDEN_DONE}" ]]; then
    log "Running system hardening..."
    chmod +x "${HARDEN_SCRIPT}"
    bash "${HARDEN_SCRIPT}" && touch "${HARDEN_DONE}" || warn "Hardening script failed (non-fatal)."
fi

# ---------------------------------------------------------------------------
# 8. Copy SSH banner (harden.sh references /etc/ssh/qualid-banner)
# ---------------------------------------------------------------------------
BANNER_SRC="${SCRIPT_DIR}/../hardening/ssh/qualid-banner"
if [[ -f "${BANNER_SRC}" && ! -f /etc/ssh/qualid-banner ]]; then
    cp -f "${BANNER_SRC}" /etc/ssh/qualid-banner
fi

log "Install complete."
