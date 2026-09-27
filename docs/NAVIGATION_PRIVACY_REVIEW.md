# Back navigation and privacy agreement review

## Implemented navigation

The shared layouts had no consistent Back link. Standalone reports relied on `window.close()`, which is unreliable for manually opened tabs. Some views exposed a close control only after loading their content. The application wizard rebuilt dynamic fields and upload inputs on every return to the form, discarding the user's draft.

- Shared Admin, Secretary/Bookkeeper and Parishioner layouts now render a visible Back link. It uses normal browser history when a safe previous same-origin page exists. A directly opened page falls back to the role dashboard; a dashboard falls back to the public homepage. Authentication challenge/logout/reset pages are excluded as return destinations. New script-opened windows can close; normal tabs still have a working fallback.
- The navigation helper does not add history entries, intercept browser Back/Forward, or store draft data in browser storage. Existing query strings and filters are retained by normal history. Without JavaScript, the link still reaches its explicit fallback. Older browsers without the Navigation API use same-origin referrer/history checks; absent that evidence, they use the fallback.
- Modal windows have a visible Close button even during loading/error states, Escape handling, and focus return to their opener. Closing leaves the existing form DOM intact. Message-thread Back controls are visible on desktop as well as mobile.
- The application wizard handles its visible Back control as a previous-step action. Dynamic fields and selected files remain in place when returning for the same service; changing service invalidates the cached form. Failed/stale form requests do not enable continuation. State is not persisted across full reloads, session expiry or tab closure.
- Application document links now open an authenticated viewer with Back and Download controls. Both the viewer and original raw-file endpoint use one shared owner/parish/role authorization function. Raw file URLs and explicit downloads remain available without adding HTML to binary responses.
- Printable accounting documents, generated reports, certificates and receipts have navigation controls hidden from print output. Verification pages, logout confirmation and password recovery also have return links.
- The login OTP screen now offers a CSRF-protected Cancel sign-in action, which clears the pending OTP challenge and returns to login. It does not bypass authentication.

## Data Privacy Agreement: waiting for approved content

No approved agreement text, agreement version, acceptance rules or acceptance data model were found in the current application/schema or `For Enhancement.pdf`. The homepage contains only a `Privacy Policy` placeholder with `href="#"`. The PDF's sign-in/OTP feedback does not define privacy terms or consent requirements.

The user explicitly instructed that legal terms and acceptance rules must not be invented. Therefore no new privacy checkbox, mandatory sign-in gate, legal text, database acceptance record or acceptance migration was introduced. The missing information was requested while navigation work continued:

1. Exact approved agreement text and its version/effective date.
2. Which roles/users must acknowledge it and at what point in sign-in.
3. Whether acceptance is once per version, when re-acceptance is required, and how previously accepted users should be treated.
4. Any existing approved acceptance-record requirements that must be retained.

Once supplied, acceptance can be implemented using the project's authenticated user IDs, prepared queries, CSRF-protected POST, transactions and audit conventions. The version check must use the approved rule so unchanged accepted text does not prompt on every login. No acceptance is assumed from an existing login or account.

## Deployment and delivery configuration

Upload the files listed below, including the new navigation assets and `public/document_view.php`. No database migration is required for these navigation changes. Do not publish test fixtures, screenshots, private snapshots/logs or `.browser-tools`.

SMS/email behavior was not changed in this pass. The previous enhancement deployment requirements still apply: production `APP_ENV`/`APP_URL` and database settings; configured `EMAIL_ENABLED`, SMTP host/port/TLS/user/password/from address; configured SMS token/endpoint/provider; and an active provider account. Run `tools/deliver_announcements.php` every minute with production CLI settings, and schedule `tools/reminders.php` regularly. See [the enhancement deployment report](ENHANCEMENT_FIXES.md) for details. No actual recipient was contacted during tests.

## Validation and limitations

- **19 browser/API checks passed; no JavaScript errors.** [Targeted browser results](../revision-evidence/navigation/results.json): history Back/Forward, filtered URL retention, direct-tab fallback, wizard draft/file retention, mobile controls, modal Close/Escape/focus, document viewer, print controls and OTP cancellation.
- **21 PHP syntax checks passed.** [PHP syntax results](../navigation-lint-results.json): changed PHP files checked.
- Test database mutations are restricted to the existing isolated `vicar_revision_*` fixture. Browser requests to external hosts and real notification transports are blocked.
- Full-page form drafts rely on browser restoration where available; private/password/file data is not copied to localStorage/sessionStorage. Wizard/modal retention works within the current page. A selected file must be selected again after a full reload.
- Native PDF/image/download URLs remain raw resources; in-app viewing links use the new wrapper to provide explicit navigation. Embedded PDF support depends on the browser; Download and Open PDF fallbacks remain available.
- Privacy implementation is incomplete until approved content and acceptance rules are supplied. Existing sign-in verification remains in force.

## Changed files

- `includes/accounting_print.php`
- `includes/application_document.php`
- `includes/auth.php`
- `includes/navigation.php`
- `includes/pdf.php`
- `includes/recovery_form.php`
- `staff/applications.php`
- `staff/export.php`
- `staff/records.php`
- `staff/record_application.php`
- `staff/includes/layout.php`
- `admin/applications.php`
- `admin/generate_report.php`
- `admin/includes/layout.php`
- `parishioner/application.php`
- `parishioner/includes/layout.php`
- `public/document.php`
- `public/document_view.php`
- `public/logout.php`
- `public/verify.php`
- `assets/css/navigation.css`
- `assets/js/apply-service.js`
- `assets/js/navigation.js`
- `tools/test_navigation.py`
- `verify_otp.php`
