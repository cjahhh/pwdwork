# Optional Backblaze B2 storage connectivity test

Implemented on 26 October 2026. The test is disabled by default. Production storage, credentials, databases, and user data were not accessed. No migrations, commit, push, or deployment were performed. The existing Nginx template edit was preserved.

The required `league/flysystem-aws-s3-v3` adapter is already a production Composer dependency and is present in the installed dependencies. No package installation or credential change is needed. The automated tests also exercise the real Laravel/Flysystem/AWS SDK stack with a terminal mock handler that prevents network access.

The command is `php artisan pwdwork:storage-test --no-ansi --no-interaction`. It refuses to access storage unless the temporary `PWDWORK_STORAGE_TEST=true` flag is enabled, `RESUME_DISK=s3`, debug is disabled, and the selected S3 configuration has private visibility, a valid HTTPS endpoint and the required connection fields. It uses `Storage::disk(config('filesystems.resume_disk'))` with the existing bucket, region, endpoint, credentials, addressing mode and configured root. These values are never printed.

Each run generates a cryptographically random 48-character filename under `_pwdwork-storage-test/` and checks only that exact key before writing. It uploads a tiny fictional text file with private visibility, verifies private visibility, reads back and compares the contents, and removes it in `finally`, even after an ambiguous upload timeout or read failure. File contents, generated names, provider messages, response bodies, private URLs and credentials are never printed or logged by the probe.

It uses `fileExists()` rather than Laravel's general `exists()`: the latter may check directories and issue a listing when the object is absent. No listing, directory operation, public URL generation, resume lookup, user lookup, or database operation is part of the test. A collision or an inconclusive preflight aborts before reading, writing or deleting that key. Cleanup is limited to the fresh key whose initial absence was confirmed.

