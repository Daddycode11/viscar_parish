# Refund, reschedule and platform revisions

> Updated behavior: see [21 September client fixes](ENHANCEMENT_FIXES.md) for session-long, scoped staff verification and production announcement queue deployment. The five-minute gate described below records the earlier revision.

## Implemented behavior

- Parishioners submit separate refund/reschedule requests. Bookkeepers alone review and complete refunds; Secretaries alone manage reschedules. Rejections, approvals and actual refund completion are audited. Admin request URLs and mutation endpoints are denied; Admin analytics retains aggregate request summaries. Existing financial reporting remains available.
- FAQ now contains application, payment, refund, reschedule, document, QR and notification guidance, with the existing working FAQ/message handoff preserved.
- All roles share profile validation and saving. Images are MIME/dimension checked, decoded and re-encoded as PNG, assigned random filenames, and shown in sidebar, navbar and settings. Account language and profile information are reloaded from the database. Password changes/reset invalidate older authenticated sessions.
- Admin settings save homepage name, title, subtitle, text, contact details, theme colors, logo, favicon and hero image to the existing `site_settings` table. Public homepage and shared panel branding consume the saved values with safe defaults and escaping.
- Central English/Filipino translations cover the revised pages and shared navigation. Language persists on the user record. Legacy sections retain English fallback where they have not been translated.
- Accounting has five selections: existing Official Receipt, Check Voucher, Petty Cash Voucher, Disbursement and Deposits. The four new document types share validation, unique numbering, idempotent creation, draft/completion transitions, recorded approver and print layout. Check/deposit completion requires a reference. Existing receipt routes and records remain in use.
- Accounting reports filter by date, authorized parish, status and type. CSV data and per-type totals use the same filtered rows, escape spreadsheet formulas, and use fixed safe filenames. Existing Export Reports remains available.
- Secretary walk-in entry selects an existing parishioner or creates a unique-email account, then calls the online booking validator for schedules, fees, dynamic fields and documents. Applications record `source=walk_in` and the Secretary creator. New walk-in accounts have an unknown random password and can claim access through Forgot Password.
- Approving a classified sacramental service creates one linked sacramental record and stable certificate number inside the approval transaction. Priest and remarks start blank. The record links back to submitted form data and all application documents, including later uploads. Ordinary services do not create records. Manual creation and approval serialize on the application row to prevent duplicate records.
- Application details expose a stable verification QR and print view. Public verification reveals reference/status only, not payment information or private documents. Image failure provides a verification-link fallback.
- Forgot Password uses generic account-existence responses, hashed random tokens, a 30-minute expiry, database-backed throttling, single-use redemption and password hashing. Email links carry the token in a URL fragment, avoiding HTTP access-log query strings. Production-disabled mail does not log reset bodies or report success.
- Announcement publishing reuses existing forms and transports. Parishioners choose any number of active parishes and per-channel preferences. Secretaries target their own parish; Admin can target selected parishes, all parishioners, staff or everyone. Recipient/channel rows and submission keys prevent duplicate sends. Delivery feedback distinguishes queued, sending, sent in-app, provider accepted, failed and local simulated states.
- Bookkeeper navigation and quick actions contain authorized functions. Sensitive staff pages and private documents/receipts require password verification lasting five minutes. Verification is throttled and audited. Sessions expire after 30 minutes of inactivity. Logout uses the application's session storage and a CSRF-protected POST.

## Database migration

Migration: `tools/migrate_separation.php`. It is CLI-only, checks `information_schema` before adding columns, and uses `CREATE TABLE IF NOT EXISTS`. No existing table or record is deleted. It ran on the existing local schema and repeatedly on isolated test schemas.

Added columns:

| Table | Columns |
|---|---|
| `users` | `language VARCHAR(3) DEFAULT 'en'`, `auth_version INT DEFAULT 1` |
| `applications` | `source VARCHAR(10) DEFAULT 'online'`, `created_by INT NULL` |
| `services` | `sacrament_type VARCHAR(100) NULL` |

Added tables: `accounting_documents`, `password_resets`, `security_rate_limits`, `parish_subscriptions`, `announcement_parishes`, `announcement_dispatches`, `announcement_deliveries`. The executable migration contains the exact SQL, indexes and unique keys.

