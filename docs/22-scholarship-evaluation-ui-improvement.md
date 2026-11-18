# Scholarship Evaluation workspace UI improvement

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Scope and verification restrictions

Reorganized the Scholarship Evaluation detail page into seven tabs with a compact
header, saved-data progress indicators, grouped compliance inputs, evidence upload
dialog, activation confirmation and readable retained history.

Reviewed the REC-01 implementation and the subsequent UI/validation references:

- `docs/19-academic-grade-computation-and-validation.md`
- `docs/20-academic-subject-grades-ui-improvement.md`
- `docs/21-academic-policy-conditional-validation-fix.md`

No automated tests were created/executed, and no manual/browser functional testing
was performed. No fixtures, scholar records, academic records, policies, decisions,
awards, uploads, imports, migrations, resets or seeds were created/executed. Source
inspection, syntax checks, formatting, static Blade compilation and asset builds
were the only verification performed. Runtime behavior is for developer verification.

## 2. Existing interface and inspected architecture

The previous `evaluations/show.blade.php` placed validation context, semester policy,
Registrar sources, grades/summary, private evidence, all compliance inputs, school
history, external decision records/form and activation in one long sequence. It was
hard to find a task, distinguish its prerequisites or compare saved state with pending
input. History exposed large raw JSON blocks. Evidence/external forms shared field
names such as `certificate` and `verified`, requiring careful recovery after errors.

Inspected the existing evaluation routes/controller, FormRequests, application/run/
assignment/approval/document models, application policy, `OfficialRuleCatalog`,
`EligibilityEvaluator`, `SchoolGradingPolicy`, `AcademicGradeComputation`,
`EvaluateApplication`, `RecordExternalApproval`, `ActivateAward`, `PrivateEvidence`,
academic components, table enhancement and existing Alpine/native dialog patterns.

Existing permissions remain:

- Administrator, Coordinator and Registrar can view evaluations.
- Administrator/Coordinator can evaluate and manage evidence/authority decisions/
  activation, subject to the existing non-withdrawn application check.
- EDUKAR keeps its existing restricted assessment scope and cannot upload/review
  ordinary evaluation evidence through these forms.
- No navigation or authorization rule outside this page changed.

## 3. Workspace structure and navigation

| Tab / `tab` query value | Contents |
| --- | --- |
| Overview / `overview` | Saved evaluation context, policy/decision states, outstanding requirements and latest recorded checks |
| Academic records / `academic-records` | Active/source periods, Registrar sources/revisions/certificates, academic requirements, existing subject table and compact grade summary |
| School compliance / `school-compliance` | Official semester confirmation and grouped assessment form |
| Supporting evidence / `supporting-evidence` | Evidence table, private download/review actions and Add evidence dialog |
| External approval / `external-approval` | Retained authority decisions and separate compact Record external decision form |
| Award activation / `award-activation` | Existing assignment state, prerequisite checklist and explicit confirmation dialog |
| History / `history` | School runs, source/policy snapshots, external decisions and actual assignment/activation metadata/audit records |

The normal path is **Evaluations → Open assessment**. Overview is the default when
no valid tab is specified. Direct links use the existing detail route with a query
parameter; no new route is needed. Alpine changes the selected panel using `x-show`,
not conditional DOM destruction. URL history records tab selection and supports
Back/Forward without recreating forms. Invalid tab names fall back to Overview.

## 4. Header and compact academic summary

The existing page heading provides scholar name, program code, evaluation academic
year/semester and Back to evaluations. The workspace header adds student number,
course/year where recorded, full scholarship program name, assignment status, latest
school decision and a reassessment indicator based on `EvaluationRun::isCurrent()`.

Its compact grid shows recorded enrollment/verification, source-period Registrar
standing/verification, provisional percentage GWA, certified GWA usable for the
evaluation, configured minimum and academic requirement result. Draft/unconfigured
academic policies remain visibly unconfirmed. Source-preview provisional values do
not become certified evidence. Historical-period and missing-upgrade notices remain
visible outside the tabs.

## 5. Progress and outstanding requirements

`app/View/EvaluationWorkspace.php` is a new read-only presentation adapter. It reuses
existing grade summaries, the controller's academic check, `EvaluationRun::isCurrent()`,
the existing catalog and `PrivateEvidence::usable()`. It makes no database writes,
does not run a new eligibility assessment and never authorizes activation.

