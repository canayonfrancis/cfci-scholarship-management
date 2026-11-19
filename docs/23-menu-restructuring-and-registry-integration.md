# Menu restructuring and registry integration

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

Current update (2026-11-19): the standalone Academic verification module was removed
at the user's request. Billing and Validation now contains Billing batches and
Evaluations only, according to the same existing permissions. The original module
mapping below is historical where it mentions Academic verification. See
`docs/24-academic-verification-module-removal.md` for the removal and retained data.

## 1. Scope and inspected baseline

Retained the CFCI floating horizontal header, logo, account controls, horizontal
navigation scrolling and mobile navigation toggle. Staff modules are organized under
the requested five primary entries, with desktop hover disclosure navigation,
touchscreen tap fallback and keyboard controls. No sidebar was introduced.

The requested report path did not exist in this checkout, so this report was created.
The separate `docs/23-password-confirmation-page.md` was left unchanged.

Reviewed existing navigation/Alpine/CSS, installed Alpine teleport support, route
definitions, registry/academic/roster/billing/financial/report controllers and policies.
No standalone Reason for Delisting registry exists: closing/relinquishing a scholarship
uses the existing individual assignment workflow. The user explicitly selected
**Show the item as unavailable**; no destination or new registry was invented.

Only static source review, JavaScript syntax checks, Blade source compilation and
production asset compilation were performed. No automated tests were created or
executed, no manual/browser testing occurred, and no database operation/migration
or sample record creation was performed for verification.

## 2. Menu structure and existing destinations

The following staff order is preserved, with unauthorized links/groups omitted:

1. Dashboard — direct link.
2. Information Registry — dropdown.
3. Billing and Validation — dropdown.
4. Liquidation and Payment — dropdown.
5. Reports — direct link.

| Information Registry item, in order | Existing destination / permission |
| --- | --- |
| Scholarship Grant / Program | `scholarship-programs.index`; existing `viewAny` ScholarshipProgram policy |
| Batch | `scholarship-batches.index?registry=batch`; existing `viewAny` ScholarshipBatch policy |
| Course / Program | `academic-programs.index`; existing `viewAny` AcademicProgram policy |
| Scholars | `scholars.index`; existing `viewAny` Student policy |
| Academic Year | `academic-periods.index?registry=academic-year`; existing `viewAny` AcademicPeriod policy |
| Semester | `academic-periods.index?registry=semester`; same existing AcademicPeriod policy |
| Reason for Delisting | Visibly unavailable, no URL/click action; shown with authorized Scholar registry visibility |
| Roster | `scholarship-batches.index?registry=roster`; existing ScholarshipBatch policy |
| Student accounts | Retained authorized shortcut through existing `student-accounts.provision` gate |

Academic Year and Semester are entry points into the existing combined Academic years
and semesters module. Batch and Roster reuse the existing semester roster/batch module;
they do not create a second batch entity. The optional `registry` query identifies
which menu alias was selected, is not a business field, and is ignored by existing
controllers. Canonical academic-period links default to Semester highlighting and
canonical scholarship-batch links default to Roster. The parent remains active if
an internal link/filter drops the alias query.

Billing and Validation retains Billing batches, Evaluations and Academic verification,
each filtered by its existing model `viewAny` gate. Liquidation and Payment retains
the existing financial index plus Billing stages / awaiting funds, using the original
`financial-view` gate. Reports keeps the original authorized default report selection:
grants for users allowed to view rosters, otherwise billed.

Student-only accounts retain their authorized direct My profile link rather than
staff menus. The existing My student profile user-menu link remains available to
any account with `viewSelf` permission, including supported mixed-role accounts.
No generic Profile & Settings page/link was reintroduced. User identity/role labels
and the original CSRF-protected logout operation remain.

## 3. Desktop hover behavior

`resources/js/primary-navigation.js` defines shared Alpine disclosure state for all
primary groups. Desktop starts at the existing CSS breakpoint above 980px. Mouse
hover opening additionally requires actual `(hover: hover) and (pointer: fine)`
capability and ignores touch pointer-entry events.

- Entering a parent immediately opens its dropdown without clicking.
- A single `openMenu` key replaces any previously opened primary dropdown.
- Parent/panel pointer entry cancels a pending close.
- Pointer exit schedules closing after **200 ms**. In hover mode, the menu closes
  only when the pointer is over neither its trigger nor panel.
- A six-pixel transparent panel bridge covers the physical parent-to-panel gap;
  the delay also tolerates diagonal movement.
- Clicking an already-hovered mouse parent keeps it open instead of unexpectedly
  collapsing it. Selecting a child closes navigation before following its existing URL.
