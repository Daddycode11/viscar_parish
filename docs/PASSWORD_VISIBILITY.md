# Shared password visibility ? 27 September 2026

One shared presentation component adds a 44px eye button beside each password input. It uses the existing `ui_icon('eye')` SVG and overlays a slash while the password is visible. No library was added.

## Audit coverage

| Form | Source / integration |
| --- | --- |
| Login | public/login.php |
| Registration and confirmation | public/signup.php |
| Reset and confirmation | includes/recovery_form.php via public/reset_password.php |
| Change current/new/confirm password, all roles | includes/settings_page.php via all three role layouts |
| Security preference password, all roles | includes/security_page.php via all three role layouts |
| Admin and Secretary/Bookkeeper re-verification | admin/verify_password.php, staff/verify_password.php via role layouts |
| Admin creates a user, password and confirmation | admin/users.php?action=add via Admin layout |
| Initial Admin setup, password and confirmation | public/setup_admin.php, admin/setup_admin.php |

Forgot-password takes an email only and OTP verification takes a code, so neither gains a password toggle. Historical copies under `audit/runtime` are archived evidence, not application routes, and were not edited. Dynamically inserted password inputs are enhanced by the same component.

## Files

Added `includes/password_visibility.php`, `assets/js/password-visibility.js`, `assets/css/password-visibility.css`, and `tools/test_password_visibility.py`.

The shared include is loaded from `admin/includes/layout.php`, `staff/includes/layout.php`, `parishioner/includes/layout.php`, and the five standalone templates listed above. Setup pages also receive viewport metadata and bounded container widths for narrow screens.

## Behavior and boundaries

Inputs remain `type="password"` in server markup. The toggle changes only the input type and its own presentation/accessibility labels; it does not read or write the password value, send requests, submit a form, or use storage/logging. Existing name, autocomplete, required, length limits and validation remain intact. Without JavaScript, the original hidden password fields continue working.

Native `type="button"` controls support Tab, Space and Enter. Each has an action-specific aria-label, aria-controls, a visible focus outline and a decorative SVG hidden from assistive technology. Only its associated field changes. Form reset and back/forward page restoration restore concealed presentation. Authentication handlers, password hashing, authorization and server validation were not edited.

## Verification

`tools/test_password_visibility.py` uses isolated `vicar_revision_*` accounts, blocks external resources/transports, and tests Chrome at 1440px and 390px widths. It checks every rendered password field for default concealment, independent toggling, keyboard activation, unchanged value/validation attributes, no toggle submission, Enter-to-submit, required validation while visible, target size and viewport fit. It also performs real Enter-to-login with visible passwords for all four roles.

For the per-field Enter event check, the test temporarily bypasses unrelated required fields and prevents the submit event from sending any request, then restores the form setting. Required-field validation is tested separately. Password-changing forms are never submitted to change an account. Initial setup templates are exercised with their source styles because live setup routes properly deny/redirect when an Admin already exists; their server guards were not bypassed.

Final browser result: 557 checks passed, 0 failed. See `password-visibility-results.json` for individual results. All nine touched PHP templates/include passed PHP syntax checks. Mobile results are Chrome viewport tests, not physical iPhone/Android certification.