| Stage | Presentation derives from |
| --- | --- |
| Academic verification | Existing academic check: pass → Completed; fail → Blocked; review → Pending review |
| School compliance | No run → Not started; failed currentness → Requires reassessment; current eligible passed/qualified decision with matching rules/evidence → Completed; other current decision → Blocked |
| External approval | No semester record → Not started; matching approved authority/award/period/dates/usable evidence → Completed; incomplete Approved record → Pending review; rejected/revoked → Blocked |
| Award activation | Existing active assignment → Completed; pending assignment with all displayed prerequisites → Pending review for explicit activation; otherwise Blocked |

These are visual descriptions, not new persisted statuses. Each stage has an
expandable explanation and link to its tab. Opening a tab never completes a stage.
No completion percentage or tab event changes a school decision/award.

Overview identifies source/context issues, missing verified enrollment/grades,
unconfirmed academic/official policies, required certified GWA, existing academic
review reasons, missing usable evidence, school reassessment, external approval and
conflicting active awards. Recorded check reasons are reused rather than inventing
new academic requirements. Detailed explanations expand on demand. Evidence needs
use saved review selections, not unsaved form edits. For each evidence type, only
the latest upload is considered; an older accepted upload is not substituted.

## 6. Academic records tab

- Validation context shows active period, source semester and school policy reference.
- A compact source table links the actual latest enrollment/grading revisions to
  the existing academic detail page, revision history and private certificates.
- The existing academic evaluation component retains its result/reason and authorized
  program-policy link. Additional expandable details show configured academic flags,
  failed-limit approval/manual-review reference and INC/dropped handling.
- The original subject table is reused with all component/reported/computed grades,
  unrounded averages, results, grade status/reconciliation and remarks. Its existing
  sorting and below-table page-size/pagination enhancement remains in place.
- An optional compact mode of the existing grade summary uses a responsive grid for
  counts, recorded/included units and provisional/certified GWA. Calculation explanation
  is expandable; existing warnings and exclusion-reason access are preserved.
- Equivalent values remain outside evaluation calculations/tables. Any historical
  Equivalent information remains available separately on its academic source detail.

The new compact mode defaults off for other callers, preserving their existing
presentation. No average, 75% threshold, rounding comparison, GWA inclusion, standing,
certification, source selection or academic revision rule was changed.

## 7. School compliance grouping and indicators

The existing forms are organized into eight accordion groups:

| Group | Existing fields |
| --- | --- |
| Official semester rules | Separate `confirm_policy` operation and recorded policy reference/confirmation |
| Basic eligibility | `filipino`, `first_degree`, with both source references |
| Institutional and academic compliance | `recognized_program`, `retention`, `discipline`, `authenticity`, with source references |
| Enrollment and academic history | `attendance`, `continuing_award`, `reporting`, their sources, `returning`, `transferee` |
| Other national financial assistance | `permitted_aid`, its source and `smart_same_semester` |
| Special beneficiary conditions | `legacy_pnsl_transfer`, `claiming_pwd`, `special_case` |
| Duration and approved exceptions | `duration_review`, `duration_basis`, `exceptions_reviewed` |
| Evaluator notes and submission | Existing `evaluator_notes` and a clear final evaluation action |

`applicant_type` stays visible before the groups. `submission_revision` and
`previous_run` are retained as hidden concurrency fields. Every existing fact still
submits as `facts[key]`, with its independent `fact_sources[key]` source reference.
Existing selectable values and maximum lengths are preserved.

Group badges describe actual entered values or server errors: Pending review,
Entered — evaluate to verify, Validation errors, or No applicable fields. They never
claim that entered values have been independently verified or approved. Backend
errors automatically expand affected groups. An unchecked exceptions checkbox is
restored as unchecked after a rejected submission, without falling back to the last
run's checked value. The Evaluate and record school decision action stays outside
the accordions, visible at the bottom of its own form.

## 8. Existing program-specific applicability

Visibility uses only existing `OfficialRuleCatalog::requiredFacts()` mappings for
new/continuing categories when the repository has an applicable catalog entry.
The backend applies TES-only `legacy_pnsl_transfer`/`claiming_pwd` checks and TDP-only
`smart_same_semester`; the UI follows those same program distinctions.

