# VISCAR GitHub and Hostinger deployment preparation

Prepared 28 September 2026. **Deployment readiness: BLOCKED pending production verification and owner review.** No Git commit, remote modification, push, production connection, live migration, deployment or credential rotation was performed.

## Blocking items

1. This workspace has no `.git` repository. Branch, remote, index and previous Git history do not exist here and cannot be verified. Supply/confirm the intended GitHub repository and branch, and inspect its existing history before publishing.
2. The real HTTPS domain, Hostinger document root, database schema/version and SSH/PHP CLI availability have not been supplied or inspected. Confirm them; run the migration plan against a backed-up staging copy of production before a live migration.
3. Configure real database, SMTP and SMS settings privately. Rotate the previously hardcoded Gmail/SMTP app password. Its earlier exposure is not undone by moving it into private configuration. Git history is unavailable locally, so exposure in earlier commits cannot be ruled out. No real passwords were changed.
4. Confirm SSL/HTTPS enforcement, directory denial rules and PHP settings on Hostinger. The current local `post_max_size=40M` cannot hold twenty 5 MB files plus multipart overhead. Set `post_max_size=128M`, `upload_max_filesize=64M` (also accommodates the existing 64 MB backup UI), `max_file_uploads=20` or higher, and `memory_limit=512M` where the plan permits. The application still enforces 5 MB per attachment. Large archive restore remains limited to 256 MB expanded and needs a staging test; memory use may exceed the compressed archive size.
5. Verify authorized real email/SMS delivery, cron scheduling and physical-phone QR behavior before declaring live testing successful. Automated tests use synthetic accounts and mock providers only.

## Git and secret audit

- Git status: **not a Git repository**. Branch: **none verified**. GitHub remote: **none configured/verified**. Staged files: **no index exists**.
- `deployment/COMMIT_FILES.txt` lists the candidate source/documentation/test files. `deployment/DEPLOY_FILES.txt` lists the smaller runtime deployment set. Regenerate both with `python tools/prepare_release.py` after final edits. The tool uses an isolated bare Git directory under protected storage only to evaluate ignore rules; it does not initialize or stage this workspace.
- `.gitignore` excludes filled private configs, `.env` files, private keys, logs, sessions, caches, uploads, backup archives/dumps, archived runtime copies, local tools and generated test evidence. Only migration SQL is included; `database/indigo_church.sql` and `system/*.sql` are excluded. Existing runtime data was not deleted.
- Current-tree scan findings are redacted in `deployment/SECRET_SCAN.json`. No recognized real credential pattern remains in the candidate source set. This is a heuristic scan plus manual configuration review, not proof against every secret format or a history scan.
- Previously embedded SMTP credentials now remain only in protected, ignored local configuration. Never copy that local file to Hostinger or GitHub. The database seed/dumps and archived `audit/` material are excluded regardless of whether individual values are synthetic or real.
- `.gitignore` does not untrack already committed files. Run the staged checker and inspect any upstream repository before committing. Rotate any exposed tokens first; history removal, if desired, is a separate explicitly approved operation. No force push is authorized.

## Changes made for deployment

- `includes/config.php`: private production configuration or optional external `VISCAR_CONFIG_FILE`; defaults to production when no local configuration exists; rejects missing production database/HTTPS URL settings; hides errors and logs failures privately; supports an explicit maintenance flag. Existing local configuration remains intact.
- `includes/db.php`: safe database-unavailable response and failing CLI exit status.
- `config.local.example.php`, `config.production.example.php`: placeholder-only configuration templates. Environment variables take precedence. No Composer/framework/environment-file parser dependency was introduced.
- `.gitignore`, root/directory `.htaccess` rules, `deployment/hostinger.htaccess`: protect configs, source/private directories and uploads; production-only HTTPS rules are provided separately so local HTTP development remains usable.
- `tools/migrate_compatibility.php`, `tools/migrate_production.php`, `database/deployment_schema.json`: additive migration orchestrator with database-name confirmation, backup/maintenance prerequisites, lock, preserved status enums, row-count checks and schema checks. It never creates a database, imports seed accounts, truncates tables or deletes existing rows.
- `tools/deployment_preflight.php`: CLI configuration, extensions, permissions, limits, InnoDB, schema and document-presence checks. Its output contains no credential values.
- `tools/prepare_release.py`, `tools/check_staged.py`: candidate manifests and redacted current-tree/index checks.
- `admin/includes/layout.php`: removed a reference to nonexistent `loads/assets/js/admin.js`; existing layout JavaScript remains.
- `parishioner/dashboard.php`, `staff/records.php`: QR verification links use the configured canonical base URL, avoiding proxy/host-header differences.
- `tools/test_transports.py`: mock production-mode tests explicitly supply their isolated database and example HTTPS URL after production configuration was made stricter.