The migration classifies exact canonical sacrament names once, recording `separation_sacrament_seed` in existing settings. The inspected local service named **Baptism** was classified. **Mass Request** and **Mass Intention** remain non-sacramental. Other/custom names can be classified by the Secretary under Services; no substring guessing or retroactive record creation occurs.

For an existing deployment with the earlier schema migrations installed:

For phpMyAdmin/Hostinger, use [database/migration_separation.sql](../database/migration_separation.sql). Export a backup first, select the existing application database, then use **Import** to upload this SQL file. It adds missing columns and tables and preserves existing records; no manual ALTER commands are necessary. This is an update, not a full database dump: the earlier project schema, including `site_settings`, must already exist. Run one import at a time. A successful import can be repeated without duplicating columns or resetting manually configured sacrament types.

Alternatively, run the equivalent CLI migration:

```text
php tools/migrate_separation.php
```

`php tools/setup_local.php` now includes this migration for fresh/local setup. Back up the deployment database and code before migration. A rollback should restore the prior code and retain additive tables/columns until newly created records have been exported/reconciled; dropping financial, reset or delivery tables is deliberately not automated. DDL is not claimed to be transactionally reversible.

## Routes and permissions

| Route | Behavior / permitted users |
|---|---|
| `parishioner/requests.php?type=refund` / `type=reschedule` | Owner request submission/history |
| `staff/requests.php` | Bookkeeper refunds or Secretary reschedules, according to role |
| `admin/requests.php` | Denied; summaries in `admin/analytics.php` |
| `admin/finance.php?ajax=refund` | Denied; refund controls removed |
| `{admin,staff,parishioner}/settings.php` | Shared account settings; homepage Admin-only; parish details Secretary-only |
| `staff/accounting.php?type=…` | Bookkeeper creation/listing/completion; `?print=ID` for vouchers/disbursements/deposits |
| `staff/accounting_report.php` | Bookkeeper filtered report; `export=csv` downloads |
| `staff/receipts.php`, `public/receipt.php` | Existing official-receipt routes preserved; owner receipts remain available |
| `staff/export.php` | Existing reports plus link to accounting report types |
| `staff/walk_in.php` | Secretary walk-in application |
| `staff/services.php` | Added explicit sacrament classification form |
| `staff/record_application.php?id=ID` | Secretary's parish-scoped submitted data/documents for a linked record |
| `parishioner/application.php?id=ID` | Owner application details, QR and printing |
| `public/verify.php` | Safe public verification, including a rejected application's actual status |
| `public/forgot_password.php`, `public/reset_password.php` | CSRF-protected reset request/redemption |
| `staff/verify_password.php` | Staff password challenge; no password stored in session |
| `admin/announcements.php`, `staff/announcements.php` | Existing publishing routes now use durable delivery and subscription targeting |
| `public/logout.php` | POST logs out; GET provides confirmation form |

## Configuration and deployment

