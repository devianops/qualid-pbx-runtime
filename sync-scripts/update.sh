#!/usr/bin/env bash
# =============================================================================
# Quali-D PBX OS — Auto-Updater
# Runs as root via qualid-updater.service (systemd timer, every hour).
#
# What it does:
#   1. Checks https://quali-d.com/downloads/firmware/version.txt for a newer version
#   2. If found: downloads qualid_pbx_os.tar.gz from the same path, extracts, runs install.sh
#   3. Always: ensures Asterisk is running (offline watchdog)
# =============================================================================

set -euo pipefail

QUALID_DIR="/usr/share/qualid"
CONFIG_DIR="/etc/qualid"
VERSION_FILE="${CONFIG_DIR}/version"
UPDATE_BASE="https://quali-d.com/downloads/firmware"
VERSION_URL="${UPDATE_BASE}/version.txt"
TARBALL_URL="${UPDATE_BASE}/qualid_pbx_os.tar.gz"
TMP_DIR=$(mktemp -d /tmp/qualid-update.XXXXXX)
LOG_TAG="qualid-updater"
# Log file in /etc/qualid/ — accessible to www-data (owned by www-data) so the
# web UI can display live progress.  /tmp is a private tmpfs inside the service.
LOG_FILE="${CONFIG_DIR}/update.log"
> "${LOG_FILE}" 2>/dev/null || true
chmod 644 "${LOG_FILE}" 2>/dev/null || true

log()  { logger -t "${LOG_TAG}" "$*"; echo "$*" | tee -a "${LOG_FILE}"; }
warn() { logger -t "${LOG_TAG}" "WARN: $*"; echo "WARN: $*" | tee -a "${LOG_FILE}" >&2; }

# ---------------------------------------------------------------------------
# Offline watchdog — always runs regardless of update availability
# ---------------------------------------------------------------------------
watchdog() {
    if ! /usr/sbin/asterisk -rx "core show version" &>/dev/null; then
        warn "Asterisk not responding — restarting..."
        systemctl restart asterisk || true
    fi
}

# ---------------------------------------------------------------------------
# Cleanup temp dir on exit
# ---------------------------------------------------------------------------
cleanup() { rm -rf "${TMP_DIR}"; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# 1. Read current installed version
# ---------------------------------------------------------------------------
current_version="0.0.0"
if [[ -f "${VERSION_FILE}" ]]; then
    current_version=$(cat "${VERSION_FILE}" | tr -d '[:space:]')
fi
log "Current version: ${current_version}"

# ---------------------------------------------------------------------------
# 2. Fetch latest version from Quali-D update server
# ---------------------------------------------------------------------------
latest_version=$(curl -sf --max-time 15 "${VERSION_URL}" 2>/dev/null | tr -d '[:space:]') || {
    warn "Update server unreachable — skipping update check"
    watchdog
    exit 0
}

if [[ -z "${latest_version}" ]]; then
    warn "Could not read latest version from update server — skipping"
    watchdog
    exit 0
fi

log "Latest release: ${latest_version}"

# ---------------------------------------------------------------------------
# 3. Compare versions (simple lexicographic — works for x.y.z semver)
# ---------------------------------------------------------------------------
if [[ "${latest_version}" == "${current_version}" ]]; then
    log "Already up to date."
    watchdog
    exit 0
fi

# Check if latest > current using sort -V
newer=$(printf '%s\n%s\n' "${current_version}" "${latest_version}" \
    | sort -V | tail -1)
if [[ "${newer}" != "${latest_version}" ]]; then
    log "Installed version (${current_version}) is newer than release — skipping"
    watchdog
    exit 0
fi

# ---------------------------------------------------------------------------
# 4. Download and extract the release tarball
# ---------------------------------------------------------------------------
tarball="${TMP_DIR}/qualid_pbx_os.tar.gz"

log "Downloading update package..."
curl -sfL --max-time 120 -o "${tarball}" "${TARBALL_URL}" || {
    warn "Download failed — aborting update"
    watchdog
    exit 1
}

tar -xzf "${tarball}" -C "${TMP_DIR}" || {
    warn "Extraction failed — aborting update"
    watchdog
    exit 1
}

# ---------------------------------------------------------------------------
# 5. Run install.sh from the extracted payload
# ---------------------------------------------------------------------------
# The tarball contains a top-level qualid-pbx-runtime/ directory; install.sh
# lives under sync-scripts/ inside it.
install_sh="${TMP_DIR}/qualid-pbx-runtime/sync-scripts/install.sh"
if [[ ! -f "${install_sh}" ]]; then
    warn "No install.sh found in release tarball (expected: ${install_sh}) — aborting"
    watchdog
    exit 1
fi

chmod +x "${install_sh}"
log "Installing version ${latest_version}..."
bash "${install_sh}" "${latest_version}" || {
    warn "install.sh exited with error — rollback may be needed"
    watchdog
    exit 1
}

log "Update to ${latest_version} complete."
watchdog
