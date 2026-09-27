# Interface icon revision

The public site, administrator dashboard, staff pages, and parishioner pages now use local monochrome SVG icons in place of emoji decorations. Existing page structure, colors, forms, and workflow processing are retained. Currency symbols remain readable amounts, and the FAQ automation continues to identify its automated answers.

## Implementation

- [assets/js/icons.js](../assets/js/icons.js) defines the SVG paths, shared sizing, and rendering behavior. Icons inherit their surrounding text color and require no external library, icon font, or remote image service.
- [includes/icons.php](../includes/icons.php) loads the renderer for HTML responses. JSON responses and file downloads are not modified by this loader.
- The parishioner dashboard uses `ui_icon()` from that helper to render its content icons directly as inline SVG, including payment summaries and QR actions. Its content icons do not need marker conversion.
- Administrator, secretary, bookkeeper, and parishioner navigation bars also use `ui_icon()`, including top bars, sidebars, and mobile menu controls. Public desktop/mobile navigation uses the same SVG style. Shared server-rendered paths are stored in [icon_paths.json](../includes/icon_paths.json).
- Templates use named markers such as `[icon:calendar]`, `[icon:bell]`, and `[icon:check]`. The renderer converts them into SVG elements. It also handles content inserted through JavaScript, including text-based toast and modal updates.
- CSS pseudo-elements on the public pages use local SVG data masks. They do not rely on emoji fonts.
- Icons are hidden from screen readers when accompanying text. Icon-only controls receive an accessible label from their existing title or icon name.
- [icon-revision-files.json](../icon-revision-files.json) lists the interface files changed by the source replacement. Workflow processing in `includes/workflows.php` was not rewritten for this visual revision.

To add an icon, use an existing named marker in the template or add a matching SVG path to the renderer. Keep the adjacent action label descriptive. Do not insert an icon marker into stored user content, an email, a CSV field, or a plain-text notification destination; the markers are an HTML-interface convention.

JavaScript must load for the named markers to render. Generated standalone documents should use SVG markup or load the renderer when they contain markers. Existing certificate and receipt templates contain no converted emoji markers.

## Verification evidence

- [Icon browser results](../revision-evidence/icons/results.json): public and role pages checked for rendered SVG, visible leftover markers, emoji, and JavaScript errors; dynamic text insertion checked for each role.
- [Secretary dashboard preview](../revision-evidence/icons/secretary.png), [administrator preview](../revision-evidence/icons/admin.png), [bookkeeper preview](../revision-evidence/icons/bookkeeper.png), and [parishioner preview](../revision-evidence/icons/parishioner.png).
- [Changed PHP syntax checks](../revision-evidence/icons/lint.json).
- The existing HTTP/database suite was rerun: 114 assertions passed. Browser login, booking submission, and cash-payment persistence also passed on a fresh synthetic fixture.
