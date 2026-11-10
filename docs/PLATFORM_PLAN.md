# From exam library to assessment platform: plan and progress

ExamLib is growing from a private certification study site into an examination and assessment
platform with three products on one engine: individual readiness assessment, institutional
assessment management, and examination integrity. The product specification ("ExamLib:
Institutional Assessment, Examination Readiness and Computer-Based Testing Platform") sets the
direction. This file maps that specification onto the code as it is, records where the two differ
and why, and reports each phase as it is delivered. What the web app does today, page by page, is
in [WEB_SPEC.md](WEB_SPEC.md).

Phases are delivered one at a time. A phase starts only once the one before it meets its
acceptance criteria.

| Phase | What | State |
|---|---|---|
| 0 | Repository and architecture mapping | Done (this file) |
| 1 | Institutional foundation: tenants, memberships, roles, isolation | Done |
| 2a | Programmes, classes, student records and student import | Done |
| 2b | Question bank management | Done |
| 3 | Institutional examination builder | Done |
| 4 | Examination execution engine (first major milestone) | Done |
| 5 | Results and analytics, and the public registration page | Done |
| 6 | Readiness assessment and certificates | Done |
| 7 | Integrity monitoring | Done |
| 8 | Live invigilation | Done |
| 9 | Formal examination capabilities | Not started: only after pilot experience |

---

## Phase 0: how the specification maps onto the code

### What exists and is reused

| Specification needs | Already in the code | How it is reused |
|---|---|---|
| Accounts, sign-in, two-step, sign-in protection | `Auth`, `TwoStep`, `SignInGuard`, `Registration` | Unchanged. An institution membership hangs off the existing global account. |
| Granular, server-checked permissions | `Access` (catalogue, role permissions, per-person extras) and the route gate in `Kernel` | Copied as a pattern, not extended: `InstitutionAccess` is the same idea per membership. |
| Audit of administrative actions | `AuditLog` (hash-chained, append-only) and `AuditText` | Reused. Institution events are ordinary entries with subject `institution`/uuid; `forSubject()` reads one institution's history. |
| Notifications and email | `Notifier` (inbox), `MailQueue`, `Mailer` with letterhead | Reused for registration, decisions and invitations. |
| Timed exams with a server clock, saved answers, server grading | `MockExams`, `mock_exams` (`ends_at`, picks saved as made, results computed on the server) | The model for the Phase 4 engine. It reads certification-library questions; the shared engine will put an adapter in front of each question source rather than copy the controller. |
| Background work | `WorkerSupervisor`, detached `bin/*.php` processes, `Worker` job queue | Reused for imports, report building and session recovery later. |
| PDF documents | dompdf through `DocumentStore` and its templates | Reused for result reports and certificates (Phase 5 and 6). |
| Backups | `Backup` exports every table that is not in `SKIP` | New tables are included without any change. |
| Look and feel | Tokens, components, `segmented`, `kpi-strip`, `list-rows`, data tables, dark theme | Every new page uses them; no new design system. |

### Constraints found in the code

- **Routes name one global permission.** The Kernel checks `can:` against the global role before
  a controller runs. Institution pages therefore route as "any signed-in account" and the
  controller's `tenant()` does the institution check. AccessTest still holds every route to a
  declared gate.
- **Administrators hold every global permission.** If institution rights were global permissions,
  every platform administrator would see every institution's people. Keeping them per membership
  is what lets the platform administrator be kept out (specification 3.1).
- **No question source other than collected datasets.** Library questions live in JSON cache files
  addressed by question key, with corrections layered over them. Institution questions will live in
  database tables with immutable versions; the two are never mixed (specification 2.2).
- **The database is MySQL or MariaDB**, so composite foreign keys are available for tenant
  references where a later phase needs them; `FIELD()` ordering is used already.

### Where the code differs from the specification, and why

- **Route names.** The specification proposes `/institution/{ref}`. The code uses plural resource
  paths everywhere (`/users/{ref}`, `/requests/{uuid}`), so institutions are `/institutions/{uuid}`
  and the platform's review pages are `/platform/institutions`.
- **Permission catalogue.** The specification lists about twenty institution permissions. Only the
  four the code checks today are in `InstitutionAccess::PERMISSIONS`; each later phase adds its own
  (question banks, assessments, results, integrity) when the feature behind it exists, so no box is
  offered that does nothing.
- **Departments, programmes and classes** are listed under institutional entities but belong with
  student management, which is in the minimum institutional release but not in Phase 1. They come
  with Phase 2 or as their own step before Phase 3, since assessments are assigned to classes.
- **Verification evidence** is a text note kept with each decision (what the reviewer checked).
  Uploading documents as evidence is left for later.

---

## Phase 1: institutional foundation (delivered)

### Files

Created:

- `web/database/migrations/027_institutions.sql`
- `web/app/Services/InstitutionAccess.php`, `web/app/Services/Institutions.php`
- `web/app/Repositories/InstitutionRepository.php`
- `web/app/Controllers/InstitutionController.php`, `PlatformInstitutionController.php`, `InvitationController.php`
- `web/app/Views/institutions/` (index, register, show, members, profile, activity, `_form`, `_header`),
  `web/app/Views/platform/` (institutions, institution), `web/app/Views/invitations/show.php`
- `web/tests/Integration/InstitutionsTest.php`

Modified: `routes/web.php`, `Services.php` (wiring), `Access.php` (`institutions.manage`),
`AuditLog.php` (`forSubject()`, an Institutions filter), `AuditText.php` (eleven sentences),
`Notifier.php` (an Institutions kind), `View.php` and `layout.php` (menu entries),
`account/index.php` (a Register card), `UserController.php` (delete guard), `components.css`
(segmented control scrolls on a phone), `AccessTest` and `HttpSecurityTest` (the new public
invitation page and route samples).

### Database

Migration 027 adds four tables:

- `institutions`: the profile (official and common name, kind, address, region, district,
  contact details, registration details, intended use) and status: `pending`, `verified`,
  `rejected`, `suspended`, with the latest reason and who decided.
- `institution_reviews`: every decision: from and to status, reason, what was checked, reviewer
  and time.
- `institution_memberships`: one row per person per institution, with the institution role, extra
  institution permissions and `active` or `removed`. A membership that ends is marked removed and
  kept, so history keeps its context; joining again brings the same row back.
- `institution_invitations`: by email address, with a role, 14 days to accept, and only the
  SHA-256 of the link's token stored.

### What it does

- **Registering.** Any signed-in account can register an institution (`/institutions/register`)
  and becomes its owner. It waits for verification; up to three registrations per account can wait
  at once. Reviewers (`institutions.manage`, administrators by default) are told in their inbox.
- **Verification.** `/platform/institutions` lists every institution by status;
  `/platform/institutions/{uuid}` shows the registration, its owners and every decision, and
  verifies, declines (a reason is required) or suspends (a reason is required) it, or reinstates a
  suspended one. Owners are told, with the reason. A declined registration can be corrected and sent
  again. The pages say plainly that verification is not accreditation.
- **Roles.** Owner, institution administrator, instructor, examination officer, invigilator and
  student, each with institution permissions: `institution.manage` (profile, owners only by
  default), `members.view`, `members.manage`, `activity.view`. An owner holds all of them. Extra
  permissions can be given per member, but nobody gives a permission they do not hold.
- **People.** `/institutions/{uuid}/members` lists the people, invites by email with a role,
  shows open invitations and withdraws them, changes roles and removes people. Only owners make or
  change owners; nobody changes their own membership; the last owner cannot be removed, demoted or
  leave; an account that is the only owner of an institution cannot be deleted.
- **Invitations.** The emailed link (`/invitations/{token}`) shows to anyone, signed in or not.
  Accepting needs a signed-in account with the invited email address, so a forwarded link lets no
  one else in. An existing account is also told in its inbox. Without email set up, the person
  inviting is shown the link to pass on.
- **Overview.** `/institutions/{uuid}` is the institution's dashboard shell: its status, people
  by role, open invitations, the person's own role and what it allows, and recent activity. It shows
  only figures that exist; question banks, assessments and results add theirs as they are built.
- **Activity.** `/institutions/{uuid}/activity` is the institution's own history (registration,
  decisions, profile changes, invitations, joining, roles, removals), read from the main activity
  log, which also gained an Institutions filter.
