# 12 - Browser-based manual system testing

Prepared: 2026-11-13, Asia/Manila. Scope: current implementation after HI-01, before CR-01/CR-02. This is a procedure to execute, **not a record of completed browser tests**.

Read with [progress](../sms/progress.md), [final readiness audit](09-final-system-readiness-audit.md) and [billing roster integration](../sms/billing-roster-integration.md). The audit describes its earlier application commit; Task 30 subsequently implements billing roster linkage. Approved grading interpretation (CR-01) and final Form 2/Form 3 classification (CR-02) remain unresolved.

## Preconditions and result recording

1. Use an already prepared, separate UAT environment running the current application. Obtain its base URL from the person hosting it. All paths below are relative to that URL; `http://127.0.0.1:8000` is an example, not confirmation that a server is running there.
2. Perform creation, corrections and deliberately invalid submissions **only on new fictional records in that UAT environment**. If only the school database is available, run authorized read-only viewing tests and mark the creation/change tests **Blocked: no isolated UAT environment**. Do not reset the school database, edit an existing student's information, change financial history or delete test history.
3. Obtain existing authorized accounts for **School Scholarship Coordinator** and **Registrar Staff**. Example account labels are `uat-coordinator@example.test` and `uat-registrar@example.test`; these are fictional placeholders, not working credentials. Use privately supplied credentials, never put passwords in the test log. The last documented configured-database inventory had no operational accounts; verify availability rather than assuming provisioning occurred. Missing accounts mean the affected role tests are Blocked. Do not change roles or create accounts during this checklist.
4. Use separate browser profiles for the two accounts, or explicitly log out before switching. Two ordinary windows in one browser profile share login cookies. Confirm the displayed role in the profile menu. The retained **Test Administrator** is not a substitute for testing both operational roles.
5. Enable JavaScript for the existing Alpine controls. Record browser/version, viewport, base URL/environment, application revision if known, tester, role and time. Record the actual generated database IDs from page URLs; do not assume any ID is `1`.
6. Do not run database resets, seeds, synthetic demo policies, SQL edits, grading mocks or classification overrides to reach later screens. Do not change the global academic validation context or existing approved rates/budgets. This guide does not execute payments, approve external scholarship decisions or create signed payment evidence.

Use these verdicts for **each assertion**, splitting a test into sub-results when necessary:

| Result | Meaning |
| --- | --- |
| Pass | Performed the stated action and observed the exact expected saved/read-only result or expected rejection. A rejected invalid request is a passing negative test. |
| Fail | Preconditions were satisfied but observed behavior contradicts the expected result, including an unexpected 500, unauthorized success, incorrect count or unintended saved record. Capture evidence and stop dependent actions. |
| Blocked | Cannot exercise the assertion because an account, environment, prerequisite record, approved policy or final classification is unavailable. An earlier error does not prove a later guard worked. |

All cases initially have **Not run** status. For each execution record: case/assertion, role, input tag, actual URL and IDs, actions, expected/actual result, verdict, screenshot/export reference and blocker/defect. Refresh/reopen a saved record to check persistence, not just a success message. Browser-required-field feedback verifies client validation only; it does not establish server validation. Expected 403 means the access-denied page; a guest normally redirects to login. POST routes below are submitted using page buttons, not entered into the address bar.

## Fictional dataset and route identifiers

Use run tag **M261113A**. If any of these identifiers already exist, choose a new tag such as M261113B and replace it throughout **before starting**. Do not reuse or update the matching saved records.

| Alias | Fictional input / intended use |
| --- | --- |
| P1 | Code `UAT_M261113A_P1`; name `UAT M261113A Scholarship A - fictional`; Active availability; reference amount/duration blank; notes `Fictional browser test only; no real award or provider authority.` |
| P2 | Code `UAT_M261113A_P2`; name `UAT M261113A Scholarship B - fictional`; same remaining values. Tests wrong-program scope. |
| T1 / T2 | Registered academic periods `2026-2027 / 1` and `2026-2027 / 2`. Reuse their existing identities without editing them. Optional missing-period setup is below. These are test selections, not the school's configured active/source period. |
| RA1 | P1/T1 roster name `UAT-M261113A-A1`. Main roster. |
| RA2 | P1/T1 roster name `UAT-M261113A-A2`. Tests a second roster and distinct counts. |
| RA-T2 | P1/T2 roster name `UAT-M261113A-A-T2`. Tests another period. |
| RB1 | P2/T1 roster name `UAT-M261113A-B1`. Tests another program. |
| S1 | Student ID `UAT-M261113A-001`; given name `Ari`; last name `ManualTest`. P1 pending assignment; RA1 and RA2 membership; verified enrolled. |
| S2 | Student ID `UAT-M261113A-002`; given name `Bea`; last name `ManualTest`. P1 pending assignment; RA1 membership; verified not enrolled. |
| S3 | Student ID `UAT-M261113A-003`; given name `Cai`; last name `ManualTest`. P1 pending assignment; RA1 membership; unknown enrollment. |
| S4 | Student ID `UAT-M261113A-004`; given name `Dara`; last name `ManualTest`. P1 pending assignment; **no roster membership**. Negative control. |
| BA1 / BA2 | Two financial draft headers created later for P1/T1/RA1. Preparation references `UAT-M261113A-BILL-1` and `UAT-M261113A-BILL-2`. Save their actual IDs and displayed batch numbers. |
| Rate | `1000.00` PHP, entered only for fictional P1/T1 in isolated UAT. Reference `UAT-M261113A fictional rate for UI testing only`. This is not an approved real scholarship policy, budget or cash receipt. |

