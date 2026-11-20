# Student Voice

Students give anonymous feedback through polls set by the school. Each student votes with their own
voting number, once per poll, and nobody (not even an admin or someone reading the database)
can tell which student gave which answers. The first school is St. Mark's College Namagoma.

## Stack

The same as SACCO and Tusome:

- **frontend/**: Vue 3 + TypeScript + Tailwind CSS (Vite)
- **backend/**: plain PHP 8.2 API (no framework, no Composer packages) + MySQL/MariaDB
- Runs on XAMPP locally and on cPanel (Apache) in production

```
backend/
  public/index.php      API entry point; every /api/... request lands here
  routes.php            all API routes, and who may call each
  src/                  PHP classes (namespace StudentVoice\ -> src/)
    Auth/               admin and student sign-in, login throttle
    Controllers/        Auth, Students, Polls, Votes, Results, Admins
    Services/SmisSync   the SMIS student list -> students
    Support/            migrations, voting numbers, poll rules
  database/migrations/  numbered SQL files (never edit one once committed; add a new number)
  bin/                  migrate, create-admin, sync-students
frontend/src/
  pages/vote/           the student side: voting number, my polls, the poll
  pages/admin/          overview, polls, poll editor, results, students, slips, admins
legacy/                 the first plain-PHP version (not served; kept for reference)
```

## Local setup (XAMPP)

1. The project lives in `C:\xampp\htdocs\studentvoice`, so Apache serves it at http://localhost/studentvoice.
2. Copy `backend/.env.example` to `backend/.env` (the defaults suit XAMPP).
3. Create the database and tables: `php backend/bin/migrate.php`
4. Make your super admin: `php backend/bin/create-admin.php --name="Your Name" --email=you@example.com`
   It prints a temporary password; sign in at http://localhost/studentvoice/admin and choose your own.
5. Bring in the students: Students → **Sync from SMIS** (or `php backend/bin/sync-students.php`),
   then the teachers: Teachers → **Sync from Tusome** (see "Linking Tusome" below).
6. Frontend: `cd frontend`, `npm install`, then either
   - `npm run dev` → http://localhost:5175 (Vite forwards `/api` to XAMPP), or
   - `npm run build:local` → the built app at http://localhost/studentvoice

(`php` is `C:\xampp\php\php.exe` if it isn't on your PATH.) **After every pull** run `php backend/bin/migrate.php`.

## How it works

**Students and voting numbers.** Students come from the school's SMIS (the same API Tusome syncs
from: RegNo, Name, Class, Stream). Each new student gets a random voting number like `K7PM-4QXT`
(no 0/O or 1/I/L, so it can be read aloud). It is random, not the RegNo, so classmates who know each
other's RegNo can't vote for each other. A sync never changes a voting number; students no longer in
SMIS can't vote; a student who returns keeps their number. Students not yet in SMIS can be added by
hand. **Print voting slips** gives cut-out slips (10 per A4 page) or a class list for each class
or stream. A lost slip: **New number** on the Students page (the old one stops working; votes already
cast still count, and the student still can't vote twice).

**Polls.** An admin writes a poll's questions, chooses which classes vote, can set a closing time,
and opens it. Answer types: agree/disagree 1–5, pick one, pick any, yes/no, written answer. Questions
can have section headings and can be pasted in many at a time. Once anyone has voted, questions can
only be reworded (so every ballot answered the same questions); **Make a copy** to change more, or
to run the same poll next term. A poll with votes can be closed but never deleted. The paper
"Students' Voice 360° Teaching & Learning" form is loaded twice as ready-made drafts: a school-wide
version and a **Teacher feedback** version answered for each teacher.

**Once, and anonymous.** Voting writes two unrelated things in one transaction:
- `voters` (poll + student): who has voted. Its primary key is what makes a second vote impossible,
  even if a student presses Send on two devices at the same moment.
- `ballots` + `answers`: what was answered, with only the class, stream and day. No student, no
  time of day, and random ids instead of counters, so a ballot can't be matched to a voter by order
  or time.

Results never show a class or stream with fewer than 5 ballots, and written answers are shown in
random order. When a student has nothing left to answer, they are signed out at once so the next
student can use the same computer; any student session also ends after 15 minutes without activity.

**Teacher polls (grouped by teacher).** A poll can be about *the school* (each student answers
once) or about *each teacher* (each student answers once for each of their own teachers).
Who teaches whom comes from **Tusome** (Teachers → **Sync from Tusome**): its teachers, their
subjects, and which subjects each student takes. The **Teaching list** (one grid per class: subjects ×
streams) says who teaches each subject in each stream; a subject with only one teacher is filled in
automatically, and super admins set the rest (a sync never changes their choices; other admins can
only view it).

**Upload from Excel** (Teachers page, super admins): a sheet of teachers with their subjects and
streams fills the Teaching list in one go. It reads St Mark's timetable directory (Teacher Name ·
Department · Subjects Taught · All Streams Taught or S1-S6 Streams), a ✓ grid with a column per
stream, or a plain Teacher · Subject · Class · Stream list. Names are matched to Tusome's whatever the
word order and with small spelling slips (MARVIN ESETE = Esete Marvin, CHEPTOEK = Chepteok); anyone
not found is added. Elective blocks (AGR/F.ART/LUG/TD) and CRE/IRE are settled from Tusome's record
of the teacher, or the subject common to all their blocks. A teacher with several subjects gets each
only where students take it and where another teacher doesn't already cover it; English is O-Level
only, General Paper and Subsidiary Maths A-Level only. A preview shows matches, new teachers,
anything unclear and streams where several teachers share a subject, then **Replace** (the file's
streams get exactly its teachers) or **Only fill gaps**. Teachers added from a file are kept by
Tusome syncs.

What a student does: their subjects show as tiles - their own subjects (per Tusome) first, then
**Electives, only if you take them** (optional; once their own subjects are done they can finish).
A subject with several listed teachers shows them as cards: the student rates theirs, then "I'm done". They tap one, **choose their teacher from that
subject's department** (already chosen when the Teaching list or Tusome names one; they can change it),
then answer **one statement at a time** with big 1-5 buttons that move on by themselves, with the
optional comments together at the end. "I don't take this subject" closes a subject. Choosing someone
other than the listed teacher, or not taking the subject, counts as "not my teacher" for the listed
teacher, so admins can correct the list. A subject with two teachers listed for a stream asks for both.

**Statements per teacher** (poll setting): e.g. 8 of the 20. Each student gets a different mix spread
across the sections (fixed for that student and subject), so every statement is still answered by many
students and averages stay fair, while each rating takes about a minute. School polls use the same
one-question-at-a-time screens.

Teacher results (admins only; teachers have no account and never see them):
- **Ranking table**: each teacher and subject with ratings / students listed, an overall score,
  a score per section, change since the previous teacher poll and "not my teacher" counts; sort any
  column, switch to **By subject** (with subject averages), spreadsheet and print.
- **Teacher report** (one page, printable): overall and per-area scores against the school average,
  strongest and weakest statements, every statement, scores by class and stream, a trend across
  teacher polls, and the written comments.
- Teachers with fewer than 5 ratings show counts only, never scores.

"Once per teacher" is enforced with a fingerprint (HMAC of poll + student + teacher + subject with
`APP_KEY`) instead of the student's id, so the database shows how many students rated a teacher but
not which ones. **Never change or lose `APP_KEY`** while a teacher poll is open.

Linking Tusome: put the same random key (32+ characters) in Tusome's `backend/.env` as
`STUDENT_VOICE_API_KEY` and here as `TUSOME_API_KEY`, and set `TUSOME_TEACHING_API_URL` to Tusome's
`/api/integrations/student-voice/teaching` address. With no key set, Tusome's feed stays switched off.

**Admins.** Super admins manage the admin list (Admins page). New admins and password resets get a
temporary password shown once, which must be replaced at first sign-in.

## Deploying (cPanel)

**Live:** https://studentvoice.stmark.sc.ug on server1, folder `~/public_html/studentvoice`, cPanel Git
Version Control "Student Voice" (clone at `~/repositories/StudentVoice`). To release: push to GitHub,
then in cPanel **Update from Remote** and **Deploy HEAD Commit**. `.cpanel.yml` runs
`deploy/deploy.sh`, which builds the frontend for the subdomain, backs up the database to
`~/private_backups`, copies the app while keeping the server's own files (`backend/.env`,
`backend/storage/`, cPanel's PHP block in `.htaccess`, saved as
`~/private_backups/studentvoice.htaccess.cpanel-orig`) and runs the migrations. Over SSH:
`bash ~/repositories/StudentVoice/deploy/deploy.sh`. The committed `frontend/dist` is the local
(`/studentvoice/`) build and is never copied to live.

A first setup on a new server:

1. Create the MySQL database and user in cPanel; put them in `backend/.env` on the server with
   `APP_ENV=production` and `APP_DEBUG=false`.
2. `php backend/bin/migrate.php`, then `php backend/bin/create-admin.php ...`.
3. Build the frontend for the address it will live at (`npm run build` for a domain's root, or
   `vite build --base=/folder/`) and upload `frontend/dist` with the rest.
4. Optional nightly cron: `php /path/to/backend/bin/sync-students.php`.

The root `.htaccess` sends `/api/...` to the PHP API, everything else to the Vue app, forces HTTPS
off localhost, and keeps `backend/` and `legacy/` private.
