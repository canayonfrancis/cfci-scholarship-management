# Browser context menu and developer-tool shortcuts

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**.

The shared `resources/views/layouts/browser-guard.blade.php` cancels the page context menu, F12, Ctrl+Shift+I/J/C/K and Command+Option+I/J/C/K where browsers allow page scripts to cancel these actions. It is included in the authenticated layout, guest/login layout, welcome view and the standalone payroll, liquidation and report print views. It applies to every role, including right-clicks inside form fields.

Normal typing, selection, Ctrl/Cmd+C/V, Tab, Escape, navigation, form submission and Ctrl/Cmd+P are not blocked. No alerts, redirects, reloads or developer-tool detection loops were added. Authorization, encryption, masking and existing workflows remain unchanged.

This cannot disable browser developer tools. Browser menus, already-open tools, browser-specific overrides and disabling JavaScript can bypass it. It must not be relied upon to protect confidential information or prevent unauthorized requests.

No asset build, new dependency, database change or environment setting is required. The guard is rendered through Blade rather than loading the application bundle into print views. If compiled views are stale, the developer may run `php artisan view:clear`; it was not run here.

Developer verification (not performed):

1. Refresh login and authenticated pages, including Student and staff pages. Right-click and press the listed developer-tool shortcuts; expect default actions to be cancelled where the browser permits.
2. Open the payroll, liquidation and Reports print pages; check the same controls and confirm existing Print buttons and Ctrl/Cmd+P still work.
3. Check form typing, copying/pasting with the keyboard, Tab/Escape, menus and modal dialogs. Browser overrides may still open developer tools; this does not imply an authorization failure.

Static source review and `git diff --check` only. No automated tests, manual/browser testing, builds, application commands or database operations were performed.