Leave scholar email, phone, government IDs, family details and other optional profile fields blank. Choose **Active** student record status. Profile incompleteness is expected; it does not prevent registration. Student record Active, program availability Active and scholarship assignment Pending are different states. New custom programs have no automatically supplied eligibility profile: use them for CRUD/roster/draft tests, not proof of academic qualification.

In paths/query examples, `{P1}`, `{T1}`, `{RA1}`, `{S1}`, `{BA1}` etc. mean the **database IDs recorded during this run**, not the literal strings above. `{A1}` means S1's scholarship assignment ID; `{V1}` means an academic revision ID. Use the numeric student record ID in `/scholars/{S1}`, not its institutional Student ID.

## 1. Login and operational roles

### L01 - Login, invalid password, dashboard and logout

- **Page/routes:** GET `/login`, POST `/login`, GET `/dashboard`, POST `/logout` through the profile menu.
- **Role:** run separately as Coordinator and Registrar.
- **Data:** privately supplied UAT account; one deliberately incorrect password, then the correct password. Do not repeatedly trigger login throttling.
- **Actions:** In a fresh session open `/dashboard` and confirm redirection to `/login`. Enter the correct email with a wrong password and click **Log in**. Confirm an authentication error and no authenticated dashboard. Enter correct credentials; open `/dashboard` and inspect the displayed role and existing navigation. Open the profile menu, choose **Log out**, then explicitly revisit `/dashboard`.
- **Expected:** bad password rejected; valid account authenticates; dashboard/navigation render; role is correct; fresh protected navigation after logout returns to login. Do not use a cached Back-button image alone to judge logout.
- **Verdict:** Pass for each observed result; Fail for unauthorized authentication, wrong role or inaccessible valid login despite working account/setup; Blocked for unavailable account/server. Email verification enforcement is not established by this test.

### L02 - Enforce role separation using actual pages

- **Pages:** `/scholarship-programs/create`, `/scholars/create`, `/scholars/{S1}/assignments/create`, `/scholarship-batches`, `/academic-verifications/create`, `/billing-batches`, `/scholarship-reports/grants`.
- **Roles/data:** both supplied accounts; new S1 after S01 for the assignment URL. No form is saved in this access check.
- **Actions:** Log in as each role, open these exact URLs and compare controls with the matrix. Check direct URLs as well as hidden links. Revisit after S1 exists where needed.
- **Expected:**

| Capability/page | Coordinator | Registrar |
| --- | --- | --- |
| Add scholar `/scholars/create` | Allowed | Allowed |
| Add scholarship program `/scholarship-programs/create` | Allowed | 403 |
| Create pending assignment `/scholars/{S1}/assignments/create` | Allowed | 403 |
| View `/scholarship-batches` | Allowed, **Create semester roster** present | Allowed, create form absent |
| Write Registrar facts `/academic-verifications/create` | 403 | Allowed |
| Read `/academic-verifications` and existing revisions | Allowed | Allowed |
| Prepare financial draft `/billing-batches` | Allowed | Allowed |
| Approve initial rate on `/billing-batches` | Form present | Form absent |
| Read all six `/scholarship-reports/{mode}` pages | Allowed | Allowed |

- **Verdict:** Pass when allowed pages and denied controls/403 match; Fail for a contrary access result; Blocked for missing role/account or S1 not yet created. Hidden controls alone do not prove authorization of their POST routes; deeper forged-write coverage remains the automated suite's responsibility.

## 2. Scholarship program and roster creation

### P01 - Confirm academic-period prerequisites

- **Page/routes:** GET `/academic-periods`; if missing, GET `/academic-periods/create`, POST `/academic-periods`.
- **Role/data:** Coordinator; T1 and T2.
- **Actions:** Find each year/semester. Record existing IDs without editing. If missing **in isolated UAT**, use **Add semester**: Academic year `2026-2027`, Semester code `1` or `2`, dates blank, Semester status **Open**, then **Save semester**. Do not save the separate validation-context controls on the listing page.
- **Expected:** two distinct registered identities available to forms; no global active/source-period change. An already registered period does not need recreating.
- **Verdict:** Pass for available correct selections; Fail for wrong persistence/new duplicate identity; Blocked if periods are missing and Coordinator/UAT setup is unavailable. Reusing a period verifies availability, not new-period creation.

### P02 - Create fictional scholarship programs

- **Page/routes:** GET `/scholarship-programs/create`, POST `/scholarship-programs`, GET `/scholarship-programs/{P1}`; repeat for P2.
- **Role/data:** Coordinator; P1/P2 values above.
- **Actions:** Enter code/name, leave school-stated amount and duration blank, select Active availability, enter notes. Leave optional **Billing batch reference program**, **Batch academic year / semester** and **Batch Number** reference unselected. Click **Save program**. Reopen the saved URL/list and record IDs.
- **Expected:** correct catalog entries persist, with unconfirmed reference amount. This does not create a roster, student membership, approved rate or bill. The program form's Batch Number reference is contextual and is not saved as a roster relationship.
- **Verdict:** Pass for persisted values and no unintended roster/bill; Fail for save error with valid inputs, wrong scope or incidental financial creation; Blocked for missing Coordinator/UAT access.