These are deployment/security compatibility changes; completed authentication, password toggle, attachments, role workflows and finance functionality were preserved.

## Hostinger configuration and paths

The web root must contain this project's **root `index.php`**, with sibling `admin`, `staff`, `parishioner`, `public`, `includes`, `assets`, `uploads` and `storage` directories. Do not point the domain at the project's `public/` directory: it is not a framework-style public document root.

Confirm the actual account path in hPanel. `/home/ACCOUNT/domains/DOMAIN/public_html` is only an example, not a discovered path. If deployed to a subdirectory, include that subdirectory in `APP_URL` and deploy the entire project tree there.

Runtime: **PHP 8.2 minimum**, because the code uses `mysqli::execute_query`; use the same version in web and cron/SSH environments. Required extensions: mysqli/mysqlnd, mbstring, fileinfo, GD, OpenSSL, cURL, Zip, JSON and sessions. Required functions include secure random generation, uploaded-file operations and outbound TLS sockets. Timezone remains `Asia/Manila`, and database connections set `+08:00`.

MySQL/MariaDB must support InnoDB and utf8mb4. The exact Hostinger version and production schema remain unverified. The wrapper performs column/index existence checks instead of relying on MariaDB-only `ADD COLUMN IF NOT EXISTS` syntax. Existing enum values are preserved when adding statuses.

Writable directories:

- `storage/private/`: documents, backups, private logs, delivery logs and `sessions/`; use owner-only permissions where PHP runs as the account owner (typically directories 700, files 600).
- `uploads/`: public profile/site/parish images; typical directories 755, files 644. Subdirectories are created by existing upload helpers.
- `uploads/requirements/`: preserved legacy private documents; keep its deny-all `.htaccess` and never expose direct URLs.
- Consider `max_input_time=120` and `max_execution_time=120` for uploads/reports, subject to hosting limits; test large restores rather than assuming a timeout setting guarantees completion. PHP's upload temporary directory must exist and be writable. Do not copy Windows/XAMPP temp paths into production.

Do not use 777. Verify effective permissions under the actual PHP user. Keep private storage and public uploads out of release archives and restore them from the production file backup to the same relative paths. Backups depend on that storage layout; this revision does not move documents outside the root. HTTP denial rules must therefore pass before accepting uploads.

SMTP supports authenticated **STARTTLS on port 587** and implicit TLS on port 465. The template selects 587; use the port required by the mailbox provider. Configure a supported mailbox/verified sender and SPF/DKIM/DMARC as appropriate to that provider. SMS uses the configured HTTPS iProg endpoint/token and requires provider credit. Confirm outbound connectivity on the actual hosting plan.

QR generation uses the bundled local PHP QR library and GD, not an external QR service. Camera scanning requires HTTPS, camera permission and browser support; unsupported browsers retain manual entry. Reports/certificates use printable HTML and browser Save as PDF, not a server-side PDF package.

Official Hostinger references checked during preparation:

