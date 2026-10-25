#!/bin/sh
# Run as root inside the built image, with networking disabled. Synthetic data
# only: no real certificates, application bootstrap, credentials or database.
set -eu
umask 077

case " $(id -G www-data) " in
    *" 1000 "*) ;;
    *) echo 'FAIL: www-data is missing supplementary GID 1000.' >&2; exit 1 ;;
esac

fixture_dir="$(mktemp -d /tmp/pwdwork-secret-access.XXXXXXXX)"
cleanup() {
    rm -f -- "$fixture_dir/ca.pem" "$fixture_dir/output"
    rmdir -- "$fixture_dir"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

# Model a root-owned, group-readable Render secret without granting world access.
chgrp 1000 "$fixture_dir"
chmod 750 "$fixture_dir"
printf '%s\n' 'synthetic-permission-fixture-not-a-certificate' > "$fixture_dir/ca.pem"
chgrp 1000 "$fixture_dir/ca.pem"
chmod 640 "$fixture_dir/ca.pem"

check_as_worker() {
    runuser -u www-data -- env MYSQL_ATTR_SSL_CA="$1" \
        php /opt/pwdwork/check-environment.php --ca-readable
}

check_as_worker "$fixture_dir/ca.pem"
echo 'PASS: www-data can open the root-owned GID-1000 file with mode 0640.'

expect_denied() {
    if check_as_worker "$1" > "$fixture_dir/output" 2>&1; then
        echo 'FAIL: application-user check accepted an inaccessible file.' >&2
        exit 1
    fi
    expected='Container configuration: Aiven CA cannot be opened by the application user. Check Secret File path and group access.'
    if [ "$(cat "$fixture_dir/output")" != "$expected" ]; then
        echo 'FAIL: permission check did not produce the fixed safe diagnostic.' >&2
        exit 1
    fi
}

chmod 600 "$fixture_dir/ca.pem"
expect_denied "$fixture_dir/ca.pem"
echo 'PASS: root-only file is rejected without exposing contents or path.'

chmod 640 "$fixture_dir/ca.pem"
chmod 700 "$fixture_dir"
expect_denied "$fixture_dir/ca.pem"
echo 'PASS: inaccessible parent directory is rejected.'

chmod 750 "$fixture_dir"
expect_denied "$fixture_dir/missing.pem"
expect_denied ''
echo 'PASS: missing and empty CA paths are rejected.'