### P03 - Reject duplicate program code

- **Page/routes:** GET `/scholarship-programs/create`, POST `/scholarship-programs`.
- **Role/data:** Coordinator; P1's exact code with name `UAT duplicate attempt`.
- **Actions:** Submit a new form using the already saved P1 code. Search `/scholarship-programs?q=UAT_M261113A_P1` afterwards.
- **Expected:** code validation error; original P1 remains unchanged and there is still one matching catalog entry.
- **Verdict:** Pass for rejection and unchanged original; Fail for duplicate/overwrite; Blocked if P02 did not establish P1. Do not use the Edit page for this test.

### P04 - Create independent semester rosters without a rate

- **Page/routes:** GET/POST `/scholarship-batches`, GET `/scholarship-batches/{RA1}` and corresponding saved roster URLs.
- **Role/data:** Coordinator; RA1, RA2, RA-T2 and RB1.
- **Actions:** On **Semester scholarship rosters**, use **Create semester roster**. Select program and **Academic year / semester**, enter **Roster batch name**, click **Create roster**. Repeat each scope. Before any rate approval, reopen each roster; use listing filters to find P1/T1.
- **Expected:** correct program/period/name persisted, initially no members; P1/T1 listing includes RA1/RA2. No enrollment finding, approved rate or billing item is required or automatically created.
- **Verdict:** Pass for saved scoped, empty rosters; Fail for a rate/eligibility requirement at roster setup, wrong relationship or automatic bill; Blocked for missing program/period/UAT permissions.

### P05 - Reject duplicate roster within the same scope

- **Page/routes:** GET/POST `/scholarship-batches`.
- **Role/data:** Coordinator; exact P1/T1/RA1 name.
- **Actions:** Try to create the same roster again, then filter P1/T1 and reopen RA1.
- **Expected:** duplicate roster error; one RA1 remains. Different roster names RA1/RA2 under P1/T1 are permitted.
- **Verdict:** Pass for rejection; Fail for a duplicate or overwritten roster; Blocked if P04 failed.

## 3. Scholar registration and assignment

### S01 - Register four fictional scholars

- **Page/routes:** GET `/scholars/create`, POST `/scholars`, GET `/scholars/{S1}` etc.; search GET `/scholars?q=UAT-M261113A`.
- **Role/data:** Registrar (Coordinator may repeat with another fresh tag); S1-S4.
- **Actions:** Enter **Student ID**, **Given name / first name**, **Last name**, and Active **record status**. Leave optional fields blank; click **Add scholar**. Repeat for all four, record numeric IDs, reopen each saved record and search by the run tag.
- **Expected:** four new profiles persist; incomplete-profile indication is acceptable. No scholarship assignment, verified enrollment or membership is created merely by saving a profile.
- **Verdict:** Pass for correct distinct saved records; Fail for duplicate identity, changed existing student or mandatory optional-profile fields preventing minimal registration; Blocked without isolated UAT/account access.

### S02 - Reject duplicate institutional Student ID

- **Page/routes:** GET `/scholars/create`, POST `/scholars`.
- **Role/data:** Registrar; S1's Student ID, given name `Duplicate`, last name `ManualTest`, Active record status.
- **Actions:** Submit the new form. Search the original Student ID and reopen S1.
- **Expected:** Student ID uniqueness error; S1's Ari/ManualTest details remain unchanged and no second profile exists.
- **Verdict:** Pass for rejection/preservation; Fail for a duplicate or overwrite; Blocked without S1. Never edit S1 to simulate a second identity.

### S03 - Assign programs before enrollment or billing

- **Page/routes:** GET `/scholars/{S1}/assignments/create`, POST `/scholars/{S1}/assignments`; repeat S2-S4.
- **Role/data:** Coordinator; each scholar, P1, Starting academic semester T1, Assignment status **Pending**, dates/closing semester blank; reason `UAT-M261113A fictional pending assignment`.
- **Actions:** From each new scholar click **Assign scholarship**, enter these values and **Save assignment**. Record each assignment ID from its history/update link. Do this before recording enrollment or configuring a rate.
- **Expected:** P1/T1-start pending assignment retained; no active award, eligibility, provider approval or bill inferred. The UI does not offer activation of a new pending award here.
- **Verdict:** Pass for saved pending assignment; Fail if validation/rate is required just to save pending intent or assignment becomes Active automatically; Blocked for missing account/profile/program/period. Positive award activation remains blocked by unconfirmed prerequisites and is not performed in this guide.

### S04 - Assign exact students to RA1, then verify independent membership

- **Page/routes:** GET `/scholars/{S1}`, POST `/scholars/{S1}/batch-memberships`; repeat S2/S3. Read `/scholarship-batches/{RA1}`.
- **Role/data:** Coordinator; S1-S3's corresponding P1 pending assignment and RA1. Keep S4 unassigned to a roster.
- **Actions:** Under **Semester batch memberships**, choose **Pending or active scholarship assignment**, choose **Semester roster batch** RA1, click **Assign to roster without billing**. Reopen each scholar and RA1.
- **Expected:** RA1 has exactly three members, all with correct assignment/program/period; no academic record or bill is needed. Membership links appear on each scholar. S4 is absent. All remain pending assignments.
- **Verdict:** Pass for persisted correct roster; Fail for missing/extra member, automatic billing or forced enrollment/activation; Blocked if preceding setup failed.

