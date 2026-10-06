# Recommendation (2).pdf revision review

Source: the supplied four-page PDF, including its calendar, service editor, chart, palette, homepage, and login screenshots. Only that document's requests authorize these revisions. No instructions from the document were treated as operational authorization.

## Implemented changes

| PDF section / request | Change | Files |
| --- | --- | --- |
| Parishioner: Review & Resubmit heading; remove action taken | Correct heading; submitted requests leave the action queue. Uploaded files remain accessible in application details. Closed applications are excluded. | `parishioner/documents.php` |
| Parishioner / Secretary: calendar legend, remaining slots | Green available, red full, gray past/unavailable. Fixed-time dates show remaining daily and per-time capacity, respecting existing daily limits. Unlimited capacity is labeled; user-defined times explain their per-time limit. | `assets/css/palette.css`, `assets/js/availability-calendar.js`, `includes/service_days.php`, `includes/calendar_availability.php` |
| Parishioner / Secretary: scheduled services and calendar formatting | Parish calendar includes service bookings without exposing other applicants' details or private links. Replaced question-mark separators and allowed calendar text to wrap. | `parishioner/events.php`, `staff/schedule.php`, `assets/css/recommendations.css` |
| Parishioner: payment review mobile layout and conditional fields | Reference/proof controls appear only for digital-bank choices; cash hides and disables them. Controls, QR images, and long review answers fit the container. | `parishioner/apply_service.php`, `assets/js/apply-service.js`, `assets/css/recommendations.css` |
| Parishioner: FAQ parish labels | Each answer identifies its source parish; unassigned FAQs are labeled general. | `parishioner/faq.php` |
| Parishioner: dashboard removals | Removed payment-history panel and service-applications-over-time chart. Existing application list and financial total remain. | `parishioner/dashboard.php` |
| Parishioner: My Payment / refund navigation | Removed separate payment-entry form; each completed payment links to its application's refund request. Renamed navigation to Reschedule & Cancellation. | `parishioner/payments.php`, `parishioner/includes/layout.php`, `includes/requests_page.php` |
| Parishioner: application View and Edit | View opens all existing application details and files. Owners can edit pending application answers with validation, CSRF, row locking, and audit history. Approved/checked-in applications stay locked; schedule/cancellation requests retain staff approval. | `parishioner/application.php`, `parishioner/dashboard.php` |
| Bookkeeper: AR receipt numbering | New receipts use AR; existing receipt identifiers remain unchanged. | `includes/workflows.php` |
| Bookkeeper / Secretary: refunds visible and subject to approval | Secretary can read parish refund requests but cannot approve or complete them. Payment refund links now open the scoped request workflow rather than the rejected direct-refund action. | `includes/requests_page.php`, `staff/payments.php` |
| Bookkeeper / Secretary: dashboard removals | Removed pending application/payment panels and cards, recent activity, and the bookkeeper service-breakdown panel. | `staff/dashboard.php` |
| Bookkeeper / Others: multiple trend lines | Monthly service comparison lines with shared scale, service legend, hover values, zero-filled months, and exact-value table. Existing role/parish and date filters remain. | `includes/service_trend.php` |
| Secretary: remove Max Daily Limit, keep applications per time | Removed the input and its JavaScript references. Existing saved daily limits are preserved when editing; new services default to no daily limit. | `staff/services.php`, `includes/service_editor.php` |
| Secretary: sacramental-record archiving | Archive button explicitly posts through the existing role, parish, and CSRF guards. | `staff/records.php` |
| Others: login attempt counter/countdown | Displays 1 / 3, 2 / 3, and 3 / 3 with remaining lockout time. Existing database-backed five-minute enforcement remains authoritative. | `includes/auth.php`, `public/login.php` |
| Others: organization palette and portal name | Shared green/gold palette; login/signup welcome labels use Apostolic Vicariate of San Jose in Occidental Mindoro. | `assets/css/palette.css`, `public/login.php`, `public/signup.php` |

## Existing behavior checked, not replaced

Secretary-created bank QR methods, parishioner proof uploads, bookkeeper validation, refund approval/completion separation, secretary rescheduling/cancellation approval, receipt/file access guards, QR image generation/token verification, phone validation, login terms/privacy links, and public announcements already have implementations and regression coverage. Tests use synthetic records and loopback/mock transports only.

The report layout changes in `staff/accounting_report.php`, `staff/export.php`, and `staff/receipts.php` existed before this task. They are protected and excluded from this commit, as is the pre-existing change to `staff/application_details.php`. This revision does not claim those edits as its own.

## Unresolved items and deployment dependencies

- **Overdue resubmissions:** no deadline or expiry policy exists in the request schema. A definition of overdue is required; no deadline was invented and no records were deleted.
- **Application archiving:** applications have no archive marker or incomplete status. Implementing a durable archive requires an additive schema change and agreement on what makes an application incomplete. This part remains unchanged; no deployment/migration files were modified.
- **User-defined-time availability:** there is no finite list of times from which to compute a single day-wide slot total. These dates display their per-time limit and explain that remaining capacity depends on the chosen time. Exact per-time remaining counts are implemented for fixed-time services.
- **Reported service/manual-payment failures:** creation, editing, and manual payment flow are verified on a migrated synthetic schema. Existing code reports missing schema as a deployment dependency. If the deployed installation lacks the existing scheduling fields or `parish_payment_methods`/payment metadata, its administrator must resolve that separately. No production migration or compatibility fallback was attempted.
- **SMS and QR use on real devices:** production sender/provider configuration, balance, handset delivery, HTTPS/camera access, and a device-reachable configured application URL cannot be established by local tests. No production credentials or URL settings were changed, and no real messages were sent.
- **Push:** repository has no GitHub deployment workflow, but Hostinger's external branch/webhook configuration is unavailable. No push is safe until its trigger scope is established. No deployment settings were changed.

## Verification and Git isolation

Final verification results are recorded after the checks finish. The integration runner creates its own `vicar_revision_*` database; it does not migrate the configured parish database.

The task started on `main` at `de5bf24216cc81561443effa51418c01c52edd8b`, matching the remote head read with `git ls-remote`. The separate branch is `revision/recommendation-pdf-20261007`. No unrelated commits precede this task on that branch. The four protected files are checked by SHA-256 against their pre-edit contents and excluded from staging. No Hostinger configuration, deployment workflow/script, environment file, credentials, or production data is included.

Task-specific test files: `tools/recommendation_pdf_checks.py`, `tools/test_separation.py`, `tools/attachment_checks.py`, `tools/pdf_revision_checks.py`, `tools/browser_recommendations.py`. Existing assertions were adjusted only where this PDF explicitly removes their former UI targets.