- Hovering a direct primary link closes the prior dropdown; the direct link itself
  retains normal navigation.
- Clicking outside or moving focus outside the disclosure closes it. Keyboard-open
  menus remain usable while focus is inside even when the pointer is elsewhere.

No hover action submits a form, runs an evaluation or updates a business record.

## 4. Positioning, appearance and clipping prevention

Desktop panels use installed Alpine `x-teleport="body"` so they are outside both
the horizontal overflow wrapper and the header's backdrop-filter containing block.
They use fixed positioning measured from the parent trigger's bounding rectangle,
six pixels below it, with a 340px maximum width and viewport-edge clamping. Client
viewport width excludes the page scrollbar. Long lists have a viewport-bounded
internal scrollbar; the pointer bridge remains outside the scrolling inner panel.

Panels have a white background, existing typography, 14px corners, subtle border/shadow,
existing icons and CFCI red hover/active states. Their `z-index: 1000` places them
above normal page content. Native modal dialogs retain their browser top-layer priority.
Opening a fixed-position panel does not shift page content or change header height.

Parent labels include a chevron. Existing active pills highlight the parent whenever
an authorized child route is active; active child links use red text/soft background.
The existing horizontal scroll buttons/fade edges remain. Active-parent detection
also scrolls grouped items into view. Horizontal navigation scrolling repositions
an open panel while its trigger remains visible and closes it if its trigger leaves
the visible navigation area. Page scrolling updates position; viewport resize closes
open controls to avoid stale geometry or a dropdown left open across breakpoints.

## 5. Touch and mobile fallback

Wide touchscreen devices can tap desktop parent buttons to toggle their panels;
hover is not required. Pointer modality prevents touch-generated entry events from
opening a menu before its tap toggle.

At 980px and below, the original mobile navigation button opens the existing top
navigation panel. Parent buttons tap-toggle inline child lists, with only one mobile
group expanded at a time. Selecting a child closes the group and main panel. The
mobile panel scrolls vertically within the viewport when the registry is long.
It remains a top navigation dropdown, not a sidebar.

The account dropdown remains an account action control separate from the five
primary module entries; its existing click/tap interaction is preserved.

## 6. Keyboard and accessibility

The implementation uses navigation disclosures with native buttons, lists and real
links, rather than application-style ARIA menu roles. Each parent has stable child
panel IDs, `aria-controls` and a reactive `aria-expanded` state. Desktop panels and
mobile lists are labeled by their respective parent controls. Active child/direct
links retain `aria-current="page"`.

- Tab focus on a desktop parent opens its disclosure in keyboard mode.
- Enter/Space on its native button opens and focuses the first child.
- Down/Up on the parent opens and focuses the first/last available child.
- Tab from an open parent enters its child links.
- Up/Down/Home/End navigate available child links. The unavailable Delisting item
  has no fake URL and is skipped by the interactive-link sequence.
- Shift+Tab from the first child closes the panel and returns focus to its parent.
- Tab from the last child closes the panel and proceeds to the next primary item
  or the account control, rather than following the portal's physical body placement.
- Left/Right among desktop primary items moves focus through the existing horizontal
  navigation. The scroll wrapper can reveal newly focused entries.
- Escape closes a desktop disclosure and restores parent focus without immediately
  reopening it. On mobile it closes the expanded group first, then the main panel;
  account Escape returns focus to the account trigger when appropriate.
- Disabled Delisting text uses `aria-disabled="true"` and an understandable reason.
  All statuses have text/expanded-state information in addition to color.

Focus and pointer handling explicitly recognize teleported panels, so their physical
location outside the header does not count as an outside interaction.

## 7. Business compatibility and changed files

Created:

- `resources/js/primary-navigation.js`
- `docs/23-menu-restructuring-and-registry-integration.md`

Modified:

- `resources/views/layouts/navigation.blade.php`: shared permission-filtered module
  structure, desktop disclosures/portals and mobile groups; original account/logout.
- `resources/js/app.js`: registers the new Alpine component and extends existing
  scrollable-navigation active-item lookup to include active group triggers.
- `resources/css/app.css`: scoped dropdown/disclosure/mobile-list styling and
  horizontal group-item support; existing floating header design retained.

No routes, controllers, models, request validation, policies, business actions/services,
database schema or existing tests were changed. Scholarship, grades, evaluation,
roster, billing, liquidation, payment, reporting and account permissions remain at
their existing server-side entry points. Navigation visibility does not replace
authorization. No dependencies or sidebar were added.

The working tree already contained unrelated deployment/dashboard edits. They were
preserved. Existing user-owned test/deployment files were not run or modified.