- Set `APP_ENV=production` and `APP_URL` to the real **HTTPS** application URL on Hostinger. Keep local development explicitly `APP_ENV=local`. `APP_URL` supplies reset links and QR verification URLs, including installations in a subdirectory.
- Existing database settings: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. PHP 8.2+ is required by the existing `mysqli::execute_query` usage. Enable mysqli, mbstring, fileinfo, GD, OpenSSL and curl.
- Email: `EMAIL_ENABLED=1`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_TLS`, `SMTP_USER`, `SMTP_PASSWORD`, `EMAIL_FROM_ADDRESS`, optional `EMAIL_REPLY_TO`. The existing mailer supports STARTTLS; use the provider's corresponding port/configuration. Credentials remain in environment/local configuration, not templates.
- SMS: `SMS_API_TOKEN`, `SMS_ENDPOINT`, `SMS_PROVIDER`. The existing iProg adapter is retained. Unconfigured production transports fail explicitly.
- No new runtime framework/package or external QR service was added. Existing local QR code generation is reused.
- Uploads accept decoded JPG/PNG/WEBP/GIF up to 5 MB and 16 megapixels; output is PNG. Application-document validation remains the existing shared implementation. Set `upload_max_filesize` to at least 5M and `post_max_size` high enough for the submitted documents. Make `uploads/profile_pictures`, `uploads/site`, `uploads/parish_logos`, and protected `storage/private` writable by PHP, without enabling script execution in upload folders.
- Preserve Apache protection for `includes`, SQL, configuration, and `storage/private`. Test credentials, reset/OTP simulation logs, source snapshot and session files are kept under protected private storage. Browser screenshots/print artifacts use synthetic accounts only.

## Known limitations

- Live mailbox/phone delivery, physical printer/scanner capture and Hostinger deployment were not exercised. Provider acceptance is not proof of delivery; delivery-receipt webhooks are not part of the existing adapter.
- No unsafe automatic retry occurs after a failed/ambiguous external send. A row left `sending` by a process interruption needs provider-side reconciliation before retrying; this prevents duplicate sends when acceptance is uncertain. Still-queued rows can be resumed by the shared dispatcher.
- Full translation of every legacy template and historical announcement/message is not claimed. Revised sections and shared navigation use centralized translations; remaining legacy English falls back intact.
- Printing uses the project's existing browser print / Save as PDF approach. Voucher approval is performed and recorded by the authorized Bookkeeper; no additional approver role was invented.
- Existing historical duplicate records are not deleted or consolidated. New approvals and application-linked manual creation use transaction/row-lock protection.

## Validation and exact file manifest

See the generated sections below for test totals, workflow coverage and the exact source-file manifest for this revision.


### Test results

- **236 HTTP/database workflow assertions passed; 0 failed.** [Detailed results](../separation-test-results.json), [runner](../tools/test_separation.py), [additional checks](../tools/separation_checks.py).
- **8 local mock-provider tests passed.** [Results](../separation-transport-results.json). No real recipient was contacted.
- **11 Chrome browser checks passed; no uncaught JavaScript errors.** [Results](../revision-evidence/separation/results.json), [screenshots and print artifacts](../revision-evidence/separation/).
- **127 PHP files passed syntax checks.** [Results](../separation-lint-results.json).
- **7 normal XAMPP/Apache checks passed**, including the homepage, login, recovery page and denial of private storage/configuration/includes. [Results](../separation-apache-results.json).

| Required workflow | Result and scope |
|---|---|
| 1. Parishioner refund request | Passed: request persisted; refund requires verified payment. |
| 2. Bookkeeper refund processing | Passed: approval and actual completion separated; rejection and wrong-role checks. |
| 3. Secretary reschedule processing | Passed: approved schedule persisted; rejection and wrong-role checks. |
| 4. Admin analytics only | Passed: summaries visible, management URL and refund mutation denied, buttons absent. |
| 5. Unauthorized URL access | Passed: role/parish/owner checks across restricted modules and print/detail routes. |
| 6. Profile synchronization | Passed for all four roles; sidebar/dashboard, invalid image/oversize rejection, persistence after new login. |
| 7. Admin homepage settings | Passed: persisted name/logo/color/content, escaping, staff/parishioner denial. |
| 8. English/Filipino | Passed: all-role preference changes, Filipino settings/FAQ browser rendering, persistence in a new login. |
| 9. Five accounting document types | Passed: existing official receipt plus all four new types; unique submission, completion and audit. |
| 10. Accounting print/export | Passed: print responses for all types; browser voucher PDF; filtered CSV totals/date/parish/type/status validation. |
| 11. Walk-in | Passed: existing/new parishioner, duplicate-account prevention, dynamic required documents and Secretary provenance. |
| 12. Automatic records | Passed: linked form/documents, stable certificate, blank priest/remarks; no record for ordinary services. |
| 13. Duplicate approval | Passed: repeated approval/manual creation denied; injected record failure rolls back application approval. |
| 14. Application QR | Passed: visible loaded PNG, stable token, safe verification and print visibility. Physical scanner/printer use unverified. |
| 15. Forgot Password | Passed: generic response, hashed token, expiry, replay prevention, new-password login, existing-session revocation and CSRF. |
| 16. Email/SMS | Passed with local mock providers: acceptance/rejection, missing configuration and durable failure status. Live delivery unverified. |
| 17. Parish targeting | Passed: multiple selections, per-channel simulation, subscriber-only audience, multi-parish deduplication, unsubscribe and role restrictions. |
| 18. Bookkeeper quick actions | Passed: only authorized links; no announcement/application management links. |
| 19. Password re-verification | Passed: staff dashboard/API/document gating, valid/invalid passwords, expiry, throttling and audit. |
| 20. Session/CSRF/validation | Passed: idle expiry, logout invalidation, public/authenticated form CSRF, invalid uploads/amounts/dates and authorization checks. |

### Exact source/documentation file changes

The list below covers this PDF-driven revision. The earlier login CSS/JavaScript extraction was already present at the start of this revision and is not counted again. Machine-readable list: [separation-file-manifest.json](../separation-file-manifest.json).

| Change | File |
|---|---|
| Modified | `LOCAL_SETUP.md` |
| Modified | `admin/analytics.php` |
| Modified | `admin/announcements.php` |
| Modified | `admin/finance.php` |
| Modified | `admin/includes/layout.php` |
| Modified | `admin/includes/sidebar.php` |
| Modified | `admin/settings.php` |
| Created | `docs/SEPARATION_AUDIT.md` |
| Created | `docs/SEPARATION_REVISION.md` |
| Modified | `includes/access.php` |
| Created | `includes/accounting.php` |
| Created | `includes/accounting_print.php` |
| Created | `includes/announcement_delivery.php` |
| Created | `includes/announcement_status.php` |
| Modified | `includes/announcements_page.php` |
| Created | `includes/application_links.php` |
| Created | `includes/application_revisions.php` |
| Modified | `includes/auth.php` |
| Created | `includes/branding_head.php` |
| Created | `includes/faq_guidance.php` |
| Modified | `includes/notifications.php` |
| Created | `includes/password_recovery.php` |
| Created | `includes/recovery_form.php` |
| Created | `includes/request_summary.php` |
| Modified | `includes/request_workflows.php` |
| Modified | `includes/requests_page.php` |
| Created | `includes/revision_helpers.php` |
| Created | `includes/service_classification.php` |
| Created | `includes/service_classification_form.php` |
| Created | `includes/settings_page.php` |
| Created | `includes/settings_service.php` |
| Created | `includes/site_settings.php` |
| Modified | `includes/smtp_mailer.php` |
| Created | `includes/translations.php` |
| Created | `includes/translations/fil.php` |
| Modified | `includes/workflow_routes.php` |
| Modified | `includes/workflows.php` |
| Modified | `index.php` |
| Created | `parishioner/application.php` |
| Modified | `parishioner/dashboard.php` |
| Modified | `parishioner/faq.php` |
| Modified | `parishioner/includes/layout.php` |
| Modified | `parishioner/settings.php` |
| Modified | `public/document.php` |
| Created | `public/forgot_password.php` |
| Modified | `public/login.php` |
| Modified | `public/logout.php` |
| Modified | `public/receipt.php` |
| Created | `public/reset_password.php` |
| Modified | `public/signup.php` |
| Modified | `public/verify.php` |
| Created | `staff/accounting.php` |
| Created | `staff/accounting_report.php` |
| Modified | `staff/announcements.php` |
| Modified | `staff/applications.php` |
| Modified | `staff/bookkeeper/dashboard.php` |
| Modified | `staff/dashboard.php` |
| Modified | `staff/export.php` |
| Modified | `staff/includes/layout.php` |
| Modified | `staff/payments.php` |
| Created | `staff/record_application.php` |
| Modified | `staff/records.php` |
| Modified | `staff/services.php` |
| Modified | `staff/settings.php` |
| Created | `staff/verify_password.php` |
| Created | `staff/walk_in.php` |
| Created | `tools/browser_separation.py` |
| Created | `tools/migrate_separation.php` |
| Created | `tools/separation_checks.py` |
| Modified | `tools/setup_local.php` |
| Modified | `tools/test_revisions.py` |
| Created | `tools/test_separation.py` |
| Created | `tools/test_transports.py` |
| Modified | `verify_otp.php` |


Generated review artifacts: `separation-test-results.json`, `separation-transport-results.json`, `separation-lint-results.json`, `separation-apache-results.json`, `separation-file-manifest.json`, and `revision-evidence/separation/` (four screenshots, two printable PDFs and browser results). Test databases and files contain synthetic data; private fixture credentials and simulation logs remain under `storage/private`.
