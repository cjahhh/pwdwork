#!/usr/bin/env bash
# Separate runner test. No production mode, environment, secrets or network route.
set -Eeuo pipefail
set +x
umask 077
[[ "${1:-}" == --disposable-only && "$#" == 1 ]] || { echo 'STOP: --disposable-only required'; exit 1; }
for tool in docker php python3 openssl; do
  command -v "$tool" >/dev/null || { echo "STOP: missing $tool; no database test ran"; exit 1; }
done
[[ "$(uname -s)" == Linux ]] || { echo 'STOP: use a Linux Docker host'; exit 1; }
repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"
task_temp="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
work="$(mktemp -d "$task_temp/pwdwork-incremental-lab.XXXXXXXX")"
run_label="$(basename "$work")"
db_name="$run_label-mysql"
app_name="$run_label-app"
evidence="$task_temp/$run_label-evidence"
mkdir "$evidence"
stage=preparation
cleanup() {
  local status="$?" owner resolved
  trap - EXIT
  set +e
  for container in "$app_name" "$db_name"; do
    owner="$(docker inspect --format '{{index .Config.Labels "pwdwork.rehearsal"}}' "$container" 2>/dev/null)"
    if [[ "$owner" == "$run_label" ]]; then docker rm --force --volumes "$container" >/dev/null 2>&1; fi
  done
  python3 - "$evidence" "$stage" "$status" <<'PY'
import json, pathlib, sys
directory, stage, code = sys.argv[1:]
root = pathlib.Path(directory)
checks = [json.loads(p.read_text()) for p in sorted(root.glob('case-*.json'))]
passed = code == '0' and stage == 'complete' and len(checks) == 32 and all(c['status'] == 'PASS' for c in checks)
report = dict(status='PASS' if passed else 'FAIL', phase=stage, checks=checks,
              synthetic_only=True, production_access=False, production_approval=False,
              github_approval_api='mocked offline; live environment not tested')
(root/'report.json').write_text(json.dumps(report, indent=2)+'\n')
print(report['status'] + ': disposable incremental runner; evidence=' + str(root/'report.json'))
sys.exit(0 if passed else 1)
PY
  if [[ "$?" != 0 ]]; then status=1; fi
  resolved="$(realpath "$work")"
  if [[ "$(dirname "$resolved")" == "$(realpath "$task_temp")" && "$(basename "$resolved")" == pwdwork-incremental-lab.* ]]; then
    rm -rf -- "$resolved"
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for container in "$db_name" "$app_name"; do
  if docker container inspect "$container" >/dev/null 2>&1; then exit 1; fi
done
mkdir -p "$work/secrets" "$work/tls" "$work/source/.github/scripts" "$work/source/.github/sql" "$work/source/backend/database/migrations"
cp .github/scripts/email-verification-{incremental,rehearsal}.php "$work/source/.github/scripts/"
cp .github/scripts/email-verification-rehearsal-manifest.json "$work/source/.github/scripts/"
cp .github/EMAIL-VERIFICATION-INCREMENTAL-MIGRATION.md "$work/source/.github/"
cp .github/sql/email-verification-*.sql "$work/source/.github/sql/"
cp backend/database/migrations/*.php "$work/source/backend/database/migrations/"
chmod -R a+rX "$work/source"
# Download/build before generating any synthetic credentials. Existing allowlist
# .dockerignore excludes .env, keys, dumps and credentials from the build context.
stage=images
docker pull docker.io/library/mysql:8.4.11 > "$work/pull.log" 2>&1
mysql_id="$(docker image inspect --format '{{.Id}}' docker.io/library/mysql:8.4.11)"
mysql_digest="$(docker image inspect --format '{{index .RepoDigests 0}}' docker.io/library/mysql:8.4.11)"
docker build --target vendor --tag "pwdwork-incremental-lab:$run_label" . > "$work/build.log" 2>&1
app_id="$(docker image inspect --format '{{.Id}}' "pwdwork-incremental-lab:$run_label")"
python3 - "$evidence/images.json" "$mysql_digest" "$app_id" <<'PY'
import json, pathlib, re, sys
path, mysql, app = sys.argv[1:]
assert re.fullmatch(r'(?:docker.io/library/)?mysql@sha256:[a-f0-9]{64}', mysql)
assert re.fullmatch(r'sha256:[a-f0-9]{64}', app)
pathlib.Path(path).write_text(json.dumps(dict(mysql=mysql, application=app))+'\n')
PY
stage=synthetic_credentials_and_tls
openssl rand -hex 32 > "$work/secrets/root-password"
openssl rand -hex 32 > "$work/secrets/user-password"
printf 'base64:' > "$work/secrets/app-key"
openssl rand -base64 32 >> "$work/secrets/app-key"
{
  printf '[client]\nuser=root\npassword='
  cat "$work/secrets/root-password"
  printf 'protocol=SOCKET\ndefault-character-set=utf8mb4\n'
} > "$work/secrets/root-client.cnf"
{
  printf '[client]\nuser=pwdwork_rehearsal\npassword='
  cat "$work/secrets/user-password"
  printf 'protocol=TCP\nhost=127.0.0.1\nport=3306\n'
} > "$work/secrets/user-client.cnf"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=Disposable-CA \
  -addext basicConstraints=critical,CA:TRUE -keyout "$work/tls/ca-key.pem" -out "$work/tls/ca.pem" > "$work/tls.log" 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=incremental-rehearsal.aivencloud.com \
  -keyout "$work/tls/server-key.pem" -out "$work/server.csr" >> "$work/tls.log" 2>&1
printf 'subjectAltName=DNS:incremental-rehearsal.aivencloud.com\nextendedKeyUsage=serverAuth\n' > "$work/extensions.cnf"
openssl x509 -req -days 1 -in "$work/server.csr" -CA "$work/tls/ca.pem" -CAkey "$work/tls/ca-key.pem" \
  -CAcreateserial -extfile "$work/extensions.cnf" -out "$work/tls/server.pem" >> "$work/tls.log" 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=Untrusted-Disposable-CA \
  -keyout "$work/wrong-ca-key.pem" -out "$work/wrong-ca.pem" >> "$work/tls.log" 2>&1
# Only a disposable server key is readable inside its exact mounted directory.
chmod 755 "$work/tls"
chmod 644 "$work/tls/ca.pem" "$work/tls/server.pem" "$work/tls/server-key.pem" "$work/wrong-ca.pem"
printf '127.0.0.1 localhost incremental-rehearsal.aivencloud.com wrong-rehearsal.aivencloud.com\n' > "$work/hosts"
chmod 644 "$work/hosts"
stage=isolated_mysql
docker create --name "$db_name" --label "pwdwork.rehearsal=$run_label" --network none \
  --mount "type=bind,src=$work/secrets,dst=/run/ev-secrets,readonly" \
  --mount "type=bind,src=$work/tls,dst=/run/lab-tls,readonly" \
  --env MYSQL_ROOT_PASSWORD_FILE=/run/ev-secrets/root-password --env MYSQL_ROOT_HOST=localhost \
  --env MYSQL_DATABASE=pwdwork_ev_rehearsal --env MYSQL_USER=pwdwork_rehearsal \
  --env MYSQL_PASSWORD_FILE=/run/ev-secrets/user-password "$mysql_id" \
  --ssl-ca=/run/lab-tls/ca.pem --ssl-cert=/run/lab-tls/server.pem --ssl-key=/run/lab-tls/server-key.pem \
  --event-scheduler=OFF > "$work/create.log" 2>&1
docker start "$db_name" > "$work/start.log" 2>&1
# Docker canonicalizes container:<name> to container:<full-ID> during creation.
# Use the immutable ID for both the requested namespace and its inspection.
db_id="$(docker inspect --format '{{.Id}}' "$db_name")"
[[ "$db_id" =~ ^[a-f0-9]{64}$ ]]
sql() {
  # Check the owned, isolated destination again before EVERY reset/import/query.
  test "$(docker inspect --format '{{index .Config.Labels "pwdwork.rehearsal"}}' "$db_name")" = "$run_label"
  test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$db_name")" = none
  test -z "$(docker port "$db_name")"
  docker exec --interactive "$db_name" mysql --defaults-extra-file=/run/ev-secrets/root-client.cnf \
    --default-character-set=utf8mb4 --init-command="SET SESSION time_zone='+00:00'" --batch --skip-column-names "$@"
}
ready=false
for attempt in $(seq 1 60); do
  if docker exec "$db_name" mysql --defaults-extra-file=/run/ev-secrets/user-client.cnf \
    --database=pwdwork_ev_rehearsal --execute='SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 2
done
test "$ready" = true
test "$(printf 'SELECT VERSION();\n' | sql)" = 8.4.11
printf "GRANT SELECT ON performance_schema.metadata_locks TO 'pwdwork_rehearsal'@'%%';\n" | sql > "$work/grants.log" 2>&1
app() {
  local entry="$1"; shift
  docker create --interactive --name "$app_name" --label "pwdwork.rehearsal=$run_label" \
    --user 0:0 --read-only --tmpfs /tmp:rw,nosuid,nodev,size=128m \
    --cap-drop ALL --security-opt no-new-privileges --network "container:$db_id" \
    --env EV_DISPOSABLE_CONTAINER=true --env EV_NETWORK_MODE=none \
    --mount "type=bind,src=$work/hosts,dst=/etc/hosts,readonly" \
    --mount "type=bind,src=$source_dir,dst=/run/ev-source,readonly" \
    --mount "type=bind,src=$repo_root/.github/scripts/email-verification-rehearsal.php,dst=/run/ev-tools/helper.php,readonly" \
    --mount "type=bind,src=$repo_root/.github/scripts/email-verification-rehearsal-manifest.json,dst=/run/ev-tools/manifest.json,readonly" \
    --mount "type=bind,src=$repo_root/.github/scripts/email-verification-incremental-rehearsal.php,dst=/run/ev-tools/incremental-test.php,readonly" \
    --mount "type=bind,src=$ca_file,dst=/run/aiven-ca.pem,readonly" \
    --mount "type=bind,src=$work/dump.sha256,dst=/run/ev-tools/dump.sha256,readonly" \
    "${extra_mount[@]}" --entrypoint php "$app_id" "$entry" "$@" > "$work/app-create.log" 2>&1
  test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$app_name")" = "container:$db_id"
  local result=0
  cat "$work/secrets/user-password" "$work/secrets/app-key" |
    docker start --attach --interactive "$app_name" > "$work/result.json" 2> "$work/app-error.log" || result="$?"
  local code
  code="$(docker inspect --format '{{.State.ExitCode}}' "$app_name")"
  docker rm "$app_name" >/dev/null
  test "$result" = 0 && test "$code" = 0
}
source_dir="$work/source"
ca_file="$work/tls/ca.pem"
extra_mount=()
printf 'not-yet-created\n' > "$work/dump.sha256"
chmod 644 "$work/dump.sha256"
stage=existing_baseline_and_fixture
app /run/ev-tools/helper.php init pwdwork_ev_rehearsal
stage=synthetic_backup
docker exec "$db_name" mysqldump --defaults-extra-file=/run/ev-secrets/root-client.cnf \
  --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces --hex-blob \
  --routines --events --triggers pwdwork_ev_rehearsal > "$work/before.sql" 2> "$work/dump-error.log"
test -s "$work/before.sql"
python3 - "$work/before.sql" "$work/dump.sha256" <<'PY'
import hashlib, pathlib, re, sys
data = pathlib.Path(sys.argv[1]).read_bytes()
assert not re.search(rb'(?im)^\s*(?:USE|CREATE DATABASE)\b', data)
pathlib.Path(sys.argv[2]).write_text(hashlib.sha256(data).hexdigest()+'\n')
PY
# These names exist ONLY on this newly created network-none MySQL. Production
# spelling exercises the runner byte-for-byte; it provides no production route.
cases=(preview_readonly apply_success preview_unapproved apply_unapproved wrong_database wrong_host wrong_port wrong_uuid
  wrong_ca wrong_tls_hostname changed_migration extra_migration changed_image_migration partial_one partial_two unrecorded
  already_applied history_missing history_unknown history_duplicate backup_missing backup_hash backup_expired
  preview_missing preview_hash preview_expired preview_wrong_sha preview_wrong_schema schema_drift advisory_lock enrollment_enabled rerun)
for scenario in "${cases[@]}"; do
  stage="$scenario"
  printf "DROP DATABASE IF EXISTS pwdwork_prod; CREATE DATABASE pwdwork_prod CHARACTER SET utf8mb4; GRANT ALL ON pwdwork_prod.* TO 'pwdwork_rehearsal'@'%%';\n" | sql > "$work/reset.log" 2>&1
  sql pwdwork_prod < "$work/before.sql" > "$work/restore.log" 2>&1
  source_dir="$work/source"; ca_file="$work/tls/ca.pem"; extra_mount=()
  if [[ "$scenario" == changed_migration || "$scenario" == extra_migration ]]; then
    source_dir="$work/tampered-$scenario"
    cp -R "$work/source" "$source_dir"
    if [[ "$scenario" == changed_migration ]]; then
      printf '\n// disposable tamper\n' >> "$source_dir/backend/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php"
    else
      printf '<?php // disposable extra file\n' > "$source_dir/backend/database/migrations/2099_01_01_000001_unreviewed.php"
    fi
    chmod -R a+rX "$source_dir"
  fi
  if [[ "$scenario" == changed_image_migration ]]; then
    cp "$work/source/backend/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php" "$work/wrong-image.php"
    printf '\n// disposable image tamper\n' >> "$work/wrong-image.php"
    chmod 644 "$work/wrong-image.php"
    extra_mount=(--mount "type=bind,src=$work/wrong-image.php,dst=/var/www/backend/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php,readonly")
  fi
  if [[ "$scenario" == wrong_ca ]]; then ca_file="$work/wrong-ca.pem"; fi
  app /run/ev-tools/incremental-test.php "$scenario"
  python3 - "$work/result.json" "$evidence/case-$scenario.json" "$scenario" <<'PY'
import json, pathlib, sys
result = json.loads(pathlib.Path(sys.argv[1]).read_text())
assert result == dict(status='PASS', case=sys.argv[3], synthetic_only=True)
pathlib.Path(sys.argv[2]).write_text(json.dumps(result)+'\n')
PY
  echo "PASS: $scenario"
done
stage=complete