### S05 - Duplicate and incompatible membership rejection

- **Page/routes:** S1 detail and POST `/scholars/{S1}/batch-memberships`.
- **Role/data:** Coordinator; first S1's P1 assignment + RA1 again, then the same assignment + RB1 (P2/T1).
- **Actions:** Repeat the RA1 join, then select RB1 if offered by the current form and submit. Reopen RA1/RB1 and S1's history.
- **Expected:** duplicate membership rejected; P1 assignment cannot join a P2 roster. RA1 still has three members; RB1 stays empty. The form may list several rosters; the server must reject incompatible scope.
- **Verdict:** Pass for each rejection/preserved counts; Fail for duplicate, cross-program membership or changed identity; Blocked for missing setup. Do not label P1/T2 as inherently invalid: a T1-start assignment with no end can legitimately cover T2.

### S06 - Overlapping-roster distinct-count control

- **Page/routes:** S1 detail, POST `/scholars/{S1}/batch-memberships`, GET `/scholarship-batches/{RA2}`.
- **Role/data:** Coordinator; S1's same pending assignment + RA2 (P1/T1).
- **Actions:** Save this additional membership, then inspect RA1 and RA2 separately.
- **Expected:** RA1 still has S1/S2/S3; RA2 has S1 only. Current uniqueness is per roster/student, so this deliberately overlapping test data is permitted. It is a counting test, not a new school assignment policy. S4 still has no membership.
- **Verdict:** Pass for those records/counts; Fail for lost RA1 membership, duplicate within RA2 or incidental bill; Blocked if preceding setup failed. Subsequent whole-program expected counts assume this case was completed.

## 4. Registrar enrollment verification

### E01 - Initial exact-period queue

- **Page/routes:** GET `/scholarship-batches/{RA1}?view=awaiting_verification`.
- **Role/data:** Registrar; RA1 before academic records exist.
- **Actions:** Open RA1, select **Awaiting Registrar enrollment verification**, click **Show members**. Also inspect **All members**.
- **Expected:** S1/S2/S3 await verification with no academic record. S4 is absent because it is not a member. No one is classified as Form 3 just because the source is missing.
- **Verdict:** Pass for all three unresolved members; Fail for omission/false verification/Form 3; Blocked if roster setup or Registrar login is unavailable.

### E02 - Verify enrolled and not-enrolled facts

- **Page/routes:** GET `/academic-verifications/create?student_id={S1}&academic_period_id={T1}`, POST `/academic-verifications`; detail GET `/academic-verifications/{V1}`. Repeat S2.
- **Role/data:** Registrar. S1: Enrollment **Enrolled**, standing **Unverified**, source `UAT-M261113A enrollment source S1 - fictional`. S2: **Not enrolled**, standing **Unverified**, analogous S2 source. Optional course `UAT course - fictional`, year `2`. Leave subject rows empty.
- **Actions:** Check the selected Scholar and Academic period explicitly. Enter facts and source, click **Verify record**. Record each academic ID; reopen detail, then refresh RA1's awaiting/all views. Optional certificate must be a new clearly fictional PDF/JPG/PNG, at most 2 MB, never a real student's document.
- **Expected:** Revision 1 for each exact student/T1 with verification actor/time/source. S1 shows **Verified enrolled**; S2 **Verified not enrolled**. They leave the awaiting queue but remain members. S3 remains awaiting. All form-classification notices stay pending; neither fact activates an award or bills a student.
- **Verdict:** Pass for saved sources and queue behavior; Fail for wrong period/student, missing provenance, removed non-enrolled membership or invented classification; Blocked for missing role/source setup. These are fictional facts, not evidence of real enrollment or approved grade interpretation.

### E03 - Verified standing does not mean verified enrollment

- **Page/routes:** GET `/academic-verifications/create?student_id={S3}&academic_period_id={T1}`, POST `/academic-verifications`, and RA1's awaiting view.
- **Role/data:** Registrar; S3, Enrollment **Unverified**, initially Standing **Unverified**, source `UAT-M261113A S3 standing-only source - fictional`.
- **Actions:** Click **Verify record** with both findings Unverified: expect rejection and no saved verified revision. Then set standing **Passing** only as a manually supplied fictional standing fact; keep enrollment Unverified and click **Verify record**. Do not calculate grades or infer a passing threshold. Reopen the saved academic record and roster queue.
- **Expected:** both-unknown verification rejects. A standing-only verified record is permitted, but S3 remains **Awaiting Registrar verification** for enrollment, remains a member and has pending form classification. This distinction is intentional.
- **Verdict:** Pass for both outcomes; Fail if both-unknown facts become verified or standing alone resolves the enrollment queue/creates Form 3; Blocked if account/setup is missing.

### E04 - New pending correction supersedes favorable enrollment

