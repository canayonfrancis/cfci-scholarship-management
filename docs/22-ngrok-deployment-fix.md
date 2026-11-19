# Ngrok deployment investigation and fix

Verified on 2026-11-19 (Asia/Manila), against the running application:

- Local: `http://127.0.0.1:8000`
- Public: `https://stubble-moneybags-grafted.ngrok-free.dev`

The missing styles and insecure generated URLs are fixed and verified over both origins. A successful real-account login over the public tunnel remains a manual check; successful authentication, session rotation, role restrictions, and logout were exercised with disposable test accounts in SQLite memory instead. The broader role test selection has existing route-reference failures described below.

## Confirmed root causes

### 1. An active Vite development server overrode the built assets

`public/hot` existed and contained `http://[::1]:5173`. No custom hot-file location was configured in the application or Vite config. Laravel's installed `Vite::isRunningHot()` checks for this file's existence; it does not choose the manifest merely because a build exists.

Before changes, **both** localhost and Ngrok `/login` returned these exact URLs:

```text
http://[::1]:5173/@vite/client
http://[::1]:5173/resources/css/app.css
http://[::1]:5173/resources/js/app.js
```

The project Vite process was actually running: Node PID 24868, with its command line pointing to this project's `node_modules/vite/bin/vite.js`. Independent socket and HTTP checks found IPv6 `::1:5173` listening and all three URLs returning 200 locally. IPv4 `127.0.0.1:5173` refused connections. The PowerShell TCP listing alone did not reveal this IPv6 listener, so the independent checks mattered.

The hot file therefore was not simply an abandoned file at investigation time. It pointed to an active server reachable only on this computer. A public visitor's `::1` points to their own device, and these HTTP development URLs also conflict with the HTTPS page. The compiled manifest already existed, but `@vite` was selecting development mode. Rebuilding alone did not address that selection.

### 2. Laravel did not trust the local reverse proxy

`bootstrap/app.php` had no trusted proxy configuration. `AppServiceProvider.php` did not force a scheme. The Ngrok local inspector showed the actual forwarded request headers:

```text
Host: stubble-moneybags-grafted.ngrok-free.dev
X-Forwarded-Host: stubble-moneybags-grafted.ngrok-free.dev
X-Forwarded-Proto: https
```

The agent's upstream was `http://localhost:8000`. Laravel ignored the forwarded HTTPS scheme and generated these insecure URLs in the public login HTML:

```text
http://stubble-moneybags-grafted.ngrok-free.dev/images/favicon.webp
http://stubble-moneybags-grafted.ngrok-free.dev/images/cronasia-school.webp
http://stubble-moneybags-grafted.ngrok-free.dev/images/cronasia-logo-white.png
http://stubble-moneybags-grafted.ngrok-free.dev/login
```

`APP_URL` already contained the correct HTTPS public URL. This did not override the current HTTP request's scheme for `asset()` and `route()`. Globally forcing HTTPS would also affect normal local HTTP access, so trusted proxy handling was used.

### 3. Cookies were configured for HTTPS even on local HTTP

The private `.env` had `SESSION_SECURE_COOKIE=true`. Initial localhost responses set both session and XSRF cookies with `Secure`. This was a separate local compatibility problem, not the cause of missing CSS. The file also contained duplicate `SESSION_DOMAIN=null` entries.

## Actual project and files inspected

