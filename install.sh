#\!/usr/bin/env bash
# Compatibility shim for older update.sh versions.
# Uses bash (not exec) so CRLF in install.sh shebang cannot cause failures.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "${DIR}/qualid-pbx-runtime/sync-scripts/install.sh" "$@"