- **Menu.** "My institutions" appears only for people who belong to one; "Institutions" under
  System only for reviewers. Ordinary library members see neither (specification 21).

### Security and permissions

- `InstitutionController::tenant()` guards every institution page: someone without an active
  membership gets "not found", whatever their global role, so the existence of an institution is not
  revealed and platform administrators are kept out of its people and records.
- A member without the page's institution permission gets 403. A pending or declined institution
  opens only its overview, profile and leave; a suspended one opens only its overview, and only to
  owners.
- Every repository read of a membership or invitation puts the institution's id in its `WHERE`
  clause, so an id from another institution finds nothing even on a page the person may open.
- Permissions held in one institution give nothing in another; the global role gives nothing in
  any.
- Every change is recorded in the activity history with the institution as its subject.

### Validation

`InstitutionsTest` (8 tests, about 190 assertions) covers: registration waits and the reviewer is
told; nothing can be invited before verification; declining needs a reason and the owner can send
again; a platform administrator gets 404 on every institution page and sees owners but not people
on the review page; an administrator of one institution gets 404 on another's pages, and another
institution's member and invitation ids do nothing through their own pages; roles give only their
own institution's permissions, across two institutions held by one person; extra permissions; the
owner protections and the delete guard; removal keeps the row and re-inviting restores it;
invitations store only a hash, go to their address, and are replaced by a newer one; suspension
closes the institution and reinstatement opens it. The full suite passes. Pages were checked in a
browser at 1440 and 380 pixels in light and dark, with no failed requests and no horizontal
overflow.

### Known limitations

- Verification evidence is text only; no document upload.
- No institution-level settings yet (none are needed until assessments exist).
- Departments, programmes and classes are not built yet (see above).
- Inviting many students at once (CSV import) is not built yet; it belongs with student
  management.
- The platform reviewer's username shows in an institution's activity history beside decisions.

---

## Decisions taken after Phase 1

1. **Classes before question banks** (the owner's call): Phase 2 is split into 2a (classes and
   students) and 2b (question banks), so Phase 3 can assign assessments to classes.