Nonapplicable inputs remain mounted, retain their values and submit through the
unchanged request. They are hidden from the primary groups and identified in the
Not applicable section. No silent Yes/No/Not Applicable value is inserted. The
unchanged evaluator ignores them for that program/category, preventing their retained
values from determining an inapplicable check. Changing category shows newly applicable
fields again and asks the evaluator to review retained values.

Any field with a backend validation error remains visible for correction even if
its applicability would otherwise hide it. The backend still validates supplied
input formats, including unused retained fields.

For COSCHO/other unsupported programs or an unmapped academic period, no approved
applicability matrix is assumed: all existing inputs remain organized and available.
The existing missing-rule review outcome remains. EDUKAR retains its original
academic-review/notes-only school form and provider-scope warning. Actual COSCHO or
other unimplemented policy mappings remain unresolved business scope; this UI does
not supply them.

## 9. Supporting evidence

The evidence table shows type, filename, actual review status, reviewer, uploader,
upload timestamp, existing review notes and private authorized actions. Academic
grade certificates stay separate, with a link to Academic records.

Add evidence opens a native dialog reusing the existing subject-dialog layout styles.
It preserves type, `certificate` file input, optional `verified` acknowledgement,
submission revision, multipart POST and existing PDF/JPG/PNG/2 MB restrictions.
Closing the dialog retains its mounted values and selected file. It does not upload
or save anything until Upload evidence is submitted.

Pending evidence retains separate PATCH review forms with required `review_notes`
and accepted/rejected submit values. After rejection, that exact document's review
form is displayed above the table so sorting or pagination cannot hide its errors.
It is not duplicated inside the table. Reviewed evidence remains immutable under
the original endpoint rules; replacement uses the existing upload operation.

Private download routes are unchanged. No public storage path/URL was introduced.
An Accepted metadata badge alone does not establish usable evidence; existing file/
checksum/reviewer checks still determine readiness. Missing/unreadable evidence is
never presented as satisfying a prerequisite.

## 10. External approval and activation

External approval displays retained authority decisions in a compact table with
decision, authority/reference, date, validity, recorder and private evidence links.
Its separate expandable form preserves `previous_approval`, `authority`, `decision`,
`reference`, `decided_on`, `valid_from`, `valid_until`, `certificate`,
`verification_notes` and required `verified`. The selected decision now also recovers
from its own old input after validation rejection.

Activation displays the current assignment plus eight read-only prerequisite groups:
actor permission, pending assignment, active scholar/program, free active-award slot,
current eligible school decision, exact external authority/award/semester match,
applicable decision/validity dates and usable external evidence. The presentation
mirrors the existing activation guards and reuses currentness/file checks. It can
become stale and is never an authorization boundary.

Activate award is disabled when a known prerequisite is unmet. For an eligible pending
assignment, clicking it opens an explicit confirmation naming the scholar, program,
period, evaluation run and approval reference. Only its final Activate award button
submits the original endpoint with fresh `evaluation_id` and `approval_id`.
The unchanged `ActivateAward` transaction rechecks everything under its original
locks/authorization and unique active-award constraint. There is no automatic activation.
Already-active assignments show their actual state and explain why new-activation
prerequisites need not be satisfied again; their activation references remain in History.

## 11. History presentation

History includes every loaded school run, outcome/school decision, actor/date,
currentness indicator, check reasons and actual referenced academic revisions.
Inputs and rule/policy snapshots render as readable, escaped recursive label/value
tables in expandable sections. Original snapshot JSON remains accessible in a further
collapsed section; it is no longer the default history presentation.

External decision history is reused through a shared partial. Assignment history
shows only stored status, activation time/actor/evaluation/approval references,
status/closure reasons and closer. Up to 50 actual assignment audit events are
queried by assignment subject type and ID, displaying recorded action, actor name,
timestamp and reason. No synthetic timeline entry or historical rewrite is created.

## 12. Independent form and endpoint compatibility

All original routes, methods, CSRF directives, validation, authorization, services,
transactions, audits and success messages remain. Forms are siblings, including
the two dialog forms; no form is nested inside another form.