B2-specific cleanup matters: an S3 delete by name inserts a delete marker and leaves historical file data. For this newly created key, cleanup retrieves its version ID using the S3 client already attached to the configured Laravel disk, then deletes precisely that key/version and verifies absence with an exact-key check. It never enumerates versions. Missing B2 version metadata causes cleanup failure rather than reporting a hidden historical file as removed. Other unversioned S3-compatible stores can use normal disk deletion. [Backblaze delete semantics](https://www.backblaze.com/apidocs/s3-delete-object).

The visibility check reads the ACL of only the new object. B2 reports bucket-level visibility through its object ACL API, so this also detects a public bucket without changing any ACL. [Backblaze ACL behavior](https://www.backblaze.com/docs/cloud-storage-s3-compatible-api#access-control-lists).

The probe disables SDK debugging/reporting and automatic retries, preserves TLS verification, and applies 5-second connection/10-second request timeouts only within its separate CLI process. Normal application disk behavior and configuration files are unchanged apart from the disabled opt-in setting.

To run later on Render Free, after reviewing and releasing these source changes through your normal workflow:

1. Keep `APP_DEBUG=false`, `RESUME_DISK=s3`, and all existing AWS/B2 credentials, bucket, endpoint and security settings unchanged. The current application key must be authorized for the dedicated test prefix as well as the upload/read/ACL/exact-version-delete operations; do not broaden permissions or alter credentials speculatively to bypass a failed test.
2. In Render Dashboard, open the PWDWork web service → **Environment** → **Add Environment Variable**. Add key `PWDWORK_STORAGE_TEST` with the exact lowercase value `true`.
3. Select **Save, rebuild, and deploy** when the reviewed source containing this test is available to the service. If that build is already deployed, **Save and deploy** can reuse it. Saving alone does not activate the setting until the next deployment. This is a future operator action; no deployment was performed during implementation. [Render environment-variable controls](https://render.com/docs/configure-environment-variables).
4. Watch the service's runtime logs for `storage-test.` lines. The existing entrypoint runs the optional test **in the background as `www-data`**, after normal startup validation and configuration caching. The service starts without waiting for the probe; a probe failure does not change production startup's success/failure requirements. No interactive shell or local Docker is required.
5. Successful output is exactly the following stage sequence, alongside the application's usual startup logs:

   ```text
   PASS storage-test.enabled
   PASS storage-test.configuration
   PASS storage-test.adapter
   PASS storage-test.disk
   PASS storage-test.object
   PASS storage-test.upload
   PASS storage-test.visibility
   PASS storage-test.read
   PASS storage-test.cleanup
   PASS storage-test.result
   ```

6. After `PASS storage-test.result`, remove `PWDWORK_STORAGE_TEST` from Render Environment, or set it to `false`, and select **Save and deploy**. Confirm the next startup has no test-stage lines. No other setting needs removal or adjustment.

The startup runner creates an empty local attempt marker at `/tmp/pwdwork-storage-test.attempted` before it boots the probe. It attempts once per container, including after failure. It does not use the database or remote storage to track attempts. A replacement container has a fresh marker, so leaving the flag enabled across deployments/replacements can run another test. Removing the flag after verification is what keeps this an operational one-time test; there is no claim of global exactly-once execution across container replacements.

The background runner discards incidental bootstrap stdout, suppresses raw stderr, and forwards only exact allowlisted PASS/FAIL stage lines. Unexpected startup failures produce `FAIL storage-test.startup`. Do not enable debug, print environment values, add a public test endpoint, or turn on SDK request/response dumps to investigate a failure.

| Failure stage | Safe interpretation |
| --- | --- |
| `enabled` | Temporary opt-in is absent or not exactly enabled in Laravel configuration. |
| `configuration` | Debug, disk selection, private visibility, endpoint or required configuration check failed; no disk operation was attempted. |
| `adapter` | Required S3 classes are unavailable; rebuild from the existing production Composer dependencies. |
| `disk` | The selected adapter could not be constructed. |
| `object` | Exact-key preflight failed or found a collision; no write, read or delete was attempted. |
| `upload` | Fictional upload failed; `finally` still attempts cleanup if a write might have reached the provider. |
| `visibility` | Private visibility could not be confirmed; cleanup still runs. |
| `read` | Read failed or content did not match; cleanup still runs. |
| `cleanup` | Removal could not be confirmed. The overall result must be FAIL. |
| `startup` | Runner/bootstrap/marker failed; private details remain suppressed. |

Cleanup is best effort under provider failure or abrupt process termination; no `finally` block can guarantee cleanup after a hard kill. If cleanup fails, at most the new tiny fictional test object may remain. An authorized operator can inspect only the dedicated test prefix privately in B2 to resolve that object; this probe never scans the bucket, deletes a directory/prefix, or attempts to clean up earlier objects. Do not treat upload/read PASS alone as successful verification.

Changed files:

- `backend/app/Console/Commands/StorageConnectivityTest.php`: opt-in command, private fictional round trip and exact-key/version cleanup.
- `backend/app/Support/StorageConnectivityStartup.php`: per-container attempt marker, buffered output and fixed stage filtering.
- `backend/config/filesystems.php`: disabled-by-default `PWDWORK_STORAGE_TEST` configuration.
- `deploy/docker/storage-connectivity-test.php`: isolated startup CLI runner using Laravel's cached configuration.
- `deploy/docker/entrypoint.sh`: optional background invocation as the application user.
- `Dockerfile`: include the CLI runner in the runtime image.
- `.dockerignore`: permit that runner in the otherwise restricted build context.
- `backend/tests/Feature/StorageConnectivityTest.php`: mocked success/failure, privacy, collisions, cleanup and configuration restrictions.
- `backend/tests/Feature/StorageConnectivityAdapterTest.php`: actual adapter/SDK integration with a terminal mock handler; proves exact-key calls, private ACL and version deletion without network.
- `backend/tests/Unit/StorageConnectivityStartupTest.php`: disabled behavior, atomic attempt guard, output filtering, exception safety and runner behavior.
- `.github/B2-STORAGE-TEST.md`: these operator instructions and verification record.

Verification: all **211 backend tests passed with 2,616 assertions**, including 33 new storage/startup cases. PHP syntax checks passed for all seven changed/added PHP files; Pint checks, Bash entrypoint syntax, and `git diff --check` passed. All storage operations were mocked; no real cloud requests or existing user files were accessed. Tests used the existing guarded SQLite `:memory:` configuration. Docker is not installed locally, so the container image and actual Render/B2 connection remain unverified until the operator enables this test on Render. Frontend source and features were unchanged.
