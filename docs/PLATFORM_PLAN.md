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
| 4 | Examination execution engine (first major milestone) | Next |
| 5 | Results and analytics | |
| 6 | Readiness assessment and certificates | |
| 7 | Integrity monitoring | |
| 8 | Live invigilation | |
| 9 | Formal examination capabilities | Only after pilot experience |

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