| Operation | Existing route name / HTTP method | Relevant tab after success |
| --- | --- | --- |
| Official semester confirmation | `evaluations.policy` / POST | School compliance |
| School evaluation | `evaluations.run` / POST | School compliance |
| Evaluation evidence upload | `evaluations.evidence` / multipart POST | Supporting evidence |
| Evidence review | `evaluations.evidence.review` / PATCH via POST `_method` | Supporting evidence |
| External decision | `evaluations.external` / multipart POST | External approval |
| Award activation | `evaluations.activate` / POST | Award activation |
| Private document download | `evaluations.evidence.download` / GET | Existing protected download |

The controller's write paths changed only their success redirect targets to include
the relevant tab. Their business operations/messages remain unchanged. The show path
adds presentation data and eager loads existing display relationships. No route or
request class was changed.

Presentation-only `_workspace_form` and review `_workspace_document` inputs identify
the failed form/document for old-input recovery. They are not used for authorization
and are not part of validated business data/model writes. Direct/legacy requests
without a hint use conservative error/input-key fallbacks for tab selection.

## 13. Form state and validation recovery

- Panels and accordions keep their inputs mounted, preserving pending values/files
  during tab switches. No local/session storage, URL-encoded private form content or
  background upload is used. Only the tab name and existing table state enter the URL.
- Dirty forms trigger the browser's normal leave-page warning. Submitting one form
  while another contains unsaved changes explicitly asks whether to discard those
  other changes, since each normal submission reloads the page. Cancel preserves
  the pending DOM state. This does not combine the independent submissions.
- A form restored after backend rejection is also treated as unsaved, so its recovered
  values receive the same navigation/discard warning before resubmission.
- Backend errors select the relevant tab, expand affected groups, retain that form's
  old input and focus its first invalid field or error summary. The page-level error
  banner remains. Bracketed fact/source inputs use dotted error keys correctly.
- The evidence dialog reopens after failed upload and includes its own error summary.
  Evidence and external file/verification fields use form-specific errors and old input,
  preventing cross-population of identical field names.
- A rejected review's notes remain associated with its exact document ID. A document
  concurrently reviewed by another actor cannot be edited through a recovery form.
- File inputs cannot be restored after a server reload; both upload forms explicitly
  request file reselection. They are retained across local tab/dialog interactions.
- Historical/decision IDs are server-rendered from current data, preserving the
  existing stale-submission detection rather than trusting posted old IDs.

## 14. Accessibility and responsive behavior

Uses the existing school palette, typography, record controls/cards/badges, native
details and Alpine. Changes are scoped to `.evaluation-workspace` or the optional
compact summary class. Important migration/historical-context warnings stay visible.

Tabs have tablist/tab/tabpanel relationships, active state and roving tab focus.
Left/Right/Home/End keys navigate the tabs. Mobile tabs scroll horizontally; content
grids collapse to fewer columns or one column, and large tables retain local scrolling.
Accordions use keyboard-accessible native details/summary and textual review indicators.

Evidence and activation dialogs use native modal background inertness, accessible
titles/descriptions, explicit focus containment, Escape/Cancel handling, body scroll
locking and return focus. Browser-history tab changes close an open dialog without
resetting its inputs. Focus moves to the selected tab if the original trigger is
no longer visible. Activation initially focuses Cancel. Status meaning is provided
by text, not only color. No additional animation or dependency was added.

These properties were inspected in source only; keyboard/device/assistive technology
behavior has not been exercised.

## 15. Files created or modified

Created:

- `app/View/EvaluationWorkspace.php`
- `resources/js/evaluation-workspace.js`
- `resources/views/components/evaluation-fact.blade.php`
- `resources/views/components/evaluation-snapshot.blade.php`
- `resources/views/evaluations/partials/overview.blade.php`
- `resources/views/evaluations/partials/academic-records.blade.php`
- `resources/views/evaluations/partials/school-compliance.blade.php`
- `resources/views/evaluations/partials/supporting-evidence.blade.php`
- `resources/views/evaluations/partials/evidence-review.blade.php`
- `resources/views/evaluations/partials/external-approval.blade.php`
- `resources/views/evaluations/partials/external-history.blade.php`
- `resources/views/evaluations/partials/award-activation.blade.php`
- `resources/views/evaluations/partials/history.blade.php`
- `docs/22-scholarship-evaluation-ui-improvement.md`

Modified:

- `app/Http/Controllers/ScholarshipEvaluationController.php`
- `resources/views/evaluations/show.blade.php`
- `resources/views/components/academic-grade-summary.blade.php`
- `resources/views/components/reported-grades-table.blade.php`
- `resources/js/app.js`
- `resources/css/app.css`

No dependencies, schema changes, migrations or record repairs were introduced.
No business action/service, model, policy, FormRequest, route, academic calculation,
program policy, account, authentication, email, billing or existing test source changed.
Local ignored production assets were regenerated by the build.

## 16. Actual static checks and required commands

Actual checks executed:

| Check | Actual result |
| --- | --- |
| `php vendor/bin/pint app/View/EvaluationWorkspace.php app/Http/Controllers/ScholarshipEvaluationController.php` | Completed; formatted the presenter |
| `php -l app/View/EvaluationWorkspace.php` | No syntax errors |
| `php -l app/Http/Controllers/ScholarshipEvaluationController.php` | No syntax errors |
| `node --check resources/js/evaluation-workspace.js` | Exit 0; no syntax errors |
| `node --check resources/js/app.js` | Exit 0; no syntax errors |
| Static Blade `compileString()` and `php -l` on temporary compiled sources | 14 changed/new templates compiled; no syntax errors; no rendering |
| `npm run build` | Exit 0; Vite 7.3.6 transformed 64 modules and compiled production assets |
| `git diff --check` | No whitespace errors; existing CRLF-to-LF notice only |
| Task-start source hash comparison | Zero changes to existing tests, business actions/services, requests, policies, models, routes, schema and configuration |

Reviewed original versus final form fields, endpoints/methods, ownership/role checks,
same-name input/error isolation, category applicability, currentness and activation
guards, old-input restoration, file limitations, history immutability and scope of CSS.
Static review also corrected failed-review visibility under table pagination, Boolean
No handling for conditional evidence questions, hidden invalid field recovery, unchecked
exception restoration, conservative checkbox recovery, autofocus priority and dialog
handling during browser-history navigation.

These results are compilation/source evidence, not functional test passes. No PHPUnit,
Pest, Artisan test command, browser session, HTTP simulation, fixture or migration was
run. No screenshots were taken.

Deploy the changed assets with the existing command:

```powershell
npm run build
```

The local build was already executed. No package installation or database command is
required by this UI change. Follow the normal deployment procedure for cached views.
Existing REC-01 schema/readiness prerequisites remain in force; no migration was run.

## 17. Developer manual verification guide — ALL NOT TESTED

Use an isolated development database, existing authorized development accounts and
fictional scholar/assignment/period fixtures prepared by the developer through existing
approved workflows. No fixtures or login credentials were created for this report.
Do not use real student decisions or activate real awards to perform these checks.

1. Sign in as Coordinator or Administrator. Open **Evaluations → Open assessment** on
   a fictional record. Expect Overview, scholar/student ID/program/period, actual saved
   academic/assignment states and four status indicators. Opening every tab must not
   complete a stage or change any database record.
2. Select each tab. Use Left/Right/Home/End on its tab bar; inspect the selected state
   and panel relationship. Reload a direct `?tab=academic-records` detail URL, then use
   Back/Forward between tabs. Expect the correct tab; an invalid query value should
   fall back to Overview.
3. Enter fictional evaluator notes, for example `Development review only — no actual
   award decision`. Change compliance selections and sources, switch tabs and return.
   Expect all pending values retained. Select a controlled PDF under Add evidence,
   close the modal and return later; expect the same local selection before reload.
4. With two forms edited, attempt to submit one. Cancel the warning; expect neither
   form lost. Attempt to leave/reload with pending edits; expect the browser's normal
   warning where supported. If proceeding with a real form submission, expect an
   explicit warning that the other independent form's unsaved data will be discarded.
5. In Academic records, compare periods, source revision links, reported/computed
   averages and results with the existing fictional Registrar record. Check grade
   sorting, below-table row sizes, totals, provisional/certified GWA, exclusions and
   expandable formula limitations. Do not copy provisional GWA as certified evidence.
6. Verify TDP and TES new/continuing fixtures against the repository mappings. Expect
   only applicable facts in primary groups, ignored fields listed separately and no
   automatic Yes/No/Not Applicable assignment. Switch beneficiary category and review
   retained values. For an unsupported program/period, expect retained fields and an
   applicability warning; EDUKAR must keep notes/academic review scope only.