- [Git deployment](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/): confirm the selected install path; do not configure a new checkout over the existing populated live site.
- [PHP versions](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/) and [extensions/options](https://www.hostinger.com/support/which-php-extensions-and-configuration-options-are-supported-at-hostinger/): configure the actual website rather than assuming local settings transfer.
- [HTTPS](https://www.hostinger.com/support/1583201-how-to-enable-or-disable-https-for-your-website-at-hostinger/): enable a valid SSL certificate and force HTTPS. Test redirects with the actual proxy/CDN arrangement.
- [Cron setup](https://support.hostinger.com/en/articles/1583465-how-to-set-up-a-cron-job-at-hostinger): verify the PHP binary/path for this account. Availability and limits depend on the hosting plan.

## Exact safe Git procedure (owner-run, after review)

First fill the non-secret repository URL and intended branch; `main` below is a proposed branch, not an observed one. Run from this project in PowerShell:

```powershell
$repoUrl = 'REPLACE_WITH_GITHUB_REPOSITORY_URL'
$branch = 'main'
git status --short --branch
git remote -v
git ls-remote --heads $repoUrl
```

The first two commands currently report no repository. If GitHub already has branches/history, **clone it into a separate directory**, check out the intended branch, and copy only paths from `deployment/COMMIT_FILES.txt` into that checkout. Preserve its `.git` and inspect differences; do not initialize this folder and merge unrelated histories. If the remote is genuinely empty and this folder is the intended initial source:

```powershell
git init -b $branch
git remote -v
# Run only when origin is absent and the URL above is confirmed:
git remote add origin $repoUrl
git remote get-url origin
git branch --show-current
python tools/prepare_release.py
git ls-files -ci --exclude-standard
```

If the last command lists previously tracked private files, stop and remove only reviewed paths from the index with `git rm --cached -- 'EXACT_PATH'` (or `-r --cached` for a reviewed directory). That does not erase history. Do not stage secrets to remove them in a later commit.

```powershell
git add --pathspec-from-file=deployment/COMMIT_FILES.txt
python tools/check_staged.py
git diff --cached --check
git diff --cached --stat
git status --short --branch
git remote -v
# Review the staged diff locally; do not paste credentials into chat.
git diff --cached
# Only after review and all blocking items are resolved:
git commit -m "VISCAR master revision and deployment preparation"
git push -u origin $branch
```

Do not force-push. A rejected/non-fast-forward push means inspect the upstream history; it is not permission to replace it. Keep automatic Hostinger deployment disabled until the deployment procedure below is approved.

## Production migration order

Do not run `tools/setup_local.php`, `tools/revision_fixture.php`, `database/indigo_church.sql` or database-reset scripts on Hostinger. Do not import every SQL file indiscriminately.

The sole production entrypoint is `tools/migrate_production.php`, which executes this order:

1. Existing base-table checks and read-only plan.
2. `migrate_compatibility.php`: missing supporting tables/columns/indexes from earlier migrations; preserve existing status enum values; fill only missing legacy fee/announcement values.
3. `migrate_master.php`, which includes `migrate_separation.php`: accounting/security/subscription additions, canonical services, schema snapshots, cancellation/refund fields and petty-cash sets.
4. `migrate_attachments.php`: one-to-many table and idempotent legacy attachment backfill, without moving files or rewriting legacy maps.
5. Existing row-count and expected-column checks; private migration record with backup SHA-256.

DDL auto-commits. The wrapper is rerunnable but is not a transaction around schema changes. A backup file's presence/hash is not proof of restorability: test restoring the actual production backup into an isolated database before applying live changes. Unknown schema differences may require manual review; the wrapper fails closed when expected columns remain absent.

## Deployment runbook (do not execute until blockers clear)

Use a protected staging hostname/database first. Disable outbound notifications there or use approved test recipients so restored real data cannot trigger mail/SMS. Keep production cron jobs separate.

1. Confirm the actual document root and PHP CLI binary. Export production database in hPanel/phpMyAdmin or with `mysqldump -u USER -p --single-transaction DB > /PRIVATE/BACKUP.sql` (password prompt, never a password in the command). Back up the full existing site, production config, `storage/private` and all `uploads` outside the document root. Verify file inventory, checksums and a test database restore.
2. Pause announcement/reminder cron jobs and stop writes. The old version has no confirmed maintenance mode; if necessary use Hostinger/access restrictions while replacing it. The prepared version supports `storage/private/maintenance.flag`, which returns HTTP 503 for web requests. Create that flag before enabling the new files. Do not leave old code accepting writes while taking the final coordinated backup.
3. Extract/copy only `deployment/DEPLOY_FILES.txt` into a staging/release directory first. Preserve the old working release. For in-place deployment, copy the reviewed runtime files without any delete/synchronization flag; retain production uploads and private storage. Do not copy local private files, test fixtures, seed SQL, caches or evidence. A Git checkout directly into a populated live document root is not the recommended first deployment.
4. Privately create `config.production.php` from the example, or configure `VISCAR_CONFIG_FILE` to an absolute private PHP file. Fill real hPanel credentials and the HTTPS base URL. Remove/exclude `config.local.php` from the new production release. Never commit either filled config. Use the same configuration for web and CLI/cron.
5. Install the reviewed `deployment/hostinger.htaccess` contents as root `.htaccess` after SSL works; retain the directory deny rules. Do not install the old extensionless `htaccess` file, whose root rewrites are not the application's current routing. Direct PHP routes require no front-controller catch-all.
6. From the release root, run these commands with **confirmed real values**, not the example placeholders:

```sh
PHP_BIN='/ABSOLUTE/CONFIRMED/PHP_BINARY'
APP_DIR='/ABSOLUTE/CONFIRMED/DOCUMENT_ROOT'
DB_NAME='CONFIRMED_EXISTING_DATABASE'
BACKUP='/ABSOLUTE/PRIVATE/VERIFIED_BACKUP.sql'
cd "$APP_DIR"
"$PHP_BIN" -v
"$PHP_BIN" tools/migrate_production.php --expected-database="$DB_NAME"
# Review the plan against the staging result, verify maintenance and paused cron:
"$PHP_BIN" tools/migrate_production.php --expected-database="$DB_NAME" --backup="$BACKUP" --apply
"$PHP_BIN" tools/deployment_preflight.php --schema
```

7. Confirm writable directories and HTTP denials. Missing document files, schema errors, provider/config errors or failed preflight keep deployment blocked. Set web `display_errors=Off`, `display_startup_errors=Off` and `log_errors=On` in hPanel as well. CLI PHP settings may differ from web PHP settings; verify both in hPanel, without exposing a public phpinfo page.
8. There is no application build cache to delete. If necessary reset OPcache through hosting controls/restart PHP. Do not clear uploads, sessions or private storage wholesale. Keep authentication pages/APIs out of CDN/full-page caching.
9. Once maintenance checks pass, remove **only** the maintenance flag, resume approved cron schedules and perform live smoke tests. Keep the previous release and backups until acceptance.

```sh
# The exact flag path must be verified first. This removes one marker, not storage.
rm -- "$APP_DIR/storage/private/maintenance.flag"
```

10. Cron: configure the confirmed PHP binary with `tools/deliver_announcements.php` every minute and `tools/reminders.php` every five minutes, using absolute paths and the production configuration. Use hPanel's appropriate custom-job mode if redirecting output to a private log. Do not run notification jobs until queued recipients/content are reviewed. Reminder delivery markers can prevent automatic retries after provider failure; inspect delivery audit and retry deliberately.

## Live smoke-test checklist

Use designated test accounts and approved recipients; mark test finance entries clearly and follow normal authorized reversals.

- Public: homepage, real approved logo/proportions, menu, splash/navigation, desktop and narrow mobile layouts. HTTP redirects to HTTPS without a loop; no mixed-content errors.
- Authentication: all four roles, wrong password, logout/session expiry, registration, password reset/change, every Show/Hide control, keyboard and Enter behavior, current-password re-authentication. Passwords remain hidden initially.
- Parishioner: canonical service/notes, date capacity, reservation, one and multiple attachments, selected-file removal, mixed types, invalid/oversized rejection, duplicate filenames, legacy download, payment, refund/reschedule/cancel, messages and authorized email/SMS receipt.
- Secretary: own-parish service/forms, applicant details/corrections, approve/reject with reason, Request Documents with multiple files, historical requirements, sacramental records/certificate, QR open versus actual check-in, wrong-parish denial. Test Android/iPhone camera permission and manual fallback.
- Bookkeeper: verified revenue/net/refund date reconciliation; limits and signatories for Check Voucher; petty-cash entry/set caps and replenishment; Deposit bank fields; Journal correction preserving originals; CSV/print/PDF and receipt access.
- Admin: dashboards/analytics period filters, announcements/queue, protected Main Database across parishes, re-authentication, read-only operational boundaries and protected downloads.
- Storage/security: direct config, private storage, requirements, tools and database URLs return 403/404; no directory listing; other-owner/other-parish attachment IDs are denied. PHP technical errors never appear in the browser.
- Backup: create/download a real backup and verify it contains every attachment. Test restore **on staging**, including invalid archive rejection; do not restore live data merely to check a button.
- Operations: confirm both cron jobs run exactly as intended, inspect private logs, provider acceptance and actual inbox/handset receipt. Check current/historical reports against known records and print margins on the real printer.

## Rollback procedure ? manual, never automatic

1. Stop writes again: enable hosting restrictions/maintenance, pause both cron jobs and preserve a fresh incident snapshot of database/config/uploads/logs. Do not overwrite the predeployment backup.
2. If database/files remain compatible and no bad migration/data change occurred, restore the prior code release and its corresponding private config first; keep all current uploads. Additive columns alone do not require destroying newer data. Verify the old code with the current schema on staging.
3. If database restore is necessary, keep maintenance active. Restore the verified predeployment SQL backup into a **separate recovery database**, not blindly over the live database. Compare schema/counts and attachments, reconcile any records created after the backup, then point the rollback private configuration at that recovered database after approval. Existing live data is retained for investigation.
4. Restore the matching private/public upload backup to the same relative paths. Preserve a separate copy of any newer uploads; never merge by overwriting different files with identical names. Keep legacy `uploads/requirements` and new `storage/private` documents plus their denial rules. The database and file snapshot must describe the same attachments.
5. Restore the previous HTTPS/web-server configuration and private environment settings. Keep secrets out of Git and backups out of public paths. Use hosting controls to restart PHP/clear OPcache if needed.
6. Run the authentication, role, attachment and financial smoke tests against the rollback release. Only then remove maintenance and resume the prior cron configuration. Retain both incident and predeployment snapshots until reconciliation is complete.

## Verification results and review package

- 307 integration/regression checks passed; 0 failed.
- 557 desktop/mobile password checks passed; 0 failed.
- 19 desktop/mobile multiple-attachment checks passed; 0 failed.
- 67 browser workflow/layout/report checks passed; 0 failed.
- 14 loopback email/SMS transport checks passed; no real-recipient delivery was attempted.
- 154 application/tool/template PHP files passed syntax checks; newly added PHP files were included. Two static include matches were commented historical examples, not executable missing requires. Dynamic includes/functions/classes are covered by the exercised routes; this is not a proof of every dormant branch.
- Local Apache homepage/login returned 200; private configuration/storage, tools, database and legacy requirement URLs returned 403.
- Missing production configuration failed closed, a simulated exception returned a generic 500 without its private marker/trace, and the maintenance marker returned 503.
- Migration plan and two guarded applies succeeded on the already-migrated synthetic fixture with existing counts preserved. This does not replace testing an older production-schema copy.
- Local preflight passes schema, dependency and file-presence checks, but correctly blocks the full-size upload scenario at the current 40 MB POST limit. Hostinger values remain unverified.

`deployment/VALIDATION.json` records the checks. `deployment/SECRET_SCAN.json` records the redacted candidate scan. `deployment/viscar-hostinger-code.zip` is a code-only package generated from the runtime manifest, with production HTTPS `.htaccess` substituted. It contains no filled config, uploaded user files, sessions, logs, seed database or backup dump. It is **not an automatic deployment or approval to deploy**. Install only after SSL/configuration/storage and the staging migration are ready.

Rebuild after any source change:

```sh
python tools/prepare_release.py
python tools/build_release.py
```

Python/Git are preparation tools; the runtime application does not require Python, Node, Composer or a new package manager. Hostinger only needs PHP plus the documented extensions. Generated zip files remain ignored by Git.

## Remaining known limits

No live Hostinger/GitHub access or production schema was verified. There is no approval to deploy. Physical phone scanners, printers, real provider delivery and large production restore/load behavior need acceptance testing. Some legacy lists/reports still materialize complete selected ranges. The approved white logo remains unavailable. Earlier credential exposure requires rotation. Historical attachment filenames and pre-snapshot form labels cannot be reconstructed. The production preflight checks column presence and dependencies, not every possible schema type/constraint drift; a staging restore/migration test remains mandatory.
