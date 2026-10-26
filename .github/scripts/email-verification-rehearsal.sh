#!/usr/bin/env bash
# GitHub-hosted Ubuntu only. Builds a disposable laboratory; never a production runner.
set -Eeuo pipefail
set +x
umask 077

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"
php .github/scripts/email-verification-rehearsal.php --authorize-only
test "$(git rev-parse HEAD)" = "$GITHUB_SHA"
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ && "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]
test -d "$RUNNER_TEMP"
work="$(mktemp -d "$RUNNER_TEMP/pwdwork-ev-private.XXXXXXXX")"
evidence="$RUNNER_TEMP/pwdwork-ev-evidence"
mkdir "$evidence"
stage=preparation
run_label="$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
db_name="pwdwork-ev-$run_label"
app_name="$db_name-app"
mysql_image=docker.io/library/mysql:8.4.11
app_image="pwdwork-ev-app:$run_label"
cleanup() {
  local status="$?"
  trap - EXIT
  set +e
  # Only exact containers carrying this run's ownership label may be removed.
  for container in "$app_name" "$db_name"; do
    owner="$(docker inspect --format '{{index .Config.Labels "pwdwork.rehearsal"}}' "$container" 2>/dev/null)"
    if [[ "$owner" == "$run_label" ]]; then docker rm --force --volumes "$container" >/dev/null 2>&1; fi
  done
  python3 - "$evidence" "$stage" "$status" "$GITHUB_SHA" <<'PY'
import json, pathlib, sys
directory, stage, status, sha = sys.argv[1:]
root = pathlib.Path(directory)
checks = {p.stem: json.loads(p.read_text()) for p in sorted(root.glob('*.json')) if p.name != 'report.json'}
overall = 'PASS' if status == '0' and stage == 'complete' and all(c['status'] == 'PASS' for c in checks.values()) else 'FAIL'
report = dict(status=overall, phase=stage, commit=sha, checks=checks,
              production_access=False, synthetic_only=True, manual_partial_reconciliation=True)
(root/'report.json').write_text(json.dumps(report, indent=2)+'\n')
lines = ['# Disposable MySQL email verification rehearsal', '', f'**{overall}**; last phase: `{stage}`.',
         f'Commit: `{sha}`.', '', 'Synthetic data only. No production access or rollout approval.',
         'Expected partial failures require manual forward repair or ledger reconciliation.', '',
         '| Check | Result |', '| --- | --- |']
lines += [f'| {name} | {check["status"]} |' for name, check in checks.items()]
(root/'summary.md').write_text('\n'.join(lines)+'\n')
print(f'{overall}: {stage}')
PY
  if [[ "$?" -ne 0 ]]; then status=1; fi
  # Private dumps, generated credentials and raw tool errors never become artifacts.
  resolved="$(realpath "$work")"
  if [[ "$(dirname "$resolved")" == "$(realpath "$RUNNER_TEMP")" && "$(basename "$resolved")" == pwdwork-ev-private.* ]]; then
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
mkdir -p "$work/secrets" "$work/dumps" "$work/source/backend/database/migrations" "$work/source/.github/sql"
cp backend/database/migrations/*.php "$work/source/backend/database/migrations/"
cp .github/sql/email-verification-*.sql "$work/source/.github/sql/"
# Only public reviewed source goes into this mount; the parent stays private.
chmod -R a+rX "$work/source"

# No credentials or database exist during the public dependency download/build phase.
stage=official_mysql_pull
docker pull "$mysql_image" > "$work/pull.log" 2>&1
mysql_id="$(docker image inspect --format '{{.Id}}' "$mysql_image")"
mysql_digest="$(docker image inspect --format '{{index .RepoDigests 0}}' "$mysql_image")"
stage=application_build
# The existing vendor target skips the unrelated frontend stage and contains no .env.
docker build --target vendor --tag "$app_image" . > "$work/build.log" 2>&1
app_id="$(docker image inspect --format '{{.Id}}' "$app_image")"
python3 - "$evidence/images.json" "$mysql_digest" "$app_id" <<'PY'
import json, pathlib, re, sys
path, mysql, app = sys.argv[1:]
assert re.fullmatch(r'(?:docker.io/library/)?mysql@sha256:[a-f0-9]{64}', mysql)
assert re.fullmatch(r'sha256:[a-f0-9]{64}', app)
pathlib.Path(path).write_text(json.dumps(dict(status='PASS', official_mysql=mysql, application_image=app))+'\n')
PY

stage=temporary_credentials
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

stage=isolated_mysql_startup
docker create --name "$db_name" --label "pwdwork.rehearsal=$run_label" --network none \
  --mount "type=bind,src=$work/secrets,dst=/run/ev-secrets,readonly" \
  --env MYSQL_ROOT_PASSWORD_FILE=/run/ev-secrets/root-password \
  --env MYSQL_ROOT_HOST=localhost \
  --env MYSQL_DATABASE=pwdwork_ev_rehearsal --env MYSQL_USER=pwdwork_rehearsal \
  --env MYSQL_PASSWORD_FILE=/run/ev-secrets/user-password \
  "$mysql_id" > "$work/create.log" 2>&1
docker start "$db_name" > "$work/start.log" 2>&1
test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$db_name")" = none
test -z "$(docker port "$db_name")"
sql() {
  docker exec --interactive "$db_name" mysql --defaults-extra-file=/run/ev-secrets/root-client.cnf \
    --default-character-set=utf8mb4 --init-command="SET SESSION time_zone='+00:00'" --batch --skip-column-names "$@"
}
ready=false
for attempt in $(seq 1 60); do
  # The entrypoint's temporary initialization server has no TCP listener.
  # Require the final server and initialized scoped account, not just its socket.
  if docker exec "$db_name" mysql --defaults-extra-file=/run/ev-secrets/user-client.cnf \
    --database=pwdwork_ev_rehearsal --execute='SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 2
done
test "$ready" = true
test "$(printf 'SELECT VERSION();\n' | sql)" = 8.4.11
printf "GRANT SELECT ON performance_schema.metadata_locks TO 'pwdwork_rehearsal'@'%%';\n" |
  sql > "$work/metadata-grant.log" 2>&1

helper() {
  local mode="$1" database="$2" label="$3"
  stage="$label"
  # No secret environment is forwarded. The two generated values are stdin only.
  docker create --interactive --name "$app_name" --label "pwdwork.rehearsal=$run_label" \
    --user 0:0 --read-only --tmpfs /tmp:rw,nosuid,nodev,size=128m \
    --cap-drop ALL --security-opt no-new-privileges --network "container:$db_name" \
    --env EV_DISPOSABLE_CONTAINER=true --env EV_NETWORK_MODE=none \
    --mount "type=bind,src=$repo_root/.github/scripts/email-verification-rehearsal.php,dst=/run/ev-tools/helper.php,readonly" \
    --mount "type=bind,src=$repo_root/.github/scripts/email-verification-rehearsal-manifest.json,dst=/run/ev-tools/manifest.json,readonly" \
    --mount "type=bind,src=$work/source,dst=/run/ev-source,readonly" \
    --entrypoint php "$app_id" /run/ev-tools/helper.php "$mode" "$database" > "$work/app-create.log" 2>&1
  local result=0
  cat "$work/secrets/user-password" "$work/secrets/app-key" |
    docker start --attach --interactive "$app_name" > "$work/result.json" 2> "$work/app-error.log" || result="$?"
  local container_exit
  container_exit="$(docker inspect --format '{{.State.ExitCode}}' "$app_name")"
  # Reject raw/fatal output. Only parsed, expected JSON can leave private storage.
  python3 - "$work/result.json" "$evidence/$label.json" "$mode" <<'PY'
import json, pathlib, sys
source, target, phase = sys.argv[1:]
try:
    result = json.loads(pathlib.Path(source).read_text())
    assert result['status'] in ('PASS', 'FAIL') and result['phase'] == phase
    assert set(result) <= {'status','phase','checks','tables','state','mysql_code','category','manual_reconciliation_required'}
    pathlib.Path(target).write_text(json.dumps(result, indent=2)+'\n')
except Exception:
    print('FAIL: invalid helper output; raw output withheld', file=sys.stderr)
    sys.exit(1)
PY
  docker rm "$app_name" > /dev/null
  test "$result" -eq 0
  test "$container_exit" -eq 0
  echo "PASS: $label"
}
compare() {
  local label="$1" left="$2" right="$3"
  stage="$label"
  python3 - "$evidence" "$label" "$left" "$right" <<'PY'
import json, pathlib, sys
directory, label, left, right = sys.argv[1:]
root = pathlib.Path(directory)
before = json.loads((root/(left+'.json')).read_text())['tables']
after = json.loads((root/(right+'.json')).read_text())['tables']
passed = before == after
(root/(label+'.json')).write_text(json.dumps(dict(status='PASS' if passed else 'FAIL',
    original_columns_and_all_tables_unchanged=passed))+'\n')
sys.exit(0 if passed else 1)
PY
  echo "PASS: $label"
}

helper init pwdwork_ev_rehearsal baseline_schema_and_synthetic_data
helper preflight pwdwork_ev_rehearsal preflight
helper snapshot pwdwork_ev_rehearsal before

stage=backup
docker exec "$db_name" mysqldump --defaults-extra-file=/run/ev-secrets/root-client.cnf \
  --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces --hex-blob \
  --routines --events --triggers pwdwork_ev_rehearsal > "$work/dumps/before.sql" 2> "$work/dump-error.log"
test -s "$work/dumps/before.sql"
python3 - "$work/dumps/before.sql" "$evidence/backup.json" <<'PY'
import hashlib, json, pathlib, re, sys
source, target = map(pathlib.Path, sys.argv[1:])
data = source.read_bytes()
assert not re.search(rb'(?im)^\s*(?:USE|CREATE DATABASE)\b', data)
target.write_text(json.dumps(dict(status='PASS', backup_sha256=hashlib.sha256(data).hexdigest(), bytes=len(data)))+'\n')
PY

for database in pwdwork_ev_restored pwdwork_ev_partial_one pwdwork_ev_partial_two pwdwork_ev_unrecorded pwdwork_ev_locked; do
  stage="restore_$database"
  printf "CREATE DATABASE \`%s\` CHARACTER SET utf8mb4; GRANT ALL ON \`%s\`.* TO 'pwdwork_rehearsal'@'%%';\n" "$database" "$database" |
    sql > "$work/restore-create.log" 2>&1
  sql "$database" < "$work/dumps/before.sql" > "$work/restore.log" 2>&1
done
helper snapshot pwdwork_ev_restored restored
compare restored_schema_and_all_data_match before restored

helper apply pwdwork_ev_rehearsal targeted_migration
helper postflight pwdwork_ev_rehearsal postflight
helper snapshot pwdwork_ev_rehearsal after
compare original_users_passwords_roles_sessions_and_data_match before after
helper repeat pwdwork_ev_rehearsal recorded_migration_skips
helper snapshot pwdwork_ev_rehearsal repeated
compare repeat_does_not_change_data after repeated

helper partial-one pwdwork_ev_partial_one interrupted_after_first_alter
helper snapshot pwdwork_ev_partial_one partial_one_after
compare first_alter_failure_preserves_original_data before partial_one_after
helper partial-two pwdwork_ev_partial_two interrupted_after_second_alter
helper snapshot pwdwork_ev_partial_two partial_two_after
compare second_alter_failure_preserves_original_data before partial_two_after
helper unrecorded pwdwork_ev_unrecorded interrupted_before_ledger
helper snapshot pwdwork_ev_unrecorded unrecorded_after
compare lost_ledger_preserves_original_data before unrecorded_after
helper locked pwdwork_ev_locked bounded_metadata_lock_timeout
helper snapshot pwdwork_ev_locked locked_after
compare lock_timeout_preserves_original_schema_and_data before locked_after
stage=complete
