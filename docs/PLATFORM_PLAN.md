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
| 2 | Question bank management | Next |
| 3 | Institutional examination builder | |
| 4 | Examination execution engine (first major milestone) | |
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

## Before Phase 2: decisions to make

1. **Classes before question banks?** Assessments are assigned to classes, and the minimum
   institutional release has student and class management (with CSV import) before question banks.
   Building classes first keeps Phase 3 unblocked.
2. **Who may register an institution?** Today any signed-in account can. It could instead need a
   platform permission, or a sign-up path of its own for people with no account.
3. **Students and the library.** An invited student gets an ordinary member account, which can read
   the certification library. Should institution-only students be kept out of it?
4. **Question types for the first release.** The specification starts with single choice, multiple
   select, true/false, short answer, numerical and essay. The minimum release needs only multiple
   choice; the rest can follow once the engine works.
