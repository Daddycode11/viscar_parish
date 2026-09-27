# Master revision — 24 September 2026

The existing application is PHP 8.2/MySQLi, with separate Admin, staff (Secretary/Bookkeeper), and Parishioner pages. `includes/access.php` applies role, parish and CSRF guards; `auth.php` owns password verification, sessions and idle expiry. `workflows.php` and `workflow_routes.php` provide transactional booking, payment and application actions. `request_workflows.php` separates refund approval from actual payment return. `application_document.php` protects private uploads. SMS/email transports, announcement delivery and reminder jobs already exist. Shared CSS and role layouts provide the established visual system.

Inspected the source schema, migrations and live information_schema for services, applications, payments, requests, sacramental records, announcements and accounting documents. Existing working data is not a test fixture. Regression runners create synthetic `vicar_revision_*` databases. This directory has no Git repository.

Confirmed gaps: simulated Backup/Restore with fictional history; per-service-ID demand totals; missing normalized service and fee mode fields; Admin operational mutations; Secretary account mutation controls; malformed quoting in form-field edit handlers; absent cancellation status; missing accounting limits, bank/signatory fields and petty-cash cycles; no password-gated central records menu.

Implementation sequence:
1. Repair role boundaries and form editing; retain existing transactional workflows.
2. Add rerunnable schema extensions, normalized services and historical form snapshots.
3. Extend cancellation/refund accounting and financial validation.
4. Add protected central records and real validated backup/restore.
5. Integrate UI controls and run isolated regression/security checks.

Physical Android/iPhone cameras, printers and real provider delivery require deployment acceptance. Previous reports are historical evidence, not proof of this revision.
