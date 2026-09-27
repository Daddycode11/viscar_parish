# Client enhancement fixes ? 21 September 2026

## Scope and causes

Read all 13 pages of `For Enhancement.pdf`, including the annotated screenshots. This pass addresses the four requested fixes and directly related PDF defects. A scope question was raised for the PDF's larger new features; no answer was received before proceeding with the stated focused scope.

| Area | Cause | Final change |
|---|---|---|
| Staff interruptions | `access.php` challenged nearly every staff route; `sensitive_until` expired after 300 seconds independently of login. Application idle expiry is 30 minutes. PHP session garbage collection was not aligned with that limit. | Password verification now applies to Secretary Applications/Records and Bookkeeper Accounting/Export Reports, including related detail/download/print routes and Secretary dashboard mutations. Verification lasts for the authenticated session; logout, password change/reset and idle expiry invalidate it. Normal dashboards, schedules, messages, payments and refunds no longer ask for this extra password. PHP GC uses the same 1,800-second minimum lifetime. Admin is not enrolled in the staff gate. |
| Missing requirement notes | The service-selection query omitted `requirements_note`; the wizard proceeded directly to scheduling. | Return notes with the selected service and display an optional reading screen before scheduling. Empty/whitespace-only notes skip the screen. Text rendering preserves line breaks without executing HTML. Changing service resets acknowledgement; stale service-list responses are ignored. The existing wizard script is extracted to its own JS asset. |
| Notification failures/feedback | Missing contacts were silently skipped; local simulations counted as sent; malformed SMS HTTP-success bodies could count as success; after-commit delivery results were discarded. Login/signup resend screens claimed email was sent even after failure. Secretary Compose saved only in-app messages. Bulk announcement delivery ran in the publishing request. | Count failure, simulation and provider acceptance separately; reject malformed SMS results; return persistent delivery feedback; retain failed-compose input and release loading state after network errors. Compose exposes optional SMS/email. OTP delivery and resend feedback now reflect actual transport results without bypassing authentication. Production announcements queue external delivery and use a CLI worker; local simulations remain immediate. SMTP supports STARTTLS and implicit TLS on port 465. Reminders record safe per-channel counts in their audit and CLI output. |
| Fonts, alignment and documents | Added headings/controls/tables lacked shared styling; broad input CSS stretched checkboxes. Detail modals retained `display:flex` from the loading placeholder, forcing content into a row. Documents always used attachment/octet-stream responses. | A shared CSS asset applies the existing heading/body fonts, spacing, compact checkboxes, responsive tables/forms, accounting tabs and avatar clipping. Detail containers return to normal stacked layout. Authorized PDF/JPEG/PNG documents open inline with their detected MIME; `download=1` retains explicit download. Bookkeeper navigation reads Financial Overview. |
| Admin edit form (PDF p.2) | Edit fields read `$filtered[0]`, the first listing result, rather than the requested ID. | Fetch and populate the selected user; unknown IDs return 404. A parish filter no longer overrides the edited account's parish. |

## Deployment

No new database migration is needed beyond the existing `migration_separation.sql` schema. Upload the changed application files and new CSS/JS assets. Do not upload the PDF extract, test fixtures, private logs, test evidence or `.browser-tools` as public assets.

Configure the same environment/local settings for web PHP and scheduled CLI PHP:

- `APP_ENV=production`, `APP_URL=https://your-domain` (including any application subdirectory).
- Existing `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
- Email: `EMAIL_ENABLED=1`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_TLS`, `SMTP_USER`, `SMTP_PASSWORD`, `EMAIL_FROM_ADDRESS`, optional `EMAIL_REPLY_TO`. Use the mail provider's permitted sender and credentials. Port 587 uses STARTTLS when `SMTP_TLS=1`; port 465 uses implicit TLS. Preserve certificate verification.
- SMS: `SMS_API_TOKEN`, `SMS_ENDPOINT`, `SMS_PROVIDER`; valid registered Philippine mobile numbers. Ensure the provider account can send and has sufficient credit.
- PHP 8.2+ and the existing required extensions; OpenSSL for SMTP, curl and mbstring for SMS. Keep `storage/private` writable and protected.

**Schedule the new announcement worker every minute**, using the hosting account's actual PHP binary and absolute application path:

```text
/path/to/php /absolute/app/path/tools/deliver_announcements.php
```

