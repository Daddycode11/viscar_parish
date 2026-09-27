# VISCAR master revision ? implementation and handoff

Date: 24 September 2026. Scope: the supplied 45-section master request, continued from the existing revision. The audit and existing reports were reviewed before changes. This workspace has no Git repository; the file inventory below records this revision rather than claiming a Git diff. Existing records and working role workflows were preserved.

## Features completed

- Canonical service types and local display names; sacramental classification and fixed/user-defined amount modes; legacy mapping without replacing IDs. Form editing supports quoted labels, saves the correct field, and snapshots definitions and requirements for historical applications.
- Admin operational application/payment mutations are denied by server guards. MAIN DATABASE requires password re-verification, searches approved Baptism, Matrimony, Confirmation and Funeral Mass applications across parishes, and audits record/document/certificate access. Downloads preserve existing certificates; printable records support browser Save as PDF.
- Secretary application details include submitted answers, historical labels, uploaded requirements, status, schedules, payment and request history. Corrections are audited and restricted once records are closed or certificates issued. Secretary parishioner lists are scoped to current parish membership and have no account mutation controls.
- Cancellation is separate from refunding: cancelled applications stop scheduling/payment actions and archive linked records; paid cancellations can request refunds. Refund approval and actual return remain separate stages. Actual completion records the refund date and links the payment to its request.
- Verified income, actual refunds, completed check vouchers, petty cash and disbursements share one net-revenue calculation. Refunds are deducted once while original verified income remains in gross revenue. Revenue charts use verification/payment dates. Parish comparisons and service demand use grouped queries instead of queries per parish/service. Dashboard filters and analytics honor dates; the monthly chart displays at most twelve months of a wider selected range.
- Accounting supports check limits of 20,000, petty-cash entry limits of 500 and set limits of 10,000, controlled replenishment, bank details, three check signatories, protected attachments, and journal corrections referencing original particulars without modifying original amounts. Reports provide date/type selection, CSV and printable/PDF layouts.
- Admin announcements can be edited or retired; retirement cancels queued delivery. Pending delivery only selects active announcements. Booking/request external notifications run after transaction commit, with failures reported rather than pretending success.
- Real ZIP backups contain database records and uploaded media, with a manifest and checksums. Restore validates schema, document presence, paths, checksums and foreign-key relationships, creates a recovery backup, uses a transaction, and revokes sessions. Failed restore rolls back database changes. Configuration credentials, sessions and logs are excluded.
- Booking availability shows aggregate capacity without applicant details; parish calendars include the signed-in parishioner's scheduled applications and parish events. QR opening is separated from actual attendance check-in. Mobile layouts, centered avatars, action visibility and request button contrast were corrected. Homepage logos retain proportions and the hero has a brighter background with readable text/buttons.

## Confirmed bugs fixed

- Form Edit embedded raw JSON in HTML attributes: quoted labels broke the selected field handler. Field objects now stay in a JavaScript cache and handlers pass IDs.
- Application rejection omitted its reason from the request. The reason now reaches the shared workflow.
- Backup controls previously simulated work and displayed fictional history. They now create and validate actual archives.
- Revenue omitted previously verified payments after refund, then deducted refunds again. Shared totals retain verified income and deduct the actual return once.
- Some date filters selected application creation dates for upcoming appointments. Appointment previews now filter the scheduled date independently.
- Analytics mixed selected-period demand with lifetime parish totals and unrelated months. All selected-period sections now share the range; the forecast explicitly describes its separate six-complete-month basis.
- Hardcoded SMTP credentials bypassed environment/mock-provider settings. Mail configuration now reads protected configuration/environment settings. Existing local values were moved without printing them in the report.
- Certificate export passed a verification page as an image URL. It now uses the existing local QR image endpoint.
- Zero-value financial charts could divide by zero; chart maxima now have a safe minimum.

The earlier reported refund error was not established as one specific original exception. The refund workflow was traced, hardened and tested for authorization, completion, repeat requests and cancellation interaction; this report does not invent a root cause for an unobserved exception.

## Files modified

| Area | Files |
| --- | --- |
| Entry/UI | `index.php`, `assets/css/enhancements.css`, `assets/js/apply-service.js` |
| Access and shared workflows | `includes/access.php`, `includes/revision_helpers.php`, `includes/application_document.php`, `includes/workflows.php`, `includes/document_workflows.php`, `includes/application_revisions.php`, `includes/request_workflows.php`, `includes/requests_page.php` |
| Accounting/reporting | `includes/accounting.php`, `includes/accounting_print.php`, `includes/report_data.php`, `includes/analytics.php` |
| Notifications | `includes/notifications.php`, `includes/announcement_delivery.php`, `includes/email_config.php`, protected `config.local.php` |
| Admin | `admin/includes/layout.php`, `admin/dashboard.php`, `admin/analytics.php`, `admin/applications.php`, `admin/finance.php`, `admin/reports.php`, `admin/announcements.php`, `admin/backup.php` |
| Secretary/Bookkeeper | `staff/dashboard.php`, `staff/services.php`, `staff/applications.php`, `staff/parishioners.php`, `staff/record_application.php`, `staff/schedule.php`, `staff/checkin.php`, `staff/walk_in.php`, `staff/accounting.php`, `staff/accounting_report.php`, `staff/finance.php`, `staff/export.php` |
| Parishioner | `parishioner/dashboard.php`, `parishioner/apply_service.php`, `parishioner/application.php`, `parishioner/events.php` |
| Documentation index | `docs/README.md` |
| Existing tools | `tools/setup_local.php`, `tools/test_separation.py`, `tools/separation_checks.py` |

