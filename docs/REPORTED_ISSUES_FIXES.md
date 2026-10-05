# Reported issues: 5 October 2026

## Findings and fixes

- **Time controls:** native browser controls followed device locale, manual slots accepted only 24-hour tokens, and several views printed raw database timestamps. Shared explicit hour/minute/AM-PM controls and presentation helpers now retain canonical database values. Midnight and noon map to `00:00` and `12:00`. The dashboard also converted parish wall-clock values through `toISOString()`, shifting rescheduled times; it now preserves the original date and time directly.
- **Bookkeeper Payments:** the listing explicitly selected newly introduced optional metadata columns. Reproducing a pre-migration schema generated MySQL error 1054. The listing now tolerates those missing optional columns, keeps parish and role boundaries, and shows an error reference while logging real query failures. Empty results retain the existing useful empty state. This reproduces a concrete failure path; the original deployed incident cannot be conclusively attributed without its server logs.
- **Secretary forms:** AM/PM entries failed the old slot parser. Digit-leading field labels generated invalid internal names, and generic errors did not identify the offending input. Slots now parse AM/PM consistently, generated names start with a letter, and field errors preserve entered data. Missing schema updates return an actionable migration message. Browser inspection also found repeated dashboard modal IDs inside the application loop; the reschedule and document-request dialogs are now single instances outside the table.
- **Visual consistency:** shared navy button/pill rules use existing brand variables, readable text, and hover/focus/disabled states. Time controls wrap on small screens, and the selected calendar outline uses the existing palette. No layout redesign.

## Changed application files

- Shared behavior: `includes/time_format.php`, `includes/config.php`, `includes/navigation.php`, `assets/js/time-controls.js`, `assets/css/time-controls.css`, `assets/css/recommendations.css`.
- Forms and payments: `includes/service_editor.php`, `staff/services.php`, `staff/payments.php`, `staff/dashboard.php`.
- Time presentation and scheduling: `assets/js/apply-service.js`, `assets/js/availability-calendar.js`; `includes/announcements_page.php`, `includes/application_details.php`, `includes/calendar_availability.php`, `includes/report_data.php`, `includes/request_workflows.php`, `includes/requests_page.php`, `includes/workflows.php`; `admin/applications.php`, `admin/dashboard.php`, `admin/generate_report.php`, `admin/main_database.php`, `admin/reports.php`; `parishioner/application.php`, `parishioner/dashboard.php`, `parishioner/documents.php`; `staff/accounting_report.php`, `staff/announcements.php`, `staff/applications.php`, `staff/masses.php`, `staff/receipts.php`, `staff/records.php`; `tools/reminders.php`.
- Packaging and verification: `deployment/DEPLOY_FILES.txt`, `tools/test_time_format.php`, `tools/reported_issue_checks.py`, `tools/test_separation.py`, `tools/browser_reported_issues.py`, `tools/browser_recommendations.py`, and the documentation index.

## Actual verification

- PHP time helper: **17 checks passed**, including midnight, noon, surrounding minutes, invalid values and canonical round trips.
- Synthetic-database integration suite: **450 passed, 0 failed**. Includes Secretary create/edit, transactional validation failure, field edits, CSRF, cross-parish and role denials, Bookkeeper records and zero matching records, Admin finance, optional-column compatibility, and deliberate server failure with logging. Temporary schema changes were restored.
- Headless Chrome: **25 passed, 0 failed**, no uncaught browser errors. Includes retained form input, successful service/field create/edit, seven AM/PM boundary selections, unchanged saved values, event midnight editing, rescheduling without UTC conversion, parishioner noon selection, mobile controls, Bookkeeper records/empty search results and Admin finance.
- PHP lint: 165 files passed; the dashboard and reminder edits were also checked after their final changes. Whitespace validation passed.
- Mobile reschedule screenshot inspected. Test artifacts remain private under `revision-evidence/reported-issues/` and `storage/private/`.

The empty-state checks used a search returning no records, rather than an entirely empty production parish. Tests used local synthetic accounts and data; no production browser session, production migration, or live notification delivery is claimed. The broader recommendation browser script was updated for AM/PM expectations but is not counted as a completed rerun here.

No new migration or rewrite of existing schedule values is needed for these follow-up fixes. Deployments still require the recommendation migration shipped with the preceding implementation when its schema updates have not yet been applied. Local secrets and generated test artifacts are excluded from Git.