- **Page/routes:** GET/POST `/academic-verifications/{V1}/correction`; GET `/academic-verifications/{V2}`; PATCH `/academic-verifications/{V2}` through its edit form.
- **Role/data:** Registrar; S1's verified revision 1 only, correction reason `UAT-M261113A correction test - fictional`, revised source `UAT-M261113A S1 revision 2 source`.
- **Actions:** On S1 revision 1 choose **Create correction**, enter the reason/source, set enrollment Unverified, click **Save draft**. Record revision 2's ID. Check revision 1 remains in **Revision history**, and RA1's awaiting queue includes S1 and S3. Open revision 2, choose **Edit / verify draft**, set enrollment Enrolled and click **Verify record** with its source. Reopen both revisions and the roster.
- **Expected:** new pending revision blocks reliance on old verified enrollment without editing the old record. After verifying revision 2, S1 again leaves the queue; S3 remains. Three RA1 members retained. Final classification/stale-classification history is still unavailable under CR-02; do not claim it was verified here.
- **Verdict:** Pass for append-only revisions and latest-source queue changes; Fail for rewritten revision 1, fallback to it while revision 2 is pending, or lost membership; Blocked without the preceding verified new UAT record. Never correct an existing school student's source.

### E05 - Coordinator reads, Registrar writes

- **Page/routes:** GET `/academic-verifications/{V2}`, `/academic-verifications/{V2}/edit`, `/academic-verifications/{V2}/correction`, `/academic-verifications/create`.
- **Role/data:** Coordinator; S1's new verified revision 2.
- **Actions:** Read its source/history. Try the direct create/correction URLs; do not submit any change. Confirm no Registrar write controls appear. A verified revision also cannot be edited as a draft by either role.
- **Expected:** Coordinator can read but cannot create/verify/correct Registrar facts; forbidden pages return 403. Verification is not financial independent-review workflow and does not require a second Registrar account.
- **Verdict:** Pass for read/denial results; Fail for Coordinator write access; Blocked without accounts/new source record.

## 5. Billing-to-roster integration

### B01 - A roster does not supply an approved financial rate

- **Page/routes:** GET/POST `/billing-batches`.
- **Role/data:** Registrar or Coordinator; new P1/T1/RA1, preparation reference `UAT-M261113A-NO-RATE`. Run before B02.
- **Actions:** Under **Prepare a semester allocation batch**, select program P1, Academic year/semester T1 and **Scholarship semester roster** RA1. Click **Create draft allocation batch**.
- **Expected:** creation rejects because no approved rate exists. The existing roster/members remain; no draft financial header is created. A program reference amount would not be sufficient either.
- **Verdict:** Pass for rate rejection and no new header; Fail if an unapproved rate authorizes preparation or a partial header appears; Blocked if this scope already had an approved rate. Do not alter an existing rate to recreate the condition.

### B02 - Configure only the fictional scope's initial rate

- **Page/routes:** GET `/billing-batches`; POST `/billing-batches/terms` using **Configure a scholarship rate for a semester**.
- **Role/data:** Coordinator; P1/T1, `1000.00`, fictional rate reference from the dataset.
- **Actions:** Select the program/period in the initial-rate form, enter the amount/reference, tick the confirmation checkbox and click **Approve initial semester rate**. Reopen the page/scope to confirm the recorded rate. Do not configure a budget or receipt.
- **Expected:** approved financial rate for P1/T1, independent of academic policy, eligibility and external approval; no bill/payment/funds generated. Registrar lacks this approval form.
- **Verdict:** Pass for exact scoped rate; Fail for changed unrelated scope or inferred student qualification/cash; Blocked outside isolated UAT or without Coordinator/setup. This fictional financial configuration is not school policy confirmation.

### B03 - Two linked drafts from one roster

- **Page/routes:** GET/POST `/billing-batches`, GET `/billing-batches/{BA1}` and `{BA2}`.
- **Role/data:** Registrar; P1/T1/RA1, separate preparation references BILL-1/BILL-2.
- **Actions:** Create the first draft, record its ID/number, open its displayed **Semester roster** link and confirm it is RA1. Return to the creation form and repeat with the same roster and the second reference. Refresh both details and listing.
- **Expected:** two distinct financial headers, each linked to the same RA1 and correct program/period. On a fresh P1 these normally display Batch 1/Batch 2; record actual labels. Both are Draft with no allocation items; prepared total is zero. No additional membership, submitted billing, payment or funds are created. RA1 still has three members.
- **Verdict:** Pass for persisted links/multiple drafts; Fail for null/wrong link, one-to-one restriction, shared financial ID or automatic billing; Blocked without rate/roster/account.

### B04 - Compatible options, selection clearing and invalid scope

- **Page/routes:** GET/POST `/billing-batches`, creation form only.
- **Role/data:** Registrar; P1/T1, RA1, RA2, RA-T2 and RB1. Use preparation reference `UAT-M261113A-INVALID-SCOPE` for any rejected attempt.
- **Actions:** Select P1/T1: RA1/RA2 should be selectable, RA-T2/RB1 hidden or disabled. Select RA1, change period to T2: selection must clear, with RA-T2 compatible. Change program to P2: again clear and show only its matching-period options. Do not create extra valid drafts. Try submitting with no roster to inspect browser-required feedback.
- **Optional server check, isolated UAT only:** return to P1/T1 with RA1 selected. Using browser Elements/Inspector, change that selected option's numeric value to RB1's ID, leaving program/period unchanged; submit. Repeat with RA-T2 to test wrong period. This changes only the local page input, not application code. Do not alter CSRF, role, grading, approval or classification fields. Reload afterwards.
- **Expected:** UI clears/enforces compatibility. Empty selection is blocked in the browser. Forged program/period combinations reaching the server produce a roster validation error and no new header; BA1/BA2 remain intact.
- **Verdict:** Pass separately for UI behavior and server rejections actually observed; Fail for selectable incompatible options, stale selection or server acceptance; Blocked for unavailable rosters or optional Inspector test not performed. Native required feedback alone is not proof of server rejection.