## Files added

- `includes/service_types.php`, `includes/service_editor.php`, `includes/service_availability.php`
- `includes/financial_totals.php`, `includes/financial_summary.php`, `includes/accounting_rules.php`
- `includes/application_details.php`, `includes/backup_service.php`, `includes/dashboard_filter.php`
- `assets/js/availability-calendar.js`
- `admin/main_database.php`, `admin/verify_password.php`, `staff/application_details.php`
- `tools/migrate_master.php`, `tools/master_checks.py`, `tools/test_master_storage.php`, `tools/browser_master.py`
- `docs/MASTER_REVISION_AUDIT.md`, this report, and generated test/evidence files listed below.

## Database changes and local application

`tools/migrate_master.php` includes the earlier additive separation migration and is rerunnable. It adds:

| Table | Additions |
| --- | --- |
| services | general_type, classification, amount_mode |
| applications | form_schema, requirement_schema; cancelled status retained alongside existing enum values |
| payments | refunded_at, refund_request_id |
| accounting_documents | bank_name, bank_account_number, secretary_signatory, finance_signatory, priest_signatory, attachment, original_particulars, correction_reason, petty_cash_set, original_document_id, original_receipt_id |
| petty_cash_sets | New table for parish-specific replenishment cycles |
| application_requests | cancel type retained alongside existing enum values |

Legacy services are mapped only when general_type is unset. Unknown or ambiguous names remain Other / Custom; local names and IDs stay intact. Existing submitted answers are not rewritten. Legacy definitions are snapshotted from the available current definitions. Historical refund dates are populated only from completed refund requests.

Applied twice successfully to `vicarparish_local`. Before/after counts: 9 users, 6 applications, 2 payments, 3 services. A 44,312-byte SQL backup was created first at `storage/private/master-before-20260924-200349.sql`. It is protected by Apache access rules and should be kept private. No restore was run against local parish records; destructive restore tests used isolated synthetic databases.

For another installation: take its own backup, deploy these files and run `C:\xampp\php\php.exe tools/migrate_master.php` with that installation's intended database configuration. Do not use a test fixture as parish data.

## Security changes

Role/parish/ownership and CSRF checks remain on server endpoints. Admin central records and backup/restore require re-verification of the existing hashed account password and use the shared idle-session boundary. Secretary account edits are blocked. User amounts and accounting limits are checked server-side, not only in browser controls. Uploaded documents remain behind authorized routes. Journal corrections preserve original records. Restores reject missing documents, incompatible schemas, unsafe paths, conflicting file contents and invalid relationships; successful restores invalidate existing sessions.

## Testing performed

- Integration/role/privacy/workflow suite: 280 checks passed, zero failed. Includes payment/refund transactions, isolation, CSRF, cancellation, canonical mapping, historical forms, accounting limits and protected records.
- Storage and reconciliation: 10 checks passed, zero failed. Includes a real backup round trip, omitted-document rejection, failed-restore rollback and financial/date consistency.
- Email/SMS transport: 14 checks passed, zero failed, using loopback mock providers. Acceptance, rejection, missing configuration, invalid recipients and retry behavior were exercised.
- Browser acceptance: 67 checks passed, zero failed. Chrome at 1440, 768 and 390 pixels across all roles; actual form Edit/save, quoted labels, service creation, amount booking, availability, report PDF and uncaught JavaScript errors.
- PHP syntax: 217 files passed. Syntax is not a substitute for runtime coverage.
- Local Apache smoke checks: homepage and login returned 200; private fixture and local configuration URLs returned 403.

Evidence: `separation-test-results.json`, `master-storage-results.json`, `separation-transport-results.json`, `master-lint-results.json`, and `revision-evidence/master/results.json`. Screenshots and `revision-evidence/master/accounting-report.pdf` use synthetic data. Browser width checks establish absence of horizontal page overflow in those scenarios, not universal device certification.

## Remaining issues and manual configuration

- An approved white official logo was not supplied or identified. The existing color logo is preserved with correct proportions. Replace it only when the approved white asset is available.
- Rotate the SMTP app password previously embedded in source. It was moved into protected local configuration, but moving it does not revoke previous exposure. Production mail/SMS credentials and sender settings must be configured and delivery tested with authorized recipients. Provider acceptance is not proof of handset/inbox receipt.
- Confirm scheduler deployment for the existing announcement delivery/reminder jobs. This revision does not claim a scheduled backup service.
- Physical Android/iPhone camera behavior, unsupported-browser QR fallback, printer margins and real provider delivery require deployment acceptance. Tested Chrome and generated PDF are the available evidence.
- Before restoring a real installation, pause application writes and retain an external recovery copy. Restore supports matching schemas and transactional InnoDB tables; the UI accepts archives up to 64 MB with a 256 MB expanded limit. PHP upload limits may be lower and must be configured for the intended archive size. Large datasets and concurrent production restores were not load-tested.
- Legacy labels changed before snapshots existed cannot be reconstructed. Legacy refunded payments without an actual completion timestamp cannot be assigned to a reporting period accurately; reconcile these against source records rather than invent dates.
- Some legacy reporting/list endpoints still load their complete selected result sets before rendering. Broad all-time reporting at production scale needs volume testing and pagination/streaming work if necessary.
- The test matrix covers the listed workflows; it does not certify every button in the entire pre-existing application or every third-party/browser combination. Earlier reports remain historical records, not evidence that untested scenarios passed.