It claims only queued rows, prevents overlapping worker runs, and never resends already accepted/failed/sending rows automatically. Inspect Announcements ? Delivery status after publishing. Without this scheduler, production external announcements remain queued, although publishing and in-app notifications succeed. Existing reminder scheduling must also run `tools/reminders.php` regularly (for example every 15 minutes) to notify approved, unchecked-in appointments due within 24 hours. Configure both jobs against the production database and environment.

After deployment, verify login OTP and a single message to an authorized test recipient, then a subscribed announcement. Provider acceptance is not proof of mailbox/handset delivery. No real email or SMS was sent during this implementation.

## PDF conflicts and remaining scope

- Written feedback says sign-in notifications work; PDF pages 2/6/9 report missing login OTP and page 13 says only signup works. The shared transport and OTP flow were preserved, and failure feedback was corrected. All-role OTP is tested locally; the host's actual credentials, sender policy and provider delivery are not verifiable here. Enabling Login Security saves a preference; the OTP is sent at the next login, not when the checkbox is saved.
- PDF pages 4/8 narrow staff verification to named areas, conflicting with the former broad five-minute gate. Implemented those areas plus equivalent actions/documents so direct URLs cannot bypass verification. The PDF gives no replacement duration; the stated session-long verification meets the user's no-five-minute-interruption requirement while preserving the 30-minute idle expiry.
- The PDF also proposes a password-gated Admin central records/export area, standardized service names and classifications, refund-only parish analytics, removing Admin application actions, new accounting fields/limits/signatories/journal vouchers and replenishment rules, plus homepage/signup branding and removal of duplicate help/dashboard sections. These are not claimed implemented in this focused pass. They alter existing features/permissions rather than only fixing the four reported paths.
- Before extending those areas, resolve the PDF's Secretary replenishment role versus existing Bookkeeper accounting ownership; the meaning/scope of the PHP 10,000 replenishment cycle; removal of Holy Orders/Reconciliation from configurable sacramental types versus existing classified historical records; and button-only theme editing versus current homepage color settings. Existing records and permissions were not silently rewritten to infer those rules.
- Original parish-targeted announcements respect subscription/channel preferences. An empty subscriber audience is reported; the fix does not enroll users without their choice. Features that originally notify only in-app remain in-app unless SMS/email options already existed or the PDF explicitly requested them for Compose.
- Failed or interrupted external deliveries are not automatically retried because a timeout may follow provider acceptance. Reconcile provider status before retrying to avoid duplicate messages. Reminder records remain one attempt per application/schedule; the delivery audit now exposes failures, but a retry/reconciliation interface is outside this pass.
- Live Hostinger configuration, SMTP TLS negotiation with the actual provider, physical devices and printer output remain deployment validation tasks.

## Validation

- Existing regression suite: 237 checks passed, zero failed. Includes role/parish isolation, OTP, reset revocation, idle expiry, booking/payment/document/accounting flows.
- Local mock-provider suite: 14 checks passed, including malformed gateway responses, missing contacts, production queueing and repeated worker execution.
- Targeted Chrome/API checks: 29 passed, zero failed; no uncaught JavaScript errors. Screenshots and [results](../revision-evidence/enhancements/results.json). Covers desktop/mobile notes, compact checkbox, six-minute staff access, full idle expiry, Compose, selected Admin edit data and document viewing.
- Changed PHP syntax checks: 27 passed, zero failed; [results](../enhancement-lint-results.json).

All test database mutations target synthetic `vicar_revision_*` schemas, not the parish's working data. External browser requests were blocked; fonts therefore use the configured fallback fonts in test screenshots.

## Changed code and test files

- `includes/access.php`
- `includes/announcement_delivery.php`
- `includes/auth.php`
- `includes/document_workflows.php`
- `includes/notifications.php`
- `includes/password_recovery.php`
- `includes/revision_helpers.php`
- `includes/settings_service.php`
- `includes/smtp_mailer.php`
- `includes/workflow_routes.php`
- `includes/translations/fil.php`
- `staff/accounting.php`
- `staff/announcements.php`
- `staff/applications.php`
- `staff/messages.php`
- `staff/records.php`
- `staff/verify_password.php`
- `staff/includes/layout.php`
- `admin/users.php`
- `admin/includes/layout.php`
- `parishioner/apply_service.php`
- `parishioner/includes/layout.php`
- `public/document.php`
- `public/signup.php`
- `assets/css/enhancements.css`
- `assets/js/apply-service.js`
- `assets/js/delivery-feedback.js`
- `tools/deliver_announcements.php`
- `tools/reminders.php`
- `tools/separation_checks.py`
- `tools/test_enhancements.py`
- `tools/test_transports.py`
- `verify_otp.php`