### B05 - Candidates, member addition and nonmember limitation

- **Page/routes:** GET `/billing-batches/{BA1}`; **Add an individual scholar** search `?q=UAT-M261113A`; item action POST `/billing-batches/{BA1}/items` exists only when an eligible candidate's button is shown.
- **Role/data:** Registrar; S1-S3 roster members, S4 nonmember; all assignments remain Pending and no semester evaluations/external approvals were created in this guide.
- **Actions:** Open BA1, inspect/search candidates and note any displayed eligibility reason. Confirm no **Add scholar** button permits these unqualified pending students. Read the roster link and classification notice.
- **Expected:** fresh dataset normally has **No unbilled semester validations match this search**, since candidates start from semester evaluations and require exact membership. Enrollment verification alone does not create an evaluation, activation or external approval. No student becomes a billable item.
- **Verdict:** Pass for absence of unauthorized add/payability on this dataset; Fail if a pending/unqualified student can be added. **Positive member addition, nonmember server rejection against an otherwise qualified evaluation, duplicate bill reservation and submit-time member revalidation are Blocked** without the relevant legitimate prerequisites. An empty list cannot prove those separate rules. Do not create guessed qualifications, force Active or edit requests to bypass grading/classification. Existing automated tests are separate evidence, not a manual Pass.

### B06 - Empty submission and CR-02 are distinct checks

- **Page/routes:** GET `/billing-batches/{BA1}`, POST `/billing-batches/{BA1}/submit` via **Validate and submit batch**.
- **Role/data:** Registrar; empty BA1, Submission reference `UAT-M261113A-SUBMIT-NEGATIVE`.
- **Actions:** Read the notice **Final submission remains blocked until approved Form 2/Form 3 templates and current payable classification are implemented**. Submit the empty header with the reference; refresh header/items and check billed report R02.
- **Expected:** rejection for at least one valid scholar/submission prerequisite; header stays Draft with no submitted actor/time or billed amount. The empty-item check occurs **before** the classification check. Therefore this action need not show the explicit CR-02 error.
- **Verdict:** Pass for empty-submission rejection and unchanged financial state; Fail for submission/payment success. **Successful submission and direct exercise of the CR-02 guard after every earlier eligibility check are Blocked on this fresh dataset.** If a separately established, legitimate UAT prerequisite ever reaches that guard, expected rejection is `Final billing submission is blocked: approved Form 2/Form 3 templates and current payable classification are not yet available...`; never override it to complete a test.

### B07 - Existing unlinked history is read-only and remains unknown

- **Page/routes:** GET `/billing-batches/{legacyBillingId}`; `/scholarship-reports/billed` and other financial report modes with recorded-link filters.
- **Role/data:** either role; only an already existing, authorized UAT snapshot containing an unlinked header. Use no real student's records to manufacture this condition.
- **Actions:** Open the header, inspect **No recorded roster link**, recorded status and existing lines. Compare unfiltered report output with a specific roster filter. Do not submit, approve, relink, remove, upload to or otherwise change the historical record.
- **Expected:** no guessed association and no label claiming a new record is necessarily historical. An unlinked draft has a notice that it cannot add/submit new work. Supported submitted history can appear unfiltered but is excluded from a selected-roster financial filter; ordinary billed status rules still apply.
- **Verdict:** Pass for observed preservation/filtering; Fail for fabricated link, changed history or inclusion in an unrelated recorded-roster filter; Blocked without suitable existing authorized snapshot. Do not create a null link by SQL for this test.

## 6. The six required reports

Both operational roles can read these modes. Open **Scholarship reports** in navigation or use the exact URLs below. Each page has **Generate report**, **Export CSV**, **Print complete report**, **Report definition and whole-result totals**, and **Open source** links where rows exist.

For R01-R06 use the form selections **Scholarship program P1**, **Semester roster batch (recorded link) RA1**, **Academic year 2026-2027**, **Semester 1**, then **Generate report**. The example query is:

`?program_id={P1}&scholarship_batch_id={RA1}&academic_year=2026-2027&semester_code=1`

Leave **Financial billing submission** at All for the financial reports. It is intentionally absent from grants/not-billed pages; do not add that filter to redefine the roster denominator. `/billing-batches/report` is the older allocation-history report and is not a substitute for these six modes.

After the preceding cases, the fresh run's exact expected results are:

| Scope | Grants / Not Billed | Distinct scholars / grant combinations | Billed / awaiting-funds / paid-signed / currently verified liquidated main rows |
| --- | --- | --- | --- |
| P1/T1/RA1 | 3 / 3 rows | 3 / 3 | 0 in each |
| P1/T1/RA2 | 1 / 1 row (S1) | 1 / 1 | 0 in each |
| P1/T1, All rosters | 4 / 4 membership rows | 3 / 3 | 0 in each |
| P2/T1/RB1 | 0 / 0 | 0 / 0 | 0 in each |

These assertions require the complete fresh-tag setup, including S06, and no additional records under that tag. Counts exclude S4, which has no membership. Zero qualifying financial rows verify exclusion/rendering, **not positive financial processing**. Do not label the whole end-to-end workflow passed from this table.

### R01 - Grants by Scholarship Program and Batch