7. Enter Yes without a source, Unverified, No, unknown conditional evidence flags and
   incomplete duration review. Expect review/input badges without automatic approval.
   Provide fictional valid fields and sources; Entered — evaluate to verify still
   must not mean eligible. Confirm the original official semester policy separately
   when applicable; expect return to School compliance and unchanged historical runs.
8. Submit a controlled invalid compliance request (for example use development tools
   to submit an unsupported fact value). Expect the relevant tab/group opened, an
   adjacent server error, old input retained and no new evaluation. Repeat on a
   normally hidden inapplicable field; expect it visible for correction. Do not alter
   real records or weaken the validator to conduct these checks.
9. Upload a controlled valid private PDF/JPG/PNG, then a wrong type or file over 2 MB.
   Expect the original upload rules, accurate success/error, upload modal recovery,
   retained type/checkbox values and a file-reselection notice after rejection.
   Confirm files are available only through protected download actions.
10. Review a pending fictional document. Submit empty notes; expect that exact failed
    review above the evidence table, even if the document would be on another page.
    Correct notes and accept/reject; expect the original PATCH behavior, return to
    Supporting evidence and the existing need for reevaluation. Already reviewed
    documents must not become editable.
11. Record a fictional external decision with its source file, reference, authority,
    decision date, any stated validity dates, explanation and required verification.
    Try missing evidence, wrong authority, invalid/future dates and unchecked required
    verification. Expect existing validation; the right form/tab recovers without
    leaking its file/checkbox errors into Add evidence. A valid save returns to
    External approval and does not activate the award.
12. Review Award activation before prerequisites are met. Expect a disabled action and
    accurate unmet checklist. On a developer-prepared fully valid isolated fixture,
    expect a confirmation dialog. Cancel must perform no write. Confirm should invoke
    the existing backend activation operation with the displayed evaluation/approval
    IDs and return to Award activation. Inspect actual active status/references in
    History. Do not perform this against a real award.
13. Prepare separate isolated stale-policy/grade-correction/evidence-change and
    conflicting-active-award fixtures using approved existing workflows. Expect current
    reassessment indicators and blocked activation; direct/stale activation requests
    must still fail under the original backend guards and one-active-award constraint.
14. Inspect History. Expect saved run decisions/reasons/source links, readable snapshots,
    optional original JSON, actual external records and only stored assignment events.
    Navigating/opening details must not append audit events or rewrite prior snapshots.
15. Sign in as Registrar. Expect view access with write actions unavailable. A Student
    or other unauthorized role must remain denied by the existing routes/actions,
    including direct requests; hidden controls alone must not be the protection.
16. Inspect narrow/mobile and desktop widths, table/tab scrolling, source names and
    long references. Tab through both dialogs; verify focus stays inside, Escape and
    Cancel restore focus, activation initially focuses Cancel and background scrolling
    is restored. Check labels, textual statuses and errors with assistive technology
    where available.

Every manual step above has actual status **NOT TESTED**. Expected outcomes are
derived from static source review, not reports of completed operations.

## 18. Known limitations and final status

- All interactive behavior, form submissions, storage/SQL operations, concurrency,
  mobile layouts and accessibility remain pending developer functional verification.
- The page requires the existing JavaScript/Alpine environment for in-place tabs,
  dialogs and unsaved-change handling. Without it, ordinary tab links reload the
  server-selected panel and advanced dialog actions are unavailable.
- DOM preservation lasts only within the current page. Server reloads restore the
  submitted form's old input, not unrelated unsaved forms; the user is warned before
  discarding those forms. Browser-selected files require reselection after reload.
- Browser leave-page warnings depend on browser support/user interaction. This UI
  introduces neither autosave nor persistent client-side storage of private data.
- Read-only file/checksum/currentness checks can add storage I/O to detail rendering.
  A displayed positive prerequisite can become stale; the backend remains authoritative.
- Assignment audit history displays up to 50 actual events. Other historical records
  remain accessible through existing relationships/screens; no synthetic events exist.
- COSCHO/other missing official program mappings, official GWA approval/rounding and
  actual unconfigured school requirements remain outside this presentation task.
- No business action, migration, secret/email configuration or real record was changed
  as part of implementation verification.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