## 8. Actual static review and compilation

Reviewed hover/tap modality, close-delay cancellation, gap hit area, one-menu state,
portal positioning, outside pointer/focus detection, keyboard entry/exit/Escape,
mobile expansion, active-route aliases, all route names and corresponding gate checks.
Installed Alpine source confirmed `x-teleport` availability; no package was installed.

Actual commands/checks:

| Check | Actual result |
| --- | --- |
| `node --check resources/js/primary-navigation.js` | Exit 0; no syntax errors |
| `node --check resources/js/app.js` | Exit 0; no syntax errors |
| Blade `compileString()` on navigation, then `php -l` on the temporary compiled source | No PHP syntax errors; template not rendered |
| `npm run build` | Exit 0; Vite transformed 65 modules and compiled production assets |
| `git diff --check` on changed navigation sources | No whitespace errors; existing line-ending notices only |
| Task-start source comparison | Existing backend/policy/route/schema/config/test files preserved |

These are source/compilation results, not functional test passes. No PHPUnit, Pest,
Artisan tests, browser automation, manual testing, role login, navigation click or
sample-record/database action was executed.

## 9. Setup and manual verification — NOT EXECUTED

Deploy the changed assets using the existing `npm run build` command; it was already
executed locally. No new dependency, database command or migration is required.
Use the normal deployment procedure if compiled Blade views are cached.

The developer should verify on controlled development accounts/data:

1. Sign in with an existing Coordinator or Administrator account. Confirm the same
   CFCI horizontal bar and the five primary staff items, filtered by authorization.
2. Without clicking, hover Information Registry. Expect its child list immediately
   below the trigger. Move slowly/diagonally through the gap to Semester/Roster and
   down the list; expect no premature closure. Move away from both areas; expect
   closure after the short delay.
3. Hover Registry, then Billing and Validation, then Liquidation and Payment. Expect
   one panel at a time. Hover/click Dashboard or Reports; expect normal direct links.
4. Inspect hover red highlighting, active-parent indication on each actual child
   module, white rounded panels and chevrons. Scroll a narrow desktop navigation
   bar and the page; expect aligned panels or closure when their trigger disappears,
   without content shift or dropdown clipping.
5. Follow authorized program, course, scholar, batch/roster, year/semester, evaluation,
   verification, billing, financial and report links. Expect the existing modules and
   protected routes. Batch/Roster and Year/Semester intentionally share existing modules.
6. Inspect Reason for Delisting. Expect unavailable text, no clickable URL/action,
   and no new registry form. Student accounts should remain visible only to existing
   authorized provisioning roles.
7. Use Tab to focus a desktop parent; Enter/Space/Down/Up to enter its children;
   Up/Down/Home/End within the child list; Shift+Tab/Tab at its edges; Left/Right
   among parents; and Escape. Expect visible focus and correct expanded states,
   no focus lost to the body portal and no immediate reopening after Escape.
8. On a wide touch device, tap parents twice to open/close. Expect no prerequisite
   hover or double-toggle caused by synthesized pointer/focus events.
9. At tablet/mobile widths, open the original navigation button and tap parent groups.
   Expect inline children, only one expanded group, vertical scrolling where needed,
   child-selection closure and Escape focus restoration. No sidebar should appear.
10. Check Registrar, supported legacy Finance/Viewer and Student-only accounts against
    their existing permissions. Unauthorized items must remain hidden; Student-only
    users retain My profile. Direct protected requests must still be controlled by
    the unchanged backend authorization, independently of menu visibility.
11. Confirm the user menu, My student profile where authorized and CSRF-protected
    logout still work. Verify ordinary scholarship/academic/financial/report forms
    remain unchanged after navigating to their existing pages.
12. Inspect labels/expanded states with assistive technology and verify zoom, short
    viewport heights and long child lists. Native application dialogs should remain
    above normal navigation; hovering must never submit or alter records.

All steps have actual status **NOT TESTED**. They are instructions for the developer,
not reports of executed navigation behavior.

## 10. Remaining limitations

- Hover geometry, timers, hybrid touch input, role-specific rendering, keyboard/
  assistive technology behavior and cross-browser responsiveness remain pending
  developer verification as explicitly requested.
- Disclosure controls require the existing JavaScript/Alpine assets. No new
  no-JavaScript navigation implementation or dependency was introduced.
- Reason for Delisting intentionally remains unavailable per the user's decision.
  The existing per-scholar assignment closing workflow is unchanged.
- Combined existing registry modules remain combined; navigation aliases do not
  introduce separate Academic Year, Semester, Batch or Roster database entities.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
