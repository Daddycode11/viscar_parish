# Local setup and revision checks

This remains the existing PHP/session application. The original pre-change audit is in `audit/requirements-audit.md`; post-change evidence is in `REVISION_REPORT.md`.

1. Start Apache and MySQL in XAMPP. This machine's Apache listens on **8080**.
2. Keep local settings in the ignored `config.local.php`. Its existing values are preserved; environment variables can override them. Required database variables are `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. Use `APP_ENV=local` and `APP_URL=http://localhost:8080/vicarparish-update` here.
3. From the project directory run `C:\xampp\php\php.exe tools\setup_local.php`. This creates the local database only if absent and applies additive compatibility/revision migrations. Back up an existing database first. A protected pre-compatibility backup of this machine's local database already exists under `storage/private/`.
4. Open `http://localhost:8080/vicarparish-update/`. Use existing accounts. If the database has no administrator, open `public/setup_admin.php` locally to create the first administrator; it closes after an administrator exists. Do not reset existing users to run tests.

Local mode records SMS/email in protected `storage/private/` logs. These are simulations; they do not prove delivery to a phone or mailbox. GCash is a reference submitted for bookkeeper verification; no live transfer is initiated. Refund approval is separate from recording an actual completed refund.

The reminder runner is `C:\xampp\php\php.exe C:\xampp\htdocs\vicarparish-update\tools\reminders.php`. Configure the deployment scheduler to run it every 15 minutes with the correct environment. No operating-system scheduler was installed by this revision. It records each approved booking/schedule once when due within 24 hours. External delivery failures are logged; automatic provider retries remain an operational follow-up.

Run `python tools/test_revisions.py` for HTTP/database checks. It creates a separate synthetic `vicar_revision_*` schema and disables outbound transport. Run `python tools/browser_revisions.py` after that for browser checks; it requires Playwright installed under `.browser-tools` and local Chrome. Both scripts retain synthetic evidence and databases for review. Never point fixture tools at a production database.

Apache must honor `.htaccess`: private storage, include files, SQL, logs, and local configuration must not be downloadable. PHP's built-in server is used only for isolated testing and does not implement Apache access rules. Before deployment, verify HTTPS, storage encryption/key management, outbound provider delivery, scheduler operation, backups, and physical scanner/printer behavior in that environment.

Two-factor login is opt-in under **Login Security** and uses an email code after the password. Local codes appear only in the protected email simulation log. Existing session authentication and password hashing are preserved; JWT has not been added.


## Refund/reschedule and accounting update

See [the current revision report](docs/SEPARATION_REVISION.md) and [pre-change audit](docs/SEPARATION_AUDIT.md). Local setup now includes `tools/migrate_separation.php`; run that CLI migration explicitly on an existing deployment after backing up the database. No provider credentials are supplied by this update.

`python tools/test_revisions.py` now runs the combined baseline and separation suite, writing `separation-test-results.json`. `python tools/test_transports.py` checks local mock providers only. `python tools/browser_separation.py` verifies revised pages and produces synthetic screenshots and printable PDFs under `revision-evidence/separation` using the existing Playwright installation and Chrome. The transport/browser checks use the latest synthetic fixture from the integration suite.

Staff are prompted to re-enter their password before sensitive information, with a five-minute verification window. Secretaries can classify services under Services. Parishioners opt into parish announcements using Settings; existing account parish membership is not silently treated as consent for subscriptions.
