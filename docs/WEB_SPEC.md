# ExamLib web app: what it does today

A description of the web app as it stands, written to discuss new features from. It covers what
each kind of person can do, every page and what it is for, how the pieces fit together, and the
limits and settings that shape it. The last section is a technical reference (routes, permissions,
data) for whoever builds the next features.

For installing and running the server, see [INSTALL.md](INSTALL.md) and
[OPERATIONS.md](OPERATIONS.md); for how it is built, [ARCHITECTURE.md](ARCHITECTURE.md) and
[DATA_MODEL.md](DATA_MODEL.md).

Contents:

1. [What it is](#1-what-it-is)
2. [Who uses it](#2-who-uses-it)
3. [Getting in: accounts and sign-in](#3-getting-in-accounts-and-sign-in)
4. [Finding exams: library, search and providers](#4-finding-exams-library-search-and-providers)
5. [Studying](#5-studying)
6. [Progress and the dashboard](#6-progress-and-the-dashboard)
7. [Members' discussion and reports](#7-members-discussion-and-reports)
8. [Getting new exams: requests and collecting](#8-getting-new-exams-requests-and-collecting)
9. [Keeping the content right](#9-keeping-the-content-right)
10. [Documents (PDF and Markdown)](#10-documents-pdf-and-markdown)
11. [Images](#11-images)
12. [Notifications](#12-notifications)
13. [Running the system](#13-running-the-system)
14. [Look and feel](#14-look-and-feel)
15. [Limits and rules worth knowing](#15-limits-and-rules-worth-knowing)
16. [Not built, or on hold](#16-not-built-or-on-hold)
17. [Technical reference](#17-technical-reference)

---

## 1. What it is

A private website for studying certification exams. Exams (questions, choices, answers,
explanations and the community discussion) are collected from ExamTopics by a command-line tool
that runs on the server, and kept in a library. People then read them, practise them, take timed
mock exams, track their progress, and discuss them. A small group of people keeps the content
right: they correct answers, fix wording, add missing pictures and deal with problems members
report.

The site is never public: every page except signing in, signing up and resetting a password
needs an account.

## 2. Who uses it

There are two roles. On top of a role, an administrator can give one person extra permissions.

| Role | What they do |
|---|---|
| **Member** | Reads and practises exams, takes mock exams, keeps notes and bookmarks, discusses questions, reports problems, requests exams that are not in the library yet, and loads the exam list of a provider nobody has loaded before. |
| **Administrator** | Everything: collecting exams, correcting content, managing accounts, settings, backups and security. |

The permissions an administrator can hand out one by one:

| Permission | Lets a person |
|---|---|
| `library.view` | Browse providers and the library, read and practise, download documents. *(members have it)* |
| `exams.request` | Request exams not collected yet. *(members have it)* |
| `inventory.first` | Load the exam list of a provider never fetched before. *(members have it)* |
| `documents.build` | Build and delete PDF and Markdown documents. |
| `answers.edit` | Correct answers and questions, add pictures, see the Integrity page, deal with reports, see correction history and carry corrections to other editions. |
| `exams.collect` | Collect exams, refresh any provider, add providers, download images, see jobs. |
| `library.manage` | Delete old versions of an exam, and choose which exams share a card as editions. |
| `requests.manage` | See and answer every exam request, and be told of new ones. |
| `users.manage` | Create, edit, approve and disable accounts, and be told of sign-ups. |
| `settings.manage` | Change system, email and branding settings. |
| `backup.manage` | Make, download and restore backups. |
| `progress.view_all` | See every member's study progress. |
| `activity.view` | See the activity history. |
| `security.manage` | Review sign-in attempts, unlock accounts, unblock networks, and be told when either is locked. |

Pages and buttons a person may not use are hidden from them, and the server refuses them anyway.

## 3. Getting in: accounts and sign-in

- **Signing up** depends on the setting an administrator chooses:
  - *Open*: anyone signs up, confirms their email with a code, and becomes a member.
  - *Needs approval*: the same, then an administrator approves the account.
  - *Closed*: only administrators create accounts.
- **Signing in** is by username or email and password. A forgotten password is reset by an
  emailed link.
- **Two-step sign-in** (an authenticator app code) can be turned on from the account page, with
  one-time recovery codes to print or save. An administrator can turn it off for someone who has
  lost their phone.
- **Confirming it is you**: backups and deleting accounts ask for a two-step code (or the
  password, when two-step is off) again, valid for a few minutes.
- **Protection against guessing**: an account is locked for a while after repeated wrong
  passwords, and a network address that fails many times across different names is refused for
  half an hour. Shared addresses (an office or school where several people sign in) and
  Cloudflare's own addresses are never blocked. Sign-in records never store a mistyped password.
- **Sessions** end after a period of inactivity set by an administrator; each person can see
  their recent sign-ins and failed attempts on their account page.
- **Account page** (`/account`): name and email (a new email is confirmed with a code), password,
  two-step sign-in, recent sign-ins, email notifications on or off, timezone, and appearance.

People are addressed by a random id in every web address, never by their username.

## 4. Finding exams: library, search and providers

### Library (`/library`)
- Opens on **shelves**: one shelf per provider, one card per exam. Different editions of the same
  exam (for example SAA-C02 and SAA-C03) share a card, newest first, so the library is not
  cluttered with near duplicates. Each card has Read and Practice.
- A **Table** view lists one row per exam with every detail (versions, documents, when
  collected); the choice of view is remembered per person. Exams collected more than 90 days ago
  are marked as older for those who collect.
- A search box narrows the cards as you type.
- **Editions** are grouped automatically by name. Someone with `library.manage` can show an exam
  on its own card, group it with another, or choose which edition a card shows first.
- **Save** puts an exam on the person's own shelf.

### An exam's page (`/library/<provider>/<exam>`)
Read, Practice and Mock exam buttons; the version shown and its history (each collection is kept
as a version, and the best complete one is used by default); question count compared with what
ExamTopics lists; images saved; answers to check and corrections made (for those who correct);
the documents for this version; editions; the person's own progress; the JSON data file to
download.

### Search questions (`/search`)
Searches every exam in the library for words in a question or its choices. Every word must
appear; case does not matter. Results are grouped by exam with the matching words highlighted,
and each links to read or practise the question. At most 200 questions are listed. It searches
the content as corrected.

### Providers (`/providers`)
Every exam provider ExamTopics lists (Amazon, Microsoft, Cisco, Huawei and so on) and the exams
each has. A member can load the exam list of a provider nobody has loaded yet; refreshing a list
again is for those who collect.

### Command palette
Ctrl+K (or Cmd+K) opens a "go to" box for any page or exam.

## 5. Studying

### Study mode (`/library/<provider>/<exam>/view`)
Questions ten to a page with their pictures, choices, the answer (marked on the choices), where
the answer came from (ExamTopics' suggestion, the community vote, or a correction), the community
vote tally, the explanation, the ExamTopics discussion, and the members' own discussion. Search
within the exam and "go to question N" are in the toolbar. The page you were on is remembered,
so the library and dashboard offer to continue.

### Practice mode (same page, `?mode=practice`)
The page itself holds no answers at all, not even hidden. You pick your answer by clicking the
choices, then **Reveal answer**: the answer, explanation and both discussions appear, your picks
are marked right or wrong, and the result counts towards your progress. Filters:
- **Due today**: questions due for review (see spaced review below).
- **My mistakes**: questions you last answered wrong.
- **Bookmarked**: questions you bookmarked.

### Timed mock exams (`/library/<provider>/<exam>/mock`)
- Choose the questions (all, ones not answered yet, mistakes, due for review, or bookmarked), how
  many, and a time limit (1.5 minutes a question is suggested) or none.
- Questions come in random order, one at a time, with a numbered row to move around and a "mark
  to look at again" flag. No answers are in the page.
- Answers are saved as you go, so closing the page or losing the connection loses nothing. The
  clock is kept by the server and hands the exam in at zero.
- The result shows the score, right, wrong and unanswered, and every question with your pick and
  the answer, with a filter for the ones wrong or not answered.
- Only questions whose answer is a set of letters are asked (drag-and-drop and hot-area questions,
  whose answer is a picture, cannot be graded).
- Results are private to the person; answers count towards progress.

### Spaced review
Every question you answer comes back for review: answered right when due, it returns after 2
days, then 4, 8, 16 and 32; answered wrong, the next day; answered right before it is due,
nothing changes. What is due shows in practice mode, on the dashboard and as a mock exam source.

### Notes and bookmarks
A bookmark beside each question number and a note under each question. Both are private: nobody
else sees them, administrators included.

## 6. Progress and the dashboard

### My progress (`/progress`)
Per exam: questions answered, percentage right, mistakes to go over, last studied and where; a
reset button per exam; recent mock exam results. Administrators with `progress.view_all` see
**Everyone's progress** and each member's page (read only).

### Dashboard (`/`)
The person's own studying first:
- a greeting, their **streak** (days in a row studied, in their own timezone) and best streak;
- this week's answers and percentage right, and a 14-day chart;
- **Continue studying** (where they left off), **Due for review today**, **Mistakes to go
  over**, their **Saved** exams;
- **New in the library**, marking exams from providers they study;
- their exam requests (or, for those who answer them, requests waiting).

Below, for those who run the system: library totals, active and recent jobs, failed jobs, recent
documents, who studied this week, sign-ins in the last day, and recent activity.

## 7. Members' discussion and reports

### Members' discussion
Under each question in study mode, beside the discussion collected from ExamTopics:
- Anyone can post. A comment may also pick the answer the person would choose; that is their
  **vote**, and only their latest pick counts. The tally shows with the answer ("Members' votes
  here: C (3), B (1)").
- When at least two members back a different answer than the one shown, and it has more votes,
  the question appears under **Answers to check** for the people who correct content.
- In practice mode the members' discussion arrives with the revealed answer, never before.
- A comment can be deleted by its author or by anyone who corrects content. Posting is limited to
  30 comments an hour per person.

### Report a problem
On every question, in both modes: say what is wrong (the answer, the wording or a choice, a
picture, something else) and give details. Everyone who corrects content is told by inbox and
email; they see it in the reader (**Reported** filter) and on the Integrity page, and **Mark
dealt with** tells the member. Limited to 20 reports an hour per person.

## 8. Getting new exams: requests and collecting

### Exam requests (`/requests`)
A member asks for an exam that is not in the library yet. Those who answer requests are told,
and mark it collected or declined (with a reply); the member is told either way. A person has at
most one open request per exam, and a request for an exam already in the library is refused.

### Collecting (`/collect`, `exams.collect`)
- Pick a provider and exam, choose the source (the public cached dataset when it has the exam,
  or a live scrape for the newest discussions) and the pace, and add it to the queue. Several can
  be queued one after another without leaving the page; each button turns into a live status
  chip.
- Question links found elsewhere (for example in search results) can be pasted in to help find
  questions the listing misses.
- **Jobs** (`/jobs`) shows the queue and history: progress, a live log, cancel, and why a job
  failed. One job runs at a time, paced politely; a background worker runs them.
- A new collection becomes a new **version** only after it passes validation; the version before
  stays available until then. A collection that could not get every question is kept as a
  *partial* version.
- Provider lists are refreshed the same way (inventory refresh), and new providers can be added
  or discovered.

## 9. Keeping the content right

For people with `answers.edit`.

### Correcting answers
In study mode each question has **Correct this answer**: tick the right letters and add a note.
Saving the collected letters unchanged marks the answer **checked**. Corrections apply to every
version of the exam, survive collecting it again, and appear in documents made afterwards; the
collected data is never changed.

### Answers to check
The reader flags answers that look doubtful: no answer collected; the question asks for two
answers but has one; ExamTopics and the community disagree; few votes; most comments name
another answer; members here voted otherwise. **To check** in the reader shows only those.

### Editing questions
**Edit this question** fixes the wording and each choice, removes broken pictures and uploads
new ones (PNG, JPEG, GIF or WebP, up to 5 MB) for the question or its answer. A `[picture]` line
keeps a picture in place when the wording changes. Edits behave like corrections: shown over the
collected data, kept across collections, used in documents, and listed on the Corrections page,
where **Put back** shows the question as collected again.

### Corrections page (`/library/<provider>/<exam>/corrections`)
Every answer corrected, checked or noted, and every question edited, for one exam.
- **History**: every change to a question's corrections is kept (who, when, before and after).
  **Step back one edit** undoes the latest; **Put back how it was before** goes back further.
  Putting back is itself recorded.
- **Carry to other editions**: finds each corrected question in the exam's other editions (by
  identical or nearly identical wording) and shows what carrying would do before anything
  changes. An answer is carried by the wording of its choices, so a different choice order cannot
  misplace it; a question edit only goes to a question that reads the same. Only what is ticked is
  carried.

### Integrity page (`/integrity`)
One page for everything in the library that needs attention, with totals at the top and a
filter per kind:
- answers to check (and how many have no answer at all);
- reports from members;
- questions missing compared with what ExamTopics lists (with "Collect again");
- images not saved (download from the page);
- documents made before the latest corrections or images;
- partly collected exams, and exams whose data file cannot be read.

Its figures are worked out in the background and kept per exam until the exam or its corrections
change, so the page opens at once; while exams are being checked it says so and refreshes itself.

## 10. Documents (PDF and Markdown)

Each exam version can be turned into a document, in four variants, each as PDF or Markdown:

| Variant | Contains |
|---|---|
| Study | Questions with answers and explanations |
| Discussions | Questions with answers and the discussion |
| Practice | Questions only, answers hidden |
| Practice with key | Questions, then an answer key at the end |

- PDFs have a cover, contents, bookmarks and the saved images; large exams are made in parts
  (500 questions each by default) so the server never runs out of memory.
- PDFs are built in the background with progress shown; Markdown is made at once.
- A document is reused while nothing has changed, and offered for rebuilding when it was made
  before corrections, before more images were saved, or with an older layout.
- Anyone with `library.view` downloads documents; `documents.build` builds and deletes them.
- Documents include corrections and question edits, but not the members' discussion, votes,
  notes or reports.

## 11. Images

- After each collection the server downloads the questions' images from ExamTopics and keeps one
  copy of each, so the reader and PDFs do not depend on ExamTopics serving them.
- Images that failed can be downloaded again from the exam page or the Integrity page.
- Pictures uploaded by hand are kept apart, never removed by image cleanup, and always included
  in backups (they cannot be fetched again).

## 12. Notifications

- **Inbox** (the bell): sign-ups waiting, exam requests and answers to them, question reports and
  their outcome, and security alerts (an account locked, a network blocked, a possible stolen
  password). Notifications about the same thing are folded into one, and are marked read once
  dealt with.
- **Email**: the same notices, sent only to confirmed addresses of people who have not turned
  email off, with the site's logo and name. Sign-up codes and password resets also go by email.
  Email needs an SMTP server set on the Email settings page.

## 13. Running the system

| Page | For | What it does |
|---|---|---|
| **Users** (`/users`) | `users.manage` | Create, edit, approve, disable and delete accounts; set role and extra permissions; turn off two-step; choose the sign-up mode. |
| **Activity** (`/activity`) | `activity.view` | A plain-English history of sign-ins and of changes to accounts, settings, the library, corrections, documents, requests and backups, filterable, exportable to CSV, and tamper-evident (each entry is chained to the one before, and the chain can be verified). |
| **Sign-in attempts** (`/security/sign-ins`) | `security.manage` | Failed and suspicious sign-ins, flagged when a success follows many failures; unlock accounts, unblock networks. |
| **Backups** (`/backups`) | `backup.manage` | One zip with every table, the exam data, uploaded pictures, logo and optionally the saved images and documents. Made, uploaded (in pieces, resumable), downloaded and restored from the page, in the background; a safety backup is made before each restore. |
| **Settings** (`/settings`) | `settings.manage` | System status (tool version, worker, migrations), timezone and session length, sign-in security. |
| **Branding** (`/settings/branding`) | `settings.manage` | Site name, logo and colour. |
| **Email** (`/settings/email`) | `settings.manage` | SMTP server and a test message. |

Behind the pages: a background worker runs jobs; a detached process builds large PDFs, makes and
restores backups, and works out the Integrity figures and the search index; a scheduled cleanup
removes old working files, stale sign-up codes and unused images.

## 14. Look and feel

- One layout everywhere: a sidebar of the pages the person may use, a top bar with the command
  palette, the job status and the inbox.
- Light and dark themes (or follow the device), six accent colours, three densities, two surface
  styles, and reduced motion, chosen per person on the account page and saved to their account.
- Works on phones: tables turn into cards, and no page scrolls sideways.
- Every list is a sortable, searchable table with column choices and CSV export, its settings
  remembered per person.
- Times are shown in each person's own timezone.

## 15. Limits and rules worth knowing

- **One collection at a time**, paced politely, so ExamTopics is never hammered. Pace and retries
  are set on the server.
- **What can be graded**: only questions whose answer is a set of letters. Drag-and-drop and
  hot-area answers are pictures ExamTopics does not show on discussion pages.
- **Corrections never change collected data**: they sit on top, per exam, across all its versions.
- **Privacy**: notes, bookmarks and mock exam results are visible only to their owner. Progress
  is visible to the owner and to holders of `progress.view_all`. Members' comments, votes and
  reports are seen by others (reports only by those who correct content).
- **Rate limits**: 30 comments and 20 reports an hour per person; sign-in and sign-up attempts are
  limited per account and per network.
- **Upload size**: pictures up to 5 MB, also capped by the server's PHP upload limit.
- **Search**: up to six words of at least two letters; at most 200 results.
- **Security**: strict content security policy (no inline scripts or styles, all assets served
  from the site itself), CSRF protection on every form, prepared database queries, secrets kept out
  of logs and public folders.

## 16. Not built, or on hold

- **Automatic refresh of old exams**: on hold by choice. Exams older than 90 days are only marked.
- **Members' discussion in documents**: documents leave out members' comments and votes.
- **Comment upvotes and replies**: members' comments can carry a vote for an answer, but cannot be
  upvoted or replied to directly.
- **Editing a posted comment**: comments can be deleted, not edited.
- **More roles**: only Member and Administrator, plus per-person extra permissions; no "editor"
  role exists as such.
- **Sharing mock exams or results** between people, leaderboards, or groups and classes.
- **A public or read-only API** for other apps.
- **SMS, push or chat-app notifications**: inbox and email only.

## 17. Technical reference

### Pages and actions

`can:` is the permission a route needs; `signed-in` means any account; *public* needs none.

| Area | Method and path | Needs |
|---|---|---|
| Sign-in | `GET/POST /login`, `/register`, `/verify`, `/forgot`, `/reset`; `POST /verify/resend` | public |
| | `GET/POST /login/two-step`, `POST /login/two-step/cancel` | public |
| | `POST /logout`; `GET/POST /confirm` | signed-in |
| Account | `GET /account`; `POST /account/profile`, `/password`, `/time`, `/email/send`, `/email/verify`, `/notifications` | signed-in |
| | `POST /account/two-step/begin`, `/confirm`, `/cancel`, `/off`, `/codes/done`, `/codes/new`; `GET /account/two-step/codes`, `/codes.txt` | signed-in |
| Appearance | `POST /settings/appearance`, `/api/appearance` | signed-in |
| Inbox | `GET /inbox`, `/inbox/{id}`; `POST /inbox/read` | signed-in |
| Dashboard | `GET /` | library.view |
| Library | `GET /library`, `/search`, `/api/palette`, `/library/{p}/{e}`, `/library/{p}/{e}/{version}/json` | library.view |
| | `POST /library/{p}/{e}/save`, `/progress/reset` | library.view |
| | `POST /library/{p}/{e}/editions`, `/library/{p}/{e}/{version}/delete` | library.manage |
| Reader | `GET /library/{p}/{e}/view`; `GET /api/library/{p}/{e}/{version}/questions/{n}`, `/answers`; `POST .../questions/{n}/attempt` | library.view |
| Notes | `POST /library/{p}/{e}/notes`, `/notes/bookmark` | library.view |
| Discussion | `POST /library/{p}/{e}/discussion`, `/discussion/{id}/delete`, `/reports` | library.view |
| | `POST /library/{p}/{e}/reports/resolve` | answers.edit |
| Mock exams | `GET/POST /library/{p}/{e}/mock`; `GET /mock/{uuid}`; `POST /mock/{uuid}/finish`, `/discard`, `/api/mock/{uuid}/answer` | library.view |
| Corrections | `POST /library/{p}/{e}/answers`, `/questions` | answers.edit |
| | `GET /library/{p}/{e}/corrections`; `GET/POST .../corrections/history`, `.../corrections/editions` | answers.edit |
| Integrity | `GET /integrity`, `/api/integrity` | answers.edit |
| Progress | `GET /progress` | library.view |
| | `GET /progress/everyone`, `/progress/people/{ref}` | progress.view_all |
| Documents | `GET /documents/{uuid}/download`, `/api/documents/{uuid}` | library.view |
| | `POST /library/{p}/{e}/{version}/documents`, `/documents/{uuid}/delete` | documents.build |
| Media | `GET /media/{hash}` | library.view |
| Providers | `GET /providers`, `/providers/{slug}`; `POST /providers/{slug}/refresh` | library.view (a first load needs inventory.first; refreshing a loaded list needs exams.collect) |
| | `POST /providers/discover`, `/providers/add`; `GET /api/providers/{slug}/exams` | exams.collect |
| Collecting | `GET/POST /collect`; `POST /collect/links`, `/collect/links/clear`; `POST /library/{p}/{e}/{version}/images` | exams.collect |
| Jobs | `GET /jobs`, `/jobs/{uuid}`, `/api/jobs/active`, `/api/jobs/{uuid}/log`; `POST /jobs/{uuid}/cancel` | exams.collect |
| | `GET /api/jobs/{uuid}` | library.view |
| Requests | `GET/POST /requests`; `POST /requests/{uuid}/cancel` | exams.request |
| | `POST /requests/{uuid}/answer` | requests.manage |
| Users | `GET /users`, `/users/new`, `/users/{ref}`; `POST /users`, `/users/{ref}`, `/users/{ref}/delete`, `/approve`, `/two-step/off`, `/users/registration` | users.manage |
| Activity | `GET /activity`, `/activity.csv`; `POST /activity/verify` | activity.view |
| Security | `GET /security/sign-ins`; `POST /security/sign-ins/unlock`, `/unblock`, `/flags/clear-all`, `/flags/{id}/clear`, `/flags/{id}/restore` | security.manage |
| Backups | `GET /backups`, `/api/backups/status`, `/backups/{name}/download`; `POST /backups`, `/backups/cancel`, `/backups/upload`, `/backups/uploads[...]`, `/backups/{name}/restore`, `/backups/{name}/delete` | backup.manage |
| Settings | `GET /settings`, `/settings/branding`, `/settings/email`; `POST /settings/reconcile`, `/settings/time`, `/settings/branding`, `/settings/branding/logo/remove`, `/settings/email`, `/settings/email/test` | settings.manage |
| Branding | `GET /branding/theme.css`, `/branding/{file}` | public |

### What is stored

| Data | Tables |
|---|---|
| People | `users` (role, extra permissions, preferences, timezone), `user_totp`, `totp_recovery_codes`, `account_codes`, `login_attempts`, `blocked_networks`, `sign_in_flag_clearances` |
| Exams | `providers`, `exams` (edition grouping), `cache_versions` (each collection; the questions themselves are JSON files under `storage/cache`), `inventory_refreshes`, `jobs` |
| Corrections | `answer_edits`, `question_edits`, `uploaded_images`, `correction_history` |
| Members | `question_comments` (with answer votes), `question_reports`, `question_notes` (notes and bookmarks) |
| Studying | `study_answers` (latest answer per question, with spaced review box and due day), `study_days`, `study_places`, `saved_exams`, `mock_exams` |
| Output | `documents`, `media` (saved images) |
| Worked out, rebuilt on demand (not backed up) | `exam_checks` (Integrity figures), `question_index` (search) |
| System | `settings`, `notifications`, `mail_queue`, `exam_requests`, `audit_log`, `throttle_events`, `schema_migrations` |

Migrations `001` to `026` in `web/database/migrations` create all of it; `php bin/migrate.php`
applies them.

### Background processes

| Process | Started by | Does |
|---|---|---|
| `bin/worker.php` | systemd, or on demand by the web app | Runs collection, inventory and image jobs, one at a time. |
| `bin/build-document.php` | building a PDF | Builds one PDF, reporting progress. |
| `bin/backup.php` | the Backups page, or cron | Makes and restores backups. |
| `bin/integrity.php` | the Integrity and Search pages | Works out Integrity figures and the search index for exams that changed. |
| `bin/send-mail.php`, `bin/reconcile.php` | cron, every 5 minutes | Sends queued email; tidies jobs left behind by a restart. |
| `bin/cleanup.php` | cron, nightly | Removes old job files, stale codes and uploads, and (with `--media`) unused images. |