- **Page/role/data:** GET `/scholarship-reports/grants` plus the common query; Coordinator, then Registrar; S1-S3/RA1.
- **Actions:** Generate, follow **Open source** for a new UAT scholar, return and compare RA2 and All rosters. Inspect **Listed rows**, **Distinct scholars**, **Distinct student / program / period combinations** and grouping.
- **Expected:** RA1 contains three members regardless of bills/qualification, including verified not-enrolled S2 and unresolved S3; correct current source revision is shown. Classification is pending. Whole-program four membership rows represent three people/three grant combinations, not four people. S1's later verified correction is revision 2 if E04 completed.
- **Verdict:** Pass for exact roster/group/identity counts and unresolved facts; Fail for omitted never-billed/non-enrolled members, inflated people totals or invented forms; Blocked without setup/account. Official Form 2/Form 3 output remains Blocked under CR-02.

### R02 - Billed Scholars with Total Billing Amount

- **Page/role/data:** GET `/scholarship-reports/billed` plus common query; both roles; BA1/BA2 drafts and fictional rate 1000.00.
- **Actions:** Generate with All submissions, then BA1 only. Inspect original, adjustment and effective totals. Optionally inspect an already available legitimate submitted UAT snapshot read-only under its own correct scope.
- **Expected:** fresh run has zero rows and zero billing totals: two headers and a configured rate are not billed scholars. Billed means submitted with provenance, including submitted awaiting independent approval; drafts/voids are excluded. For available submitted snapshots, sum original row amounts once; adjustments/effective amounts are separate, not added again as a second billing amount.
- **Verdict:** Pass for draft exclusion/zero totals; Fail for counting draft headers/rates as bills or inflated totals. Positive submitted-row reconciliation is Blocked unless qualifying pre-existing authorized UAT records exist; do not bypass CR-01/CR-02 to create them.

### R03 - Not Billed Scholars

- **Page/role/data:** GET `/scholarship-reports/not-billed` plus common query; both roles; RA1 with no submitted bills.
- **Actions:** Generate RA1, RA2 and All rosters; compare with R01 and the roster's **Unbilled members (no submitted billing)** view.
- **Expected:** RA1 S1/S2/S3 remain listed despite enrollment differences and empty financial drafts. A draft reservation, if present in an existing UAT snapshot, still means not billed and must not authorize a duplicate allocation. A submitted bill for that student/program/period in any submission removes it from the corresponding unbilled set. Financial-submission filtering is not offered on this roster report.
- **Verdict:** Pass for expected three/one/four membership rows and distinct counts; Fail if starting from bills/evaluations omits roster-only scholars or drafts are treated as submitted; Blocked without membership data. Testing removal after a newly successful submission remains Blocked by the upstream prerequisites.

### R04 - Grants Awaiting Funds for Payroll

- **Page/role/data:** GET `/scholarship-reports/awaiting-funds` plus common query; both roles; BA1/BA2 drafts.
- **Actions:** Inspect the main list and **Funding stages — one row per billing batch**. Select BA1 only and confirm the stage list narrows. For any existing authorized financial snapshot, compare **Open source** with actual recorded receipt allocations/direct payments; make no new financial entries.
- **Expected:** fresh main list is empty and shortfall total zero; stages show the linked drafts as **Draft — not billed**, not awaiting funds. Only approved unpaid billing with usable actual allocated funding shortfall qualifies. Funded-but-unpaid/approval-pending/source-review records stay separate. Shortfall equals effective approved gross minus posted direct gross minus usable allocated school receipts, floored at zero and counted once per batch; budgets/unallocated receipts are not funding.
- **Verdict:** Pass for fresh draft separation and any actually reconciled snapshot; Fail for drafts/budgets treated as awaiting cash, double-counted batch shortfall or funded-unpaid records mixed into the main list. Positive shortfall/funded-unpaid comparison is Blocked without such existing vetted UAT records.

### R05 - Paid Grants with Verified Signed Payroll

- **Page/role/data:** GET `/scholarship-reports/paid-signed` plus common query; both roles; fresh run has no payouts or signatures. Conditional input is an existing authorized UAT snapshot of actual posted payments with reviewable evidence, not newly fabricated records.
- **Actions:** Generate fresh scope. If suitable snapshot exists, inspect its own scoped report, **Open source**, payment details and signature verification references. Compare any ordinary-proof-only payment or partial signed payment present; do not upload/verify evidence during this read-only comparison.
- **Expected:** zero fresh rows/signed net total. A payroll plan, payment draft or generic accepted payment proof is insufficient. Qualifying posted unreversed actual payouts need matching explicit human signed acknowledgment and intact ordinary proof; net paid is counted once per payment despite multiple documents. Partial signed portions must not be labeled fully applied; deductions remain separate. Evidence/history exceptions appear outside verified totals.
- **Verdict:** Pass for empty-scope exclusion and specific snapshot assertions actually checked; Fail for generic proof/plans counted as verified signed payment or duplicate payment totals. Positive signature coverage is Blocked without appropriate existing UAT evidence; a PDF's existence is not proof of signature authenticity.

### R06 - Liquidated Billings