- Laravel **12.69.2** installed; `composer.json` requires `laravel/framework:^12.0` and PHP `^8.2`.
- Node **26.13.0**, npm **11.20.0**, Vite **7.3.6**, Laravel Vite plugin **2.1.0**, Tailwind **3.4.19**, Alpine **3.17.2**; Ngrok **3.39.11**.
- Standard Laravel 12 structure: `app`, `bootstrap`, `config`, `routes`, `resources`, `public`, `tests`; frontend uses Vite, Tailwind, Alpine, and Breeze authentication.
- `composer.json`, `package.json`, dependency metadata, `vite.config.js`, `postcss.config.js`, `tailwind.config.js`, `.gitignore`.
- `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `.env` (only selected non-secret values reported), `.env.example`.
- `config/session.php`; URL-related references in `config/app.php` and `config/filesystems.php`.
- `resources/views/layouts/{guest,app,navigation}.blade.php`, `resources/views/auth/login.blade.php`, application logo component; source searches across `app`, `bootstrap`, `config`, `routes`, `resources`, and `public`.
- `resources/js/app.js`, `resources/js/bootstrap.js`, related module inventory, and built JS search.
- `routes/web.php`, `routes/auth.php`, `AuthenticatedSessionController`, `LoginRequest`, and selected authentication/role tests.
- `public/hot`, `public/build/manifest.json`, both referenced build files, login images and favicon.
- Installed framework `Vite`, `TrustProxies`, middleware configuration, session middleware, and Symfony request/response/cookie handling.
- Runtime processes, port/socket responses, Ngrok local inspector headers, HTTP responses, and rendered Chrome pages.
- `docs/26-profile-settings-removal.md`, which documents previously deleted settings routes and outdated tests.

No hardcoded Ngrok domain, development asset URL, localhost asset origin, or HTTP login endpoint was found in application templates or JS. Existing localhost configuration defaults, database/service hosts, SVG namespaces, and spreadsheet XML namespaces are legitimate references and were left intact.

## Files modified and fixes implemented

| File | Change |
| --- | --- |
| `bootstrap/app.php` | Trust forwarding headers only from `127.0.0.1` and `::1`, using Laravel 12's middleware configuration API. Honor forwarded client address, host, port, and protocol. No wildcard proxy trust. |
| `.env` (private, ignored) | Set `SESSION_SECURE_COOKIE=null`, keep `APP_DEBUG=false`, remove duplicate `SESSION_DOMAIN=null`. Retain existing HTTPS `APP_URL`; no secrets recorded. |
| `.env.example` | Document request-dependent cookie security with `SESSION_SECURE_COOKIE=null`. |
| `package.json` | Add `npm run build:ngrok`; existing `build` and `dev` commands remain available. |
| `scripts/prepare-ngrok.mjs` | Remove default `public/hot` only after Vite builds successfully, so Laravel selects the build manifest. Remind the operator to keep Vite stopped while sharing. |
| `tests/Feature/Deployment/TrustedProxyTest.php` | Four regression checks for IPv4/IPv6 proxy handling, HTTPS URLs/cookies, local HTTP, and rejection of forwarded headers from untrusted addresses. |
| `docs/22-ngrok-deployment-fix.md` | This report. |
| `docs/screenshots/22-{localhost,ngrok}-login-{desktop,mobile}.png` | Four browser captures of the unchanged UI after the fix. |

Stopped the confirmed project Vite process, successfully built the frontend, removed `public/hot`, and cleared configuration and compiled views. `public/build` was regenerated and remains ignored by Git. `public/hot` remains absent. No Vite listener remains on port 5173.

Temporary diagnostic scripts/logs were written under ignored `storage/framework/testing`, including `ngrok-deployment-check.mjs`, `ngrok-browser-results.txt`, and `ngrok-role-test-results.txt`. These contain diagnostic results, not credentials or cookie values.

No Blade, CSS, authentication controller, authorization policy, business service, migration, or application database record was edited. HTTP testing naturally creates/updates framework session records and authentication rate-limit state. Automated account/role tests use the `phpunit.xml` SQLite `:memory:` database and disposable records; no application-database migration or seeding command was run.

## Build results

Both `npm run build` and `npm run build:ngrok` exited successfully. Vite transformed 64 modules; the final build took approximately 1.03 seconds.

| Manifest entry | Output | Exact bytes | SHA-256 prefix |
| --- | --- | ---: | --- |
| `resources/css/app.css` | `public/build/assets/app-Bi3wANl0.css` | 95,341 | `44fcb2202aea6818` |
| `resources/js/app.js` | `public/build/assets/app-BiJYpC6D.js` | 130,713 | `d8b10848256f13c5` |

`public/build/manifest.json` contains both entries. Every referenced output exists. The bytes downloaded through Ngrok and localhost match for CSS, JS, logo, background, and linked favicon. No missing manifest dependency was found.

## Verification results

### Generated URLs and HTTP responses

Local generated URLs use `http://127.0.0.1:8000`; public generated URLs use `https://stubble-moneybags-grafted.ngrok-free.dev`. The table paths apply to both origins:

| Resource | Generated path | Local / Ngrok status | Response content type |
| --- | --- | --- | --- |
| Login page | `/login` | 200 / 200 | `text/html; charset=utf-8` |
| CSS | `/build/assets/app-Bi3wANl0.css` | 200 / 200 | `text/css; charset=UTF-8` |
| JavaScript | `/build/assets/app-BiJYpC6D.js` | 200 / 200 | `application/javascript` |
| Logo | `/images/cronasia-logo-white.png` | 200 / 200 | `image/png` |
| Background | `/images/cronasia-school.webp` | 200 / 200 | `image/webp` |
| Linked favicon | `/images/favicon.webp` | 200 / 200 | `image/webp` |

