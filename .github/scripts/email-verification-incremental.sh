#!/usr/bin/env bash
# Invoked only after the independent approval API gate passes.
set -euo pipefail
umask 077
private_dir="$(mktemp -d "$RUNNER_TEMP/pwdwork-email-incremental.XXXXXXXX")"
container_name="pwdwork-email-incremental-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
cleanup() {
  docker rm -f "$container_name" >/dev/null 2>&1 || true
  sudo rm -f -- "$private_dir/ca.pem"
  rmdir -- "$private_dir" || true
}
trap cleanup EXIT
test -n "$AIVEN_CA_PEM"
printf '%s\n' "$AIVEN_CA_PEM" > "$private_dir/ca.pem"
container_uid="$(docker run --rm --network none --entrypoint id pwdwork-email-incremental:reviewed -u www-data)"
[[ "$container_uid" =~ ^[0-9]+$ ]]
chmod 600 "$private_dir/ca.pem"
sudo chown "$container_uid" "$private_dir/ca.pem"
unset AIVEN_CA_PEM
# Read-only source mount, no .env, no deployment entrypoint, no host credential files.
# The only uploaded artifact is the helper's fixed-schema status/receipt, never a dump.
mkdir -p incremental-evidence
docker run --rm --name "$container_name" --user www-data --workdir /var/www/backend \
  --cap-drop ALL --security-opt no-new-privileges \
  --mount "type=bind,src=$private_dir/ca.pem,dst=/run/aiven-ca.pem,readonly" \
  --mount "type=bind,src=$GITHUB_WORKSPACE/.github,dst=/run/ev-source/.github,readonly" \
  --mount "type=bind,src=$GITHUB_WORKSPACE/backend/database/migrations,dst=/run/ev-source/backend/database/migrations,readonly" \
  --env DB_HOST --env DB_PORT --env DB_DATABASE --env DB_USERNAME --env DB_PASSWORD --env APP_KEY \
  --env EXPECTED_DB_HOST --env EXPECTED_DB_PORT --env EXPECTED_SERVER_UUID \
  --env OPERATION --env CONFIRMATION --env REVIEWED_SHA --env PREVIEW_SHA256 --env BACKUP_SHA256 \
  --env EV_APPROVED_BACKUP --env EV_APPROVED_PREVIEW --env EV_APPROVAL_VERIFIED=true \
  --env GITHUB_ACTIONS --env RUNNER_OS --env GITHUB_REPOSITORY --env GITHUB_REF \
  --env GITHUB_EVENT_NAME --env GITHUB_RUN_ATTEMPT --env GITHUB_RUN_ID --env GITHUB_SHA \
  --env EMAIL_VERIFICATION_ENABLED=false --env APP_ENV=production --env APP_DEBUG=false \
  --env 'LOG_CHANNEL="null"' --env DB_CONNECTION=mysql --env DB_URL= --env DB_SOCKET= \
  --entrypoint php pwdwork-email-incremental:reviewed \
  /run/ev-source/.github/scripts/email-verification-incremental.php \
  > incremental-evidence/receipt.json
python3 - <<'PY'
import hashlib
from pathlib import Path
raw = Path('incremental-evidence/receipt.json').read_bytes().rstrip(b'\n')
print('PASS: guarded operation; receipt SHA256=' + hashlib.sha256(raw).hexdigest())
PY