2. **Registration stays open** to any signed-in account (the owner's call).
3. **Students keep library access** for now: an invited student is an ordinary member account. This
   can be revisited without touching the institution data.
4. **First question types**: single choice, multiple select and true/false. All three are graded
   automatically and share one storage model (choices with one or more marked correct). Short
   answer, numerical and essay follow once the engine works.

---

## Phase 2a: programmes, classes and students (delivered)

### Database

Migration 028 adds `institution_departments`, `institution_programmes`, `institution_classes`,
`class_instructors`, `class_enrolments` and `institution_students`. Every one carries the
institution's id, and every reference to another institution-owned row is a composite foreign key
on `(id, institution_id)`: the database itself refuses an enrolment, record or class that points
into another institution (specification 18.1). Migration 027 gained the `(id, institution_id)`
key on memberships for this, and a `details` column on invitations.

### What it does

- **Programmes and departments** (`/institutions/{uuid}/structure`, `departments.manage`): add,
  rename, edit and remove. One still in use (a department with programmes, a programme with classes
  or student records) cannot be removed.
- **Classes** (`/classes`): a class has a name, an optional intake ("January 2027") and an optional
  programme; the same name and intake cannot be created twice. `classes.manage` creates, edits and
  archives classes, assigns instructors (instructors, administrators and owners), and adds, moves
  and takes out students. An archived class keeps its students and history and takes no new ones.
  `classes.view` sees every class; an instructor sees the classes they teach.
- **Enrolment history**: each stint in a class is a row. Taking a student out or moving them ends
  the row (removed, or moved with the class it went to) and keeps it, so a student's page lists
  every class now and before, and later exam records can name the class a student was in at the
  time. Leaving the institution ends every stint.
- **Student records** (`/students`, `students.manage`; `classes.view` reads them): the institution's
  student number (unique within it), programme, standing (active, suspended, completed, withdrawn)
  and enrolment date. Names and email addresses stay on the person's own account and are never
  copied.
- **Student import** (`/students/import`): a CSV with an `email` column and optional `name`,
  `student_number`, `classes` (separated by `;`) and `programme`. A downloadable template; header
  names, comma or semicolon, a byte-order mark and Windows-1252 text are all accepted; up to 2,000
  rows and 1 MB. The preview shows what happens to every row, and nothing is written until it is
  confirmed. Rows with problems (bad or repeated email, repeated or taken number, unknown class or
  programme, someone already here in another role) are listed with the reason and never skipped
  silently. Existing students are updated and put in their classes; everyone else is invited as a
  student, and the number, programme and classes apply when they accept. A results CSV lists every
  row's outcome, with invitation links when email is not set up. Cells that look like spreadsheet
  formulas are made inert.
- **Overview**: a Classes figure, and "Your classes" for the person's own classes, taught or taken.

### Validation

`ClassesTest` (7 tests) covers programmes and departments; creating, enrolling, moving, taking out
and archiving with history kept; who sees which classes (instructor, examination officer,
student); classes and students of another institution addressed through one's own pages; the
database refusing a cross-institution enrolment; unique student numbers; and the import from
preview to acceptance. Pages checked in a browser at 1440 and 380 pixels.

### Known limitations (2a)

- Student numbers in invitations that are not yet accepted are not reserved; if someone else takes
  the number meanwhile, the invitation's number is dropped on acceptance.
- No bulk move between classes (one student at a time).

---

## Phase 2b: question banks (delivered)

### Database

Migration 029 adds `question_banks`, `question_bank_editors`, `bank_topics`, `bank_questions`
and `question_versions`, all with the institution's id and composite foreign keys, entirely
separate from the certification library's tables.

The important rule is in the shape of the data. A question's content lives in
`question_versions`: one row per save, inserted and never changed. `bank_questions` points at
its `latest_version` (what authors work on, with its review status) and its `published_version`
(what assessments will use). Editing a published question writes a new draft version; the
published one stays in use, unchanged, until the new one is reviewed and published. Phase 3
assessments will record the exact `question_versions.id` they used (specification 7 and 18.2).

### What it does

- **Banks** (`/institutions/{uuid}/banks`): a name, an optional subject, programme and
  description. Archiving keeps the questions and stops new writing.
- **Topics**, with one level of subtopics. A topic used by any version of any question cannot be
  removed (questions keep the topic they were written with).
- **Who may do what**:
  - `question_banks.view` (examination officers, administrators) reads every bank.
  - `question_banks.create` (instructors) creates banks and writes in the banks they created or
    were added to; a bank's creator chooses who else may write in it.
  - `question_banks.edit` (administrators) writes in every bank.
  - `question_banks.publish` (examination officers, administrators) approves, publishes and sends
    back.
  - Anyone else, students included, gets "not found" on a bank and its questions, so answer keys
    never reach them.
- **Question types**: single choice (exactly one right), multiple select (one or more right, with
  optional part marks: each right pick earns its share and a wrong pick takes one share off, never
  below zero; the grading itself comes with Phase 4) and true or false. Up to ten choices, marks
  from 0.25 to 1,000, difficulty, topic, explanation, reference and up to ten pictures (the existing
  picture store, kept by image cleanup).
- **The editor** checks the question before saving (one right answer for single choice, at least
  two choices, no repeated choices) and comes back filled in when something is wrong. Saving an
  unchanged question writes nothing. Without JavaScript every field shows; with it, only the ones
  the kind of question uses.
- **Review**: draft, in review, approved, published, archived. Authors send a question for review;
  a publisher approves it, publishes it (or both at once) or sends it back with a reason the author
  sees. `/banks/review` lists everything waiting. Every step is in the institution's activity
  history.
- **The question page** shows the question as a candidate will see it, separately from its answer
  and marks, and every version with the one in use marked; any earlier version can be opened.

### Validation

`QuestionBanksTest` (5 tests): writing and checking questions of each kind, topics and
subtopics; the review chain with a returned question, publishing, and an edit that becomes
version 2 while version 1 stays unchanged and in use; who may read and write a bank (creator,
added editor, another instructor, examination officer, student); another institution's banks and
topics refused, and the database refusing a cross-institution version; pictures and archived
banks. Checked in a browser at 1440 and 380 pixels in light and dark.

### Known limitations (2b)

- Question text is plain text with line breaks: no rich formatting or mathematical notation yet.
- Short answer, numerical and essay questions are not built (decided above).
- No import of questions from a file, and no copying between banks.

---

## Phase 3: the examination builder (delivered)

### Database

Migration 030 adds `assessments`, `assessment_items` (questions chosen one by one),
`assessment_blueprints` (questions drawn per candidate) and `assessment_assignments` (classes
and named students), all institution-scoped with composite foreign keys.

### What it does

- **Assessments** (`/institutions/{uuid}/assessments`): created as drafts, with a title and a kind
  (practice, diagnostic, readiness, formative, summative, formal examination). The kind describes
  the result; it does not change how marking works (specification 8).
- **Settings**: description, instructions for candidates, time allowed (1 to 600 minutes), pass
  mark (or none), attempts allowed, when it opens and closes (entered in the person's timezone,
  kept in UTC), shuffling of questions and of choices (true or false keeps its order), a deduction
  for wrong answers (0 to 1 times the question's marks; unanswered loses nothing), and what
  candidates see once results are published (nothing, their score, or their answers with the right
  ones and explanations).
- **Questions**, in two ways that combine:
  - chosen one by one from any bank the person can see (only published questions), in an order
    that can be changed;
  - a blueprint: so many questions from a bank, optionally one topic (with its subtopics) and one
    difficulty, drawn for each candidate and never repeating a chosen question.
- **Readiness checks** before publishing: instructions written, at least one question, every chosen
  question still published, enough published questions for every blueprint row (drawn in order, as
  a form would be), a closing time in the future, and at least one class or student assigned.
- **Publishing** (`assessments.schedule`) fixes every chosen question to the exact version
  published at that moment; later edits and new published versions do not touch it. A published
  assessment's settings cannot change; its opening and closing times can. It can be closed,
  reopened, taken back to a draft (which unfixes the versions), archived and restored.
- **Assignment** to classes (meaning their students at the time they sit) and to named students.
  Instructors assign only the classes they teach; people with `classes.view` assign any class or
  student. The page shows how many students the assignments reach now.
- **Preview**: one candidate's form, drawn as Phase 4 will draw it, with the right answers marked
  for staff; "Draw another" shows a different candidate's form. The draw is reproducible from its
  number (a seeded generator), which is how a sitting will be stored: its seed and the question
  version ids it used.
- **Permissions**: `assessments.view` (see every assessment), `assessments.create` (build drafts:
  instructors see and build their own), `assessments.assign`, `assessments.schedule` (publish,
  times, close). Examination officers and administrators hold all four; instructors create and
  assign. Students cannot reach any of it.

### Validation

`AssessmentsTest` (4 tests): settings validation and UTC times; unpublished questions refused;
blueprint shortage reported; publishing refused until ready and refused to instructors; versions
fixed at publishing and unchanged by a later published edit; settings locked once published;
rescheduling, closing, reopening and back to draft; the drawn form (same seed, same form; chosen
questions never drawn again; blueprint filters; forms differ between seeds); who may build,
assign and see; another institution's assessments, banks and classes refused, and the database
refusing a cross-institution item. Checked in a browser at 1440 and 380 pixels.

### Known limitations (3)

- Going back to a draft is allowed at any time, since nobody can sit an assessment yet. Phase 4
  must refuse it once a sitting exists.
- No sections with their own timing; one time limit for the whole assessment.
- Total marks of a blueprint-drawn form depend on the questions drawn; percentages are worked out
  per candidate.

---

## Phase 4: the examination engine (delivered)

### Database

Migration 031 adds `assessment_sessions` (one per attempt: start, the server's end time,
status, score), `assessment_session_questions` (each sitting's exact form: the question
version ids in the order shown, each one's choice order and marks, and the grade),
`assessment_responses` (the latest saved answer per question, with an ordering number) and
`assessment_response_history` (every kept change, append-only).

### What it does

- **My assessments** (`/my/assessments`, in the menu for anyone in an institution): what is set
  for the person as a student, through a class they are in now or by name, with whether it is
  open, not open yet or closed, attempts left and any sitting in progress; and everything they
  have sat.
- **Instructions** before starting: questions, time allowed, pass mark, attempts left, wrong
  answer deductions, closing time, the institution's instructions, the rules and exactly what is
  recorded. Starting needs the rules accepted. A second start carries on the sitting in progress;
  two starts at once cannot both succeed (a unique attempt number).
- **The form** is drawn once, at the start, with a seed, and stored: the same questions in the
  same order and with the same choice order every time the sitting is opened, whatever happens to
  the assessment or its questions afterwards.
- **The clock is the server's.** A sitting ends at start plus the time allowed, or at the
  assessment's closing time if that is sooner. The page counts down what the server says and checks
  with it every minute; answers arriving after the end are refused, and the sitting is handed in
  with what had arrived. Sittings nobody comes back to are handed in by `bin/sittings.php` (cron,
  every minute), when next opened, and when their results are opened.
- **Answers are saved as they are picked** (`POST /api/sit/{uuid}/answer`). Each save carries a
  number that only goes up; the server keeps a save only when its number is higher than the stored
  one, so a slow request never overwrites a newer answer. An answer the server has not confirmed is
  kept on the device and sent again with growing pauses; the bar says how many have not arrived.
  Handing in also sends every answer on the page.
- **The sitting page** has its own plain layout (no menu, inbox or search), one question at a time
  with a panel to jump between them, "mark to look at again", clearing an answer, and a hand-in
  confirmation that counts answered, unanswered and marked questions. Without JavaScript every
  question shows and handing in still works. Nothing about the right answers is ever on the page.
- **Grading**, on the server, from the stored form: single choice and true or false earn their
  marks when right; multiple select earns them for exactly the right choices, or with part marks
  each right pick earns its share and each wrong pick takes one off (never below zero); a wrong
  answer loses the assessment's deduction; unanswered loses nothing; the total never goes below
  zero. Percentage and pass or fail follow.
- **Results for staff** (`/assessments/{a}/results`): every sitting with class, attempt, status
  (live progress for sittings in progress), score, percentage, pass, start and time taken; totals
  for handed in, sitting now, average and passed; and each sitting's answers question by question,
  with the letters as that candidate saw them.
- **Candidates** see that they handed in and how many they answered. Their results appear when
  the institution publishes them (Phase 5).
- **Only the candidate** reaches their sitting: anyone else, staff and administrators included,
  gets "not found" there. An assessment that has been sat can no longer go back to a draft, and
  restoring an archived one that was sat restores it as closed.

### Validation

`SittingsTest` (5 tests): the whole path from the list to the result with exact scores
(3.5 of 6 for a right single choice, half of a part-marks multiple select and a wrong true or
false); a late save ignored; history kept; handing in twice and starting a used attempt refused;
staff results; back to draft refused once sat. The server's clock: the end set at start, a save
after the end refused with the sitting handed in with what had arrived, and the background check.
Nobody but the candidate reaches a sitting; a student it is not assigned to does not see it. Not
open yet, and never running past the closing time. Marking rules for each kind of question. In a
browser: answering, losing the connection (the answer kept and shown as not saved), reconnecting
(sent), cancelling the hand-in (saving and the clock carry on), handing in; at 1280 and 380 pixels.

### Known limitations (4)

- Results are not shown to candidates yet; publishing them is Phase 5.
- No pause or extra time for one candidate (invigilation, Phase 8).
- If the device is offline when the time runs out, answers it never sent are not counted, as the
  specification requires.

---

## Public institution registration (delivered with Phase 5)

`/join`, linked from the sign-in page ("Running a training institution? Register it"), registers
an institution for someone without an account: their account (name, email, username, password)
and the institution's profile in one form. It follows the site's sign-up setting: open or needing
approval it works, closed it explains how to register from an existing account. Nothing is stored
while the institution's details have problems. The account is created unverified with an emailed
code, exactly as an ordinary sign-up; the institution waits in the browser's session and is
registered only once the code is typed, so an address nobody confirms never leaves an institution
behind. Signed in, `/join` goes to the ordinary form. `JoinTest` covers both modes.

## Phase 5: results and analytics (delivered)

Migration 032 adds `results_published_at` and `results_published_by` to `assessments`.

- **Publishing results** (`results.publish`: examination officers, administrators, owners). Until
  then candidates know only that they handed in. Publishing tells every candidate who handed in,
  in their inbox and by email if they chose it, linking to their latest attempt; it can be taken
  back. Candidates then see what the assessment's review policy allows: nothing ("your result is
  recorded"), their score with a breakdown by topic, or that plus every question with their answer,
  the right one and the explanation. A PDF of their report downloads from the same page.
- **Per sitting**: score, percentage, pass, and topics weakest first (`/results/{sitting}`, with a
  PDF report for `results.export`).
- **Per assessment** (its results page): handed in against the candidates it reaches, sitting now,
  average and passes; a score distribution in bands of 10%; topics across candidates; and every
  question with how often it was given and answered right, how often unanswered, and the wrong
  choice picked most. Questions answered right by under 30% or at least 95% of five or more
  candidates are flagged as very hard or very easy, as prompts for the author, not verdicts.
- **Per class**: each assessment assigned to it, with how many of its students handed in, their
  average and passes (class page). **Per student**: every result (student page). **Institution**:
  sittings handed in, average score and pass rate on the overview.
- **Export** (`results.export`): every sitting as CSV (cells that look like formulas made inert),
  and any candidate's report as PDF. Downloads are recorded in the activity history.
- Statistics are worked out from stored sittings (their forms, answers and grades), never from the
  question bank as it is today, so a later edit never changes a past result. Charts are drawn with
  CSS classes rather than inline styles, which the CSP forbids, and each has a table for screen
  readers.

`ResultsTest` (3 tests): statistics with the most picked wrong choice, CSV and PDF, publishing and
the notification, what each review policy shows, taking results back, who may publish and export,
and the class, student and institution views.


## Phase 6: readiness assessment and certificates (delivered)

Migration 033 adds `certificates`.

- **Readiness** is worked out from a finished mock exam of a certification in the library, by
  fixed rules in `Readiness`, and shown on the mock exam's result page with the reasons:
  - **Ready**: 80% or more, timed, at least 30 questions, at most 10% unanswered, and another
    attempt at the same exam scoring 70% or more in the 60 days before it (consistency).
  - **Nearly ready**: 70% or more without every Ready condition met.
  - **Developing**: 55% or more. **Not yet ready**: below that.
  The page says which conditions held and which did not, so a learner knows what to do next.
- **Certificates of readiness** can be issued by the learner for an attempt rated Ready, once per
  attempt. Each carries a number (`EL-YYYY-NNNNNN`), the holder's name, the exam, the score and
  the date, and the disclaimer the specification asks for: it is ExamLib's readiness estimate, not
  a certification and not endorsed by the exam's owner. "My certificates" lists them, each with a
  PDF.
- **Anyone can check a certificate** at `/verify/{token}` without signing in: the token is 32
  random hex characters printed on the PDF, so certificates cannot be listed by guessing. The page
  shows the holder, exam, score, date and whether it still stands, and nothing else about the
  person.
- **Revoking** (`certificates.manage`, platform administrators): from `/platform/certificates`,
  with a reason. A revoked certificate stays, and its verification page says it was revoked and
  when. Issuing and revoking are in the activity history.

`ReadinessTest`: the rules at every level, issuing only for Ready and only once, the PDF, the
public verification page, and revoking.

### Known limitations (6)

- Readiness reads mock exams of library certifications only. Readiness from an institution's own
  assessments, and a separate readiness model per provider, are left for when there is data to
  set them.
- The thresholds are constants in `Readiness`, not settings, until pilot use says what they
  should be.

## Phase 7: integrity monitoring (delivered)

Migration 034 adds `monitoring` to `assessments` and `assessment_sessions`, and the
`assessment_events` and `integrity_reviews` tables.

- **Three policies**, chosen per assessment and fixed into each sitting when it starts, so
  changing the assessment later never changes what a running sitting collects:
  - **Basic**: nothing from the browser. The server still records the page being opened, handing
    in, time running out, and any invigilator action.
  - **Standard**: the page being hidden and shown again (with how long), the window losing and
    regaining focus, and the connection dropping and returning.
  - **Enhanced**: the same, plus copy, cut and paste (that they happened, never what), and leaving
    full screen. The sitting page offers a full-screen button.
  Candidates are told the policy and what it collects on the instructions page before they start.
  **Keystrokes and clipboard contents are never collected under any policy.**
- **Events** are queued in the browser (kept on the device through a dropped connection) and sent
  every few seconds. The server keeps only types the sitting's policy allows, drops repeats by
  sequence number, caps a sitting at 2,000, and stamps when each arrived next to the browser's
  own time.
- **Indicators** are worked out by fixed rules and worded neutrally ("The page was hidden for 75
  seconds"): `info` (noted), `review` ("Worth a look": the page hidden or out of focus five or
  more times, hidden for two minutes in all or one minute at a stretch, or three or more pastes
  or full-screen exits) and `technical` (the page opened again three or more times, or the
  connection dropping three or more times). They are prompts for a person, never a verdict, and nothing happens to a
  result because of one.
- **The sitting page for staff** (`integrity.view`) shows the indicators and the full timeline;
  the results list has a chip for each sitting worth a look. People with `integrity.manage`
  record a conclusion (no concern, concern noted, referred for a decision) with a note, which is kept and
  recorded in the activity history. The indicators are never changed by a conclusion.

`IntegrityMonitoringTest`: what each policy collects and that it is fixed at the start, repeats
and unknown types dropped, the server's own events, only the candidate sending for their sitting,
the indicator rules, neutral wording, and conclusions.

### Known limitations (7)

- No camera, microphone or screen recording. The specification puts proctoring of that kind
  outside the minimum release, and the privacy questions need a decision first.
- A browser can always be made to send nothing; what Standard and Enhanced collect shows what a
  cooperating browser saw, which is why they are prompts and not evidence on their own.

## Phase 8: live invigilation (delivered)

Migration 035 adds `paused_at` and `extra_minutes` to `assessment_sessions`, and the
`invigilator_assignments` and `invigilator_observations` tables.

- **Who watches**: anyone with `assessments.monitor` (examination officers, administrators,
  owners), or a staff member assigned as an invigilator of that assessment by someone with
  `assessments.schedule`. Everyone else gets 404 for the live page.
- **The live page** (`/institutions/{ref}/assessments/{assessment}/live`) lists every candidate
  the assessment reaches: not started, writing, paused or handed in; answered so far; minutes left
  by the server's clock; whether the sitting has been heard from in the last two minutes; and its
  integrity level. It refreshes every 15 seconds by polling, updating the rows in place. The feed
  carries no questions or answers.
- **Messages**: a watcher sends a message to one candidate, or to everyone writing (for example
  "15 minutes left"). It appears at the top of the candidate's page within about 15 seconds, and
  the live page shows how many were sent and whether they have been seen. Messages are kept with
  the sitting (migration 036) and shown on the staff sitting page with when each was seen.
- **The counts** at the top of the live page (not started, writing, paused, handed in) update with
  each refresh.
- **Observations**: an invigilator records a note, an incident, a technical difficulty, an
  interruption or an identity check against a sitting. They are kept append-only and shown on the
  staff sitting page with who wrote them and when.
- **Interventions** (`assessments.intervene`):
  - **Pause** stops the sitting's clock. The candidate's page shows that the invigilator has
    paused it, refuses answers, and carries on by itself when it resumes.
  - **Resume** adds the paused time back to the end time.
  - **Extra time**, 1 to 240 minutes with a reason (for example a power cut).
  - **End the sitting**, with a reason: it is handed in and marked with the answers it had, and
    shows as "Ended by the institution".
  Each is recorded as a server event on the sitting's integrity timeline and in the activity
  history with its reason. A paused sitting is never handed in by the expiry run.

`InvigilationTest` (5 tests): who may watch and intervene, assigning invigilators (staff only),
the live feed, observations, pausing and resuming with the time given back, extra time, ending a
sitting, and messages with their seen state.

### Known limitations (8)

- Polling, not a socket: the live page is up to 15 seconds behind, and a candidate's page notices
  a pause within about 15 seconds.
- No room or seat plans.

## Phase 9: formal examination capabilities (not started, by design)

The specification says formal examinations (secure browsers, venue management, seat plans,
candidate identity documents, external moderation, accreditation reporting) should come only
after operational experience with Phases 4 to 8. They are not built. What exists already covers
part of the ground: identity checks can be recorded as invigilator observations, every sitting
keeps an append-only answer history and integrity timeline, results are published deliberately,
and the activity history records every decision. The next step is a pilot with one or two
institutions, then a decision on which formal capabilities they actually need.

---

# Addendum: institutional onboarding, student lifecycle and credentials

The specification addendum ("Institutional Onboarding, Student Lifecycle and Credential
Management") is delivered in its own priority order: institution registration and verification,
then student identity and membership, bulk enrolment and enrolment codes, programmes and academic
records, and credentials with signatories, transcripts and corrections.

## Stage 1: institution registration and verification (delivered)

Migration 037.

- **An application, not a switch.** Registering (`/institutions/register`, or `/join` without an
  account) starts a **draft**. The application page (`/institutions/{ref}/application`, for
  managers) lists what is still needed, takes the supporting documents, and sends it. The
  lifecycle is draft, submitted, under review, more information requested, approved, not
  approved, suspended; every step is kept in the review history with who, when and why.
  Reviewers are told only once an application is sent.
- **What is collected**, in four sections: the organisation (official and trading name, kind,
  year established, the training it offers, address, district and region, official email and
  telephone, website); registration and accreditation (registration authority and number,
  accrediting body, reference and expiry, other notes); the authorised representative (name,
  position, telephone; their email is their verified account); and the agreement.
- **Documents by kind of institution.** Universities, colleges, technical colleges and
  examination providers must send their registration certificate; anyone naming an accrediting
  body must send its evidence; a private centre or corporate department is not asked for what it
  may not have. Documents are PDF, JPEG or PNG (checked by content), up to 10 MB, stored outside
  the web root under `storage/institution_documents` (included in backups), served only to the
  institution's managers and platform reviewers, with a SHA-256 kept. Once an application is
  sent, documents can be added but not removed: they may be evidence for a decision.
- **The agreement.** Sending the application means accepting, on the institution's behalf, the
  institutional terms, the data processing agreement, the examination usage policy, the question
  ownership terms, the certificate issuance rules and the acceptable use policy. They are public
  at `/policies`, each with a version, and `policy_acceptances` records which version who
  accepted when and in what context. The data processing agreement puts the lawful basis for
  processing students' information, including guardian involvement for minors, with the
  institution: accepting a notice is not treated as that basis.
- **Verification dimensions.** Organisation identity (pending, verified, rejected), legal
  registration and accreditation (not verified, verified, not applicable), representative
  authority (pending, verified, rejected), and the platform approval (the status). A reviewer
  sets each with a note and what they checked; each change is its own history row. Approving
  needs identity and representative authority verified; registration and accreditation are shown
  exactly as set, so a verified private centre is never presented as accredited. The header shows
  "Registration verified" or "Accreditation verified" only when they are.
- **Decisions**: start the review, ask for more information (the owners answer on the
  application page and send it again), approve, do not approve (they can correct and send again),
  suspend and reinstate. Suspension closes the institution's pages but keeps every membership,
  result and certificate.
- **Activation and setup.** On approval the owners are told and the overview shows a
  "Getting started" checklist worked out from what exists: two-step sign-in on the owner's account,
  staff invited, programmes, a class, students, a question bank, a first assessment.
- Institutions approved before this stage have identity and representative authority marked
  verified (a reviewer had checked them) and registration and accreditation not verified.

`OnboardingTest` (3 tests) and the updated `InstitutionsTest` and `JoinTest`: required documents by
kind, content-checked uploads, document access, the agreement and its versions, asking for and
answering more information, approval needing identity and authority, dimension history,
registration shown verified while accreditation is not, suspension keeping records, public
policies.

### Known limitations (stage 1)

- The representative's telephone is recorded and checked by a reviewer (for example by calling),
  not by an automatic SMS code.
- Ownership: the applicant becomes the owner, and the reviewer verifies their authority before
  approval. Transferring ownership afterwards uses the existing role change by another owner.

## Stage 2: student identity, membership lifecycle, bulk enrolment and codes (delivered)

Migration 038.

- **One account, many relationships.** A student has one ExamLib account. Each institution they
  belong to holds a membership and its own student record (number, programme, classes, standing).
  Neither institution sees the other's records, and accounts are never created, merged or matched
  by name on anyone's behalf.
- **The lifecycle**, shown as counts on the students page: invited (sent, link not opened, no
  account), pending registration (the person needs to create or confirm an account), pending
  acceptance (an account with the address exists), then active, suspended, withdrawn or completed
  (the student's standing). Standing belongs to the relationship: a student who is withdrawn,
  suspended or has completed keeps their account, study and other institutions, and their results
  stay in the institution's records. **A student who is not active can no longer start the
  institution's assessments**; before this stage the sitting query ignored standing.
- **Invitations**: created, delivered, opened (recorded), accepted, expired, cancelled and
  resent. Resending issues a fresh link (the old one stops working), lasting another 14 days, and
  works on an invitation that expired unanswered. The students page lists open student
  invitations with their state, how often and when they were sent, and Resend and Cancel; staff
  invitations stay on the People page.
- **Membership notice.** Joining as a student, by invitation or by code, shows what the
  institution will and will not see and needs the box ticked; the acceptance is recorded with the
  notice's version in `policy_acceptances`.
- **Bulk enrolment** matches by email address only and puts every row in a category: existing
  member (record updated), existing ExamLib user (invited to join), new user (invited to create an
  account), already invited (kept, never sent twice), needs a decision, or not importable. A
  name that differs from the account's, or a student number that differs from the one on record,
  is held for someone to tick in the preview. A student number that belongs to another student or
  waits in another invitation is refused. The preview offers the problem rows as a CSV, the
  results CSV covers every row, and importing the same file again sends nothing twice.
- **Enrolment codes** (`/institutions/{ref}/students/codes`, students.manage): generated codes
  such as `BLAN-DNKC-GFTN`, optionally limited to a programme or class, with an expiry date, a
  maximum number of uses and whether each request needs approval. Students enter a code at
  `/enrol` after signing in, see the institution and what joining means, then join or ask to.
  Requests appear on the students page to approve or decline, and the student is told. A code
  establishes eligibility to ask, not identity, and the codes page says so. Ten wrong codes in an
  hour stop an account from trying more, so codes cannot be found by guessing. Withdrawing a code
  leaves everyone who joined with it in place.

`StudentLifecycleTest` (4 tests) and the updated `ClassesTest`: invitation states, resend with a new
link, cancel, staff invitations kept out, the membership notice, codes with and without approval
(class applied, uses counted, expiry, withdrawal, guessing limit), import categories with
conflicts held for a decision, the problems file, a safe second import, and standing stopping
assessments.

## Stage 3: programmes, academic records and transcripts (delivered)

Migration 039.

- **Programmes in detail** (`/institutions/{ref}/structure/programmes/{programme}`,
  departments.manage): description, duration, learning objectives, completion requirements,
  whether it is still offered, and its **modules** (code, name, description, required or
  optional, retired or offered). Classes keep their intake (an academic year or intake such as
  "January 2027"), which is how intakes and academic years are represented.
- **Assessments belong to modules.** An assessment's settings take a module. Passing it, once
  its results are published, completes the module for that student. Staff can also record a
  module completed elsewhere. Completions are written once and kept: relinking an assessment,
  retiring a module or changing a programme never rewrites what a student completed.
- **Programme history.** Each programme a student is on is kept with its start and end dates and
  how it ended (current, completed, withdrawn, moved to another programme). Changing a student's
  programme closes the old one; it is never edited away.
- **The academic record** on each student's page: programme history, modules with their
  completion date and result, and every assessment marked **final** (results published) or
  **provisional** (handed in, not yet published). When every required module is complete the
  record offers "Mark completed", which ends the programme as completed and sets the standing to
  completed; completing without every required module needs an explicit tick and is recorded as
  such.
- **Students see their own record** at `/my/record`, across all their institutions: final
  results only, modules completed, and the transcripts issued to them.
- **Transcripts** (transcripts.issue: owners, administrators, examination officers): a snapshot
  of the record when issued, numbered `TR-YYYY-NNNNNN`, as a PDF. Including provisional results
  is a choice and they are marked as such on the document. A transcript is never regenerated;
  a correction means issuing a new one and withdrawing the old one with a reason. A withdrawn
  transcript disappears from the student's record, and staff copies are marked WITHDRAWN.
- **Students under 18**: date of birth, guardian name and contact, and when the institution
  recorded the guardian's agreement. The record warns that the institution is responsible for
  the guardian's involvement and the lawful basis, as the data processing agreement says.

`AcademicRecordsTest` (2 tests): programme details and modules, completion only after
publication, final and provisional results, completion requirements with the override, a
completion surviving relinking, transcripts as immutable snapshots, PDF access for the holder
only, withdrawal, permissions, programme history and the under-18 record.

### Known limitations (stage 3)

- Attendance is not tracked, so "completion requirements" beyond modules are written down for
  staff rather than enforced.
- Transcripts are verified by the issuing institution for now; public verification of
  transcripts comes with the credential stage.

## Stage 4: credential management (delivered)

Migration 040. ExamLib's own readiness certificates (stage 6 above) stay as they are; this
stage adds credentials that **institutions** issue in their own name.

- **Categories with their own rules**: assessment completion, institutional achievement, and
  formal qualification. A formal qualification can be issued only by an institution that a
  platform reviewer recorded as an authorised awarding body (`/platform/institutions/{ref}`,
  "Formal qualifications"), naming the body whose qualifications it may award.
- **Templates** (credentials.manage: owners and administrators) say what is printed (title, what
  it certifies, footer), how long it is valid, whether each one needs a signatory's approval, and
  who is eligible: passing an assessment (results published, optionally at a minimum percentage),
  completing a programme (from the academic record), or chosen by staff. Once a template has
  issued anything, its kind and eligibility cannot change; a different rule is a new template.
- **Authorised signatories**: owners choose which staff may approve and sign which kinds of
  credential, with their position (Principal, Director of Studies, Examinations Officer...).
  Students cannot sign. Their name and position are printed on what they sign.
- **Issuing** (credentials.issue: owners, administrators, examination officers): the eligible
  students are listed with what they rest on; issuing asks a signatory for approval, or, when the
  template allows and the issuer is a signatory, issues directly. Signatories see what waits for
  them and approve or decline. The holder is told and finds it under My certificates.
- **No accidental duplicates**: one pending or issued credential per holder and achievement (a
  unique key, so two clicks cannot make two); issuing again needs an explicit "duplicate" tick,
  which is recorded.
- **What it contains**: the institution's logo (uploaded on the credentials page) and name at
  issue, title, holder, what it certifies, programme or assessment, achievement and issue dates,
  expiry where applicable, a number `EXL-YYYY-XXXXXXXX`, the signatory, a QR code and the
  verification address. The PDF is landscape A4.
- **Verification** (`/verify/{token}`, the same public page as readiness certificates): genuine
  and current, expired, revoked (with the reason) or replaced (naming the replacement); the
  holder, achievement, dates, signatory, and the issuer with its verification dimensions as they
  are (so "accreditation: not verified" is said when it is). If the issuer is suspended, the page
  says so and still shows what it issued. Nothing else about the holder is disclosed: no answers,
  contact details or monitoring records.
- **Authenticity**: each issued credential stores a SHA-256 fingerprint of its printed fields,
  signed with an Ed25519 key kept in `storage/keys` (outside the web root, included in backups;
  public keys at `/verify/keys`). The verification page recomputes both, so a record changed in
  the database shows as not matching its signature. The page stays authoritative: a QR code only
  points to it. No blockchain.
- **Lifecycle**: issued credentials are never edited. Revoking needs a reason and keeps the
  credential, verifiable as revoked. A correction (for example a corrected name) is a new
  credential signed again that replaces the old one, which stays, marked replaced. Every step is
  in the credential's history. If results behind a completion credential are taken back, the
  register flags it for review.

`CredentialsTest` (2 tests): the category rule, templates, eligibility, signatories (owners only,
staff only), approval, duplicates refused and deliberately allowed, verification content and
privacy, a tampered record caught, holder-only PDFs, correction and replacement, revocation with
its reason, results taken back, a suspended issuer, expiry, and an authorised awarding body.

**Notices** (with this stage): besides the institutional agreement and the membership notice,
creating an account now records the account notice, and starting an assessment records the
participation notice, plus the monitoring notice when the assessment monitors more than the
basics. Public verification links the verification notice. Every notice is versioned at
`/policies` and each acceptance is kept in `policy_acceptances` with its context.

## Institution onboarding, made flexible (delivered)

- **Registration is a wizard.** The form is split into steps (organisation, contact,
  representative, registration, extra fields, review) that slide into place, with a step
  indicator and a progress bar. Each step checks its own fields before moving on, Enter moves to
  the next step, and the last step lists every answer with a link back to its step. The join page
  adds an account step first. Without JavaScript every step shows at once and the form still
  sends. The profile page keeps the ordinary layout.
- **Administrators decide what is asked** (`/platform/institution-requirements`): document slots
  (name, hint, required or optional, which kinds of institution), extra profile fields with a type
  and, for a choice, its options, and which optional built-in fields show. Order is set with up
  and down; anything can be switched off. A field for some kinds of institution only follows the
  kind chosen in the form. Migration `041` adds `institution_requirements` (the starting list is
  the documents there were before and every built-in field shown) and `institution_field_values`.
- **One upload per document.** The application page lists each document asked of this kind of
  institution with its own Upload or Replace button; there is no list of kinds to choose from.
  A new file replaces the earlier one while the application is still open.
- **Milestones.** The application and the reviewer's page show where an application stands:
  started, sent, checks (how many of the four), then approved, not approved or suspended.
- **Approving from any open stage.** A reviewer could verify every check while the application
  was still a draft, but Approve only worked once it had been sent, so it stayed "Draft, not
  sent". Approve now works from any open stage; identity and the representative must still be
  verified.
- **Deleting is hard to do by accident.** Only an institution that is suspended or was never
  approved can be deleted, with its exact name typed, a reason, and the password confirmed. It is
  hidden from lists, closed to its members (404), listed under Deleted, and can be restored. It is
  removed for good only after 30 days, with the name typed and the password confirmed again, and
  never once it has issued credentials or transcripts. Every step is in the activity history, and
  backups still hold it.

`InstitutionSetupTest` (5 tests): the administrator's settings, the form following them, required
and checked extra fields, a hidden field kept on save, a field for other kinds of institution not
asked, approving a draft, and every deletion protection.

## PDF covers and exam names (delivered)

- **Cover page.** Every PDF (and every part of a large one) opens with a full-page cover: the
  site's logo (an uploaded one keeps its proportions; otherwise the site's mark) and name, the
  exam's name sized to fit, its code, the edition, the question count and collection date, and
  the site's address. The shapes down the left edge and in the right-hand corners use the
  site's accent, a deep shade of it and a near-black ink. The guide notes and contents move to
  page 2. The inside pages use the accent too. A change of branding or of the exam's name makes
  the PDFs again.
- **Renaming an exam** (`library.manage`): a pencil beside the exam's title on its library page.
  The name is kept when the source's exam list or a new collection brings another (migration
  `042`, `exams.name_locked`); an empty name goes back to the source's. Audited as `exam.renamed`.
- **Edition on the cover.** The cover shows the exam's edition (for example V1.0) with the kind
  of PDF under it. The edition is read from the name ("v12", "V1.0", a year) or from a code
  ending "-v1-0"; the rename form takes one by hand when that is wrong (migration `043`,
  `exams.edition_label`). With no edition, the kind of PDF stands alone.

## Phase 0: coherence and correctness (delivered)

From the review of the product audit (the corrected brief, November 2026). Decisions A to E of
that brief were not answered, so its defaults were used: the library is a separate module that
is off for accounts that come through an institution; no runtime names changed.

- **Readiness cannot be earned on questions the candidate chose.** A mock exam now records
  where its questions came from and how many that offered (migration `044`,
  `mock_exams.source` and `pool_size`). Readiness counts an attempt only when it was drawn from
  all questions or from ones not answered before, and from a pool at least twice the number
  asked. The earlier attempt it needs must meet the same two conditions, have at least 30
  questions, and share at most half of the qualifying attempt's questions. Every condition is in
  the list on the result page. Attempts from before sources were recorded do not count;
  certificates already issued stay valid and keep the criteria they were measured against.
- **The library is an entitlement.** `Entitlements` is the one place that answers whether an
  account has the library; `Access` asks it when working out what a member's role gives
  (migration `045`, `users.library_access`). Plans and billing can drive it later.
- **One home.** `/` opens for every signed-in person and shows what the account has: the
  institution section (from the data the institution pages already use), the study section, or
  how to join an institution. My certificates is open to everyone, because institutions issue
  credentials too. The palette offers exams only to accounts with the library.
- **Institution pages are grouped.** Overview, Teaching (classes, question banks, assessments),
  People (staff and members, students), Academic (programmes), Credentials, and Administration
  (profile, application, activity), with the open section's pages on a second row. Only pages
  that exist and that the member may open are shown. Until an institution is approved its three
  pages stay flat. Someone in more than one institution can switch from beside the name.
- **Identity.** `web/README.md` and the composer description describe ExamLib as it is.

Proposed readiness values, for approval before this is deployed (Decision D), all constants in
`Readiness`:

| Value | Proposed | Why |
| --- | --- | --- |
| Sources that count | all questions, unanswered questions | The other three are lists the candidate makes. |
| Pool size | at least twice the questions asked | A candidate cannot predict a draw of 30 from 60 or more. It also closes the way round through "unanswered": answering everything except thirty known questions leaves a pool of thirty. |
| Overlap with the earlier attempt | at most half | Two attempts on mostly the same questions are one performance. |
| Earlier attempt's length | at least 30 questions | Without it a one-question attempt satisfied "another attempt at 70%". |

The cost: an exam needs about 60 gradable questions for a 30-question mock to count, and
comfortably more for two attempts to share under half. Exams with small pools cannot earn a
certificate. That seems right for a claim of readiness, and the numbers are easy to change.

What the brief had wrong, found while doing this:

- No account is created by an invitation, an import or an enrolment code. Every account is made
  by its owner signing up (`/register`, `/join`) or by an administrator; invitations and imports
  are addressed to an email address. So "came through an institution" is worked out at sign-up:
  the browser followed an invitation or an enrolment code, an invitation is waiting for the
  address, or the sign-up is the institution registration.
- `/certificates` needed opening as well as `/`: it lists institution credentials, and it was
  behind `library.view`.

`LibraryAccessTest` (5 tests), and `ReadinessTest` extended (5 tests): who starts with the
library, home for each kind of account, the administrator's switch, the grouped pages and the
switcher, every new readiness condition, a chosen list earning nothing, and an older certificate
staying valid.

Left out on purpose (ideas for later, not built):

- Asking for the library from an account without it (a request the administrator approves).
- Switching existing institution-only students' library off in bulk: accounts that existed
  before keep it, and an administrator can switch each one.
- A "Review today" entry point on home for due questions.
- Runtime renames from `examtopics` to `examlib` (Decision E).

## Phase 1: thin course layer and question import (delivered)

- **Course offering** (migration `046`, `courses`). Title, class (required, fixed once made),
  optional module, start and end dates, optional lead instructor, status. Learners come from the
  class roster and teachers from the class's instructors; nothing is enrolled twice. Who may do
  what is in `Courses`: anyone who sees every class sees every course; the class's instructors
  and people who manage classes run it; its learners read it. An instructor can start a course
  only for a class he teaches. Tabs: Overview, Assessments, Participants. Learners do not see
  each other's names.
- **Assessments link to a course** with `assessments.course_id`. Adding one also sets it for the
  course's class when the person may assign it; taking it out leaves who it is set for. Learners
  see only published assessments set for them.
- **Announcements** (`course_announcements`) go to each learner's inbox, and by email where they
  take it, through `Notifier`. A notice is removed, never edited, and the removed row is kept.
- **Timeline.** Home gains "Your courses" (with each course's latest notice) and "Coming up",
  built from dates that already exist: when assessments set for the person open and close, when
  assessments of courses they teach open and close, and when their courses start and end. No
  calendar view or external sync: the list was enough.
- **Bank import** (`QuestionImport`): CSV and Aiken, checked against the same rules as the
  question form (`QuestionBanks::validate`), previewed, then saved as drafts so they go through
  review. Problem rows download as a CSV. A question already in the bank, word for word, is not
  imported again, and the import is recorded once in the activity log rather than once per
  question.
- **Copy an assessment** as a new draft: settings, chosen questions and blueprint. Not copied:
  dates, assignments, course, and the question versions fixed at publishing.
- Courses sit under Teaching for staff and under their own Courses section for students.

`CoursesTest` (3 tests): the instructor's limits, notices reaching only the class, the learner's
view and home, people outside the class and the institution, archiving, every import rule, and
the copy.

Left out on purpose: learning materials and file uploads (Phase 3), forums, groups, a calendar
view.