The exact public CSS URL is `https://stubble-moneybags-grafted.ngrok-free.dev/build/assets/app-Bi3wANl0.css`; the exact public module URL is `https://stubble-moneybags-grafted.ngrok-free.dev/build/assets/app-BiJYpC6D.js`. No `@vite/client`, port 5173, or development entrypoint occurs in the final rendered login page.

Public form action is `https://stubble-moneybags-grafted.ngrok-free.dev/login`; local form action is `http://127.0.0.1:8000/login`.

### Browser, sessions, redirects, and CSRF

Used a fresh, isolated, headless Chrome profile with real network requests to both running origins. No assets were mocked and browser security remained enabled. The automated browser added Ngrok's warning-bypass header only to the tunnel's own origin, not to third-party fonts.

- Desktop **1440 x 1000** and mobile **390 x 844**: existing styled layout renders, logo/background load, no horizontal overflow. Measured logo widths are 395 px desktop and 300 px mobile, matching existing responsive styles.
- Final browser run: **zero console/security errors and zero failed network requests** on each login page. External Bunny stylesheet and font files also load.
- Alpine's password visibility control toggles successfully in both directions on both origins.
- Both session cookies are host-only, `SameSite=Lax`; session cookie remains `HttpOnly`. Public cookies have `Secure`; local HTTP cookies do not. Cookie values were never logged.
- Empty login POST with the real CSRF token and browser cookies: **302** back to that origin's `/login`, followed by **200** displaying email/password validation errors. CSRF token remains the same across the request, demonstrating persisted session state.
- A deliberately invalid `.invalid` test address is rejected on each origin and the client remains a guest. No real account credentials were used.
- Login POST without a CSRF token returns **419** on both origins.
- Guest `/dashboard` returns **302** to the correct origin's `/login`; the Ngrok Location header uses HTTPS.
- `/` returns **302** to `/login` on both origins.
- Public HTTP `/login` returns **307** to the HTTPS equivalent.
- Sanitized runtime inspection confirms `app.debug=false`, `session.secure=null`, `session.domain=null`, database session driver, no cached config, and Vite hot mode disabled.

Screenshots:

- [Ngrok desktop](screenshots/22-ngrok-login-desktop.png) and [Ngrok mobile](screenshots/22-ngrok-login-mobile.png).
- [Localhost desktop](screenshots/22-localhost-login-desktop.png) and [Localhost mobile](screenshots/22-localhost-login-mobile.png).

### Automated PHP checks

```powershell
php artisan test tests/Feature/Deployment/TrustedProxyTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/StaffAccessAuditTest.php
```

**27 passed, 108 assertions.** This includes valid authentication, invalid password rejection, logout, trusted/untrusted proxies, and account/role audit behavior in the isolated test database. The new proxy tests cover forwarded public HTTPS and ordinary local HTTP without changing application security rules.

The initial broader selection additionally included `StudentRoleTest.php` and `OperationalRoleSecurityTest.php`: **47 passed, 14 failed, 541 assertions**. All 14 failures are `RouteNotFoundException` references to absent `profile.edit`, `profile.update`, or `scholars.store` routes. The two role suites were rerun to inspect the failures: **20 passed, 14 failed**. Passing cases include Student session rotation/logout, intended redirects, rejection of forged roles, rate limiting, staff login/logout, and denial of unauthorized access.

The settings-route removal and stale tests predate this change and are documented in `26-profile-settings-removal.md`; the existing scholar resource excludes its store route. No routes were restored or tests suppressed to make the selection green. A full-suite success is not claimed.

PHP syntax checks, Laravel Pint on the changed PHP files, and `git diff --check` pass.

## share-modal.js investigation

No `share-modal.js` file, import, or reference was found in application source or public assets. The rendered page loads only the compiled application module, and the final clean-browser runs show no null-element `addEventListener` exception. There is therefore no confirmed application-owned share-modal bug to patch.

The original error's exact source is unresolved without its full script URL/stack. If it persists in an existing browser profile, inspect that URL and repeat in a private window with extensions disabled. An extension or injected script is possible, but that attribution has not been proven.

## Remaining issues and limits

1. Successful login, authenticated pages, remember-me behavior, and logout using real deployment accounts over Ngrok still require the manual steps below. No real credentials were supplied or retrieved. Test-database results do not substitute for that end-to-end check.
2. Fourteen existing role-suite failures reference removed/missing routes. Reconciling those tests belongs to the prior settings/scholar workflow changes.
3. The legacy `/favicon.ico` file is empty and returns 200 with zero bytes. The actual login layouts explicitly link `/images/favicon.webp`, which loads correctly. The unused legacy file was left unchanged.
4. Vite must remain stopped during build-based Ngrok sharing. Starting `npm run dev` recreates `public/hot` and selects development assets again. `build:ngrok` removes the hot file but does not manage or stop processes automatically.
5. Proxy trust is intentionally limited to the local agent's loopback addresses. A future remote/container proxy needs its actual address configured. Continue binding the PHP development server to loopback.
6. Ngrok's free-tier browser warning may appear for a new human visitor. Use its Visit Site action before testing the app. Automated checks bypassed only that warning.