- **Page/role/data:** GET `/scholarship-reports/liquidated` plus common query; both roles; no fresh liquidation. Conditional input is an existing independently accepted UAT report with supporting source records.
- **Actions:** Generate fresh scope. If qualifying snapshot exists, inspect **Recorded accepted billing histories**, **Currently verified liquidated billings**, coverage and any exceptions; follow **Open source** read-only to confirm reviewer/time/checklist and payment/support coverage.
- **Expected:** zero fresh liquidated rows/coverage. Draft/submitted/returned reports and received funds alone are not liquidated. Verified coverage sums supported student net plus settled deductions once. Historical accepted records lacking confirmed checklist or current intact evidence retain acceptance but appear as exceptions rather than currently verified liquidations; do not overwrite their status to make totals match.
- **Verdict:** Pass for exclusions and actually reconciled accepted snapshot; Fail for automatic liquidation from receipts/payroll, unsupported acceptance counted as currently verified, double-counted deductions or changed historical decisions. Positive accepted-checklist reconciliation is Blocked without qualifying existing UAT records.

### R07 - Filters, complete exports, printing and role access for every mode

- **Pages:** for each of `grants`, `billed`, `not-billed`, `awaiting-funds`, `paid-signed`, `liquidated`, GET `/scholarship-reports/{mode}` with the common filters, then `&format=csv` and `&format=print` through the page links. With no other query parameters, use `?format=csv` or `?format=print`.
- **Role/data:** run all six formats as both operational roles; fictional tagged dataset and recorded IDs. No credentials/real student identifiers in screenshots or exports.
- **Actions:** Generate RA1 scope, export CSV and open **Print complete report**. Check title, applied filters, Manila generation timestamp, rows and whole-result totals agree across formats; cancel print preview or save a fictional-only local PDF. Change to RB1 with matching P2/T1 and expect empty. Deliberately combine P2 with RA1 using the filter form: expect scope error, not a misleading successful report. Financial modes can similarly combine BA1 with unrelated RB1 to test rejection. Return to valid filters afterwards. Reopen source records to confirm no reporting operation changed their status.
- **Expected:** all 18 mode/format combinations work for each role; CSV filenames follow `cfci-{mode}.csv`, and print contains all matching rows. Roster reports do not accept a financial-submission denominator. Invalid IDs/scope are rejected, valid empty results show **No qualifying records match these filters**. Empty money totals are zero where defined; genuinely unknown historical amounts stay Unconfirmed, not invented zero. Report/export does not submit/pay/liquidate anything.
- **Verdict:** Pass for each observed combination/filter/unchanged source result; Fail for wrong scope, missing rows, unauthorized exposure or changed records; Blocked for account/data/download/print environment limitations. More-than-25-row pagination reconciliation is Blocked unless an already prepared fictional dataset supplies that many matching rows; three rows do not test pagination boundaries.

## Expected completion boundary and handoff

The fresh browser run can establish program/roster/profile/pending assignment persistence, exact-period enrollment and revisions, role separation, linked financial draft creation, compatibility validation, roster-based counts, exclusion of draft financial work, and availability of six report screens/exports. It cannot establish an approved grading implementation, final form classification, positive new payable billing or a new fully funded/paid/liquidated report population.

Return a case log separating **Pass / Fail / Blocked / Not run**, with screenshots/exports of fictional records and actual IDs. Explicitly list unavailable operational accounts, absent isolated environment, CR-01, CR-02 and missing positive financial snapshot data as blockers where applicable. Do not report “all workflows passed” from page loads or expected empty results. Retain fictional test history; any later cleanup or provisioning is a separate authorized activity, not database reset or student correction in this guide.

## Implementation references used to prepare this guide

- [Web routes](../../routes/web.php), [authentication routes](../../routes/auth.php), [login controller](../../app/Http/Controllers/Auth/AuthenticatedSessionController.php), [navigation](../../resources/views/layouts/navigation.blade.php).
- [Program controller](../../app/Http/Controllers/ScholarshipProgramController.php), [program form](../../resources/views/programs/form.blade.php), [roster controller](../../app/Http/Controllers/ScholarshipBatchController.php), [roster views](../../resources/views/scholarship-batches/index.blade.php).
- [Scholar controller](../../app/Http/Controllers/ScholarController.php), [registration validation](../../app/Http/Requests/ScholarRequest.php), [scholar detail/membership form](../../resources/views/scholars/show.blade.php), [assignment form](../../resources/views/assignments/form.blade.php).
- [Academic controller](../../app/Http/Controllers/AcademicVerificationController.php), [verification validation](../../app/Http/Requests/AcademicVerificationRequest.php), [academic form](../../resources/views/academic-verifications/form.blade.php), [revision detail](../../resources/views/academic-verifications/show.blade.php).
- [Billing controller](../../app/Http/Controllers/BillingBatchController.php), [billing action](../../app/Actions/Billing/ManageBillingBatch.php), [roster/classification guards](../../app/Services/Billing/BillingRoster.php), [billing creation/rate forms](../../resources/views/billing/index.blade.php), [billing detail](../../resources/views/billing/show.blade.php).
- [Report controller/filter validation](../../app/Http/Controllers/ScholarshipReportController.php), [report counting logic](../../app/Services/Reporting/ScholarshipReports.php), [report filters/export links](../../resources/views/scholarship-reports/index.blade.php), [whole-result summary](../../resources/views/scholarship-reports/summary.blade.php).
- [Registrar policy](../../app/Policies/AcademicRecordPolicy.php), [roster policy](../../app/Policies/ScholarshipBatchPolicy.php), [shared record policy](../../app/Policies/RecordPolicy.php), [billing policy](../../app/Policies/BillingBatchPolicy.php), [financial policy](../../app/Policies/FinancialRecordPolicy.php).