## Exact startup commands

The application and Ngrok were already running during investigation and were left available. The commands below are for a fresh start; do not launch duplicate listeners on occupied ports.

First stop this project's `npm run dev` terminal with **Ctrl+C**. Do not use `composer run dev` for this sharing mode because it starts Vite. In the private `.env`, use:

```dotenv
APP_DEBUG=false
APP_URL=https://stubble-moneybags-grafted.ngrok-free.dev
SESSION_SECURE_COOKIE=null
SESSION_DOMAIN=null
```

Leave `ASSET_URL` unset for assets to follow the current request origin. Preserve the existing application key, database, and other credentials.

Terminal 1, PowerShell:

```powershell
Set-Location C:\Users\DOKII\Documents\Capstone\cfci-scholarship-management
php artisan config:clear
php artisan view:clear
npm run build:ngrok
if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed; do not start the tunnel.' }
if (Test-Path -LiteralPath public/hot) { throw 'Vite hot file still exists.' }
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2, PowerShell, with the already authenticated Ngrok installation:

```powershell
ngrok http http://127.0.0.1:8000 --url=https://stubble-moneybags-grafted.ngrok-free.dev
```

No new Ngrok token, migration, seeding, or key-generation command is needed. When the assigned tunnel URL changes, update private `APP_URL` and clear configuration; do not edit Blade templates.

For ordinary local development later, stop sharing, set private `APP_URL=http://127.0.0.1:8000`, keep `SESSION_SECURE_COOKIE=null`, clear configuration, and run the usual PHP server plus `npm run dev` in separate terminals. The existing Vite configuration and development command are unchanged. Before sharing again, stop Vite and run `npm run build:ngrok`.

## Step-by-step manual testing

1. Start the two processes above. Confirm `public/hot` is absent and `APP_DEBUG=false` before opening the public URL.
2. Open a fresh private browser window. Visit localhost `/login`, then the Ngrok `/login`. Pass Ngrok's Visit Site warning if shown. If an old profile has stale Secure localhost cookies from before this fix, clear site data once.
3. In DevTools Network, disable cache and reload. Confirm the hashed CSS and JS paths in the table return 200 with their proper content types. Confirm no `@vite/client`, `:5173`, or loopback URL occurs in public resource requests.
4. Check the public favicon, logo, background, login action, and forgot-password link all use HTTPS. Inspect desktop and mobile widths; compare the screenshots without changing UI styling.
5. Check the console for mixed content, connection refusal, JS exceptions, or `share-modal.js`. If the latter appears, capture its full source URL and stack and compare with extensions disabled.
6. Toggle password visibility twice. Confirm it changes between text and password. Submit an invalid credential once and confirm the expected authentication error, without excessive attempts that trigger rate limiting.
7. In Application/Storage cookies, confirm host-only scope, SameSite=Lax, and an HttpOnly session cookie. Public session cookies must be Secure; local HTTP session cookies must work without Secure. Do not copy cookie values into a report.
8. Using an existing authorized staff test account, log in locally. Confirm the expected dashboard, refresh, and open an allowed page. Log out and confirm protected pages redirect to login.
9. Repeat staff login, refresh, permitted navigation, and logout over Ngrok. Inspect the POST and redirect chain: HTTPS throughout, no 419 with a valid form, no redirect to localhost, and no session loss.
10. Using an existing Student test account, repeat on both origins. Confirm the intended Student profile destination and that staff-only URLs remain denied. Test an existing restricted staff role against a forbidden page without submitting business-data changes.
11. Verify Remember me with an approved test account if required, then log out. Confirm no authenticated response is accessible after logout.
12. For a safe CSRF negative check, submit a login POST without `_token` in the same browser session; expect 419. Reload `/login` afterward. Do not change CSRF middleware.
13. Record the real-account results separately. Do not mark them passed solely from this investigation's isolated account tests.

## Framework references

- [Laravel 12 trusted proxy configuration](https://laravel.com/docs/12.x/requests#configuring-trusted-proxies).
- [Laravel 12 Vite integration](https://laravel.com/docs/12.x/vite).

The conclusions above were checked against the installed framework code and actual running responses, not inferred solely from documentation.
