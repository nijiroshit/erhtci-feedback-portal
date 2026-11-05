# ERHTCI Feedback Portal — Project Reviewer

## 1. What the system is

ERHTCI Feedback Portal is a PHP and MariaDB web application. Students, staff,
and instructors can create accounts and submit feedback or complaints. Signed-in
users can read recent feedback, see its status and administrator response, and
view their own history. One privately created Admin account can identify
submitters, update report statuses, and respond.

The app has been tested locally with XAMPP and deployed to InfinityFree. The
hosted site, HTTPS, hosted API/database connection, Admin login, and main
features were tested successfully with demo data. The local XAMPP MariaDB on
the development laptop had a separate Aria table checksum/account permission
issue; this does not affect the hosted database.

The hosted copy is a demo/staging deployment. Use test data only; it has not
been approved as a production service for real school feedback.

## 2. The system from browser to database

```text
Browser
  HTML pages + styles.css
       |
       | events, forms, fetch requests
       v
  script.js
       |
       | HTTPS/HTTP requests to api/index.php
       v
Web server (Apache + PHP)
       |
       | PDO prepared SQL queries
       v
  MariaDB/MySQL database

Uploaded images: browser -> PHP upload validation -> uploads/ folder
Image viewing: signed-in browser -> PHP image action -> checked image bytes
```

### Front end

- The HTML files define page content and form fields.
- `styles.css` controls appearance and responsive layout.
- `script.js` connects forms and pages to the PHP API. It displays the API
  results but is **not** the security boundary.

### PHP API

- `api/index.php` is the server-side entry point. The `action` query parameter
  selects operations such as `bootstrap`, `login`, `register`, `feedbacks`,
  `profile`, `admin`, `status`, `response`, `submit-feedback`, and `image`.
- PHP validates submitted values, checks the signed-in session and role, and
  uses PDO prepared statements to communicate with the database.
- Errors are returned with HTTP status codes and JSON messages.

### Database

- `users` holds account names, roles, login identifiers, and password hashes.
- `feedback` holds the report, submitter ID, anonymous flag, status, response,
  optional image path, and timestamps.
- `feedback_status_history` records who changed a status and when.
- `auth_rate_limits` tracks hashed login/signup rate-limit keys.

The foreign key from feedback to users preserves the private link needed for a
submitter's profile history, even when the public display says “Anonymous.”

## 3. Main user flows

### Create an account and log in

1. A user selects Student, Staff, or Instructor on the signup page.
2. Students enter a student ID in the displayed format. Staff and Instructors
   enter an email address. The address is format-checked but is not restricted
   to an ERHTCI domain or verified by email.
3. The browser sends the information to the PHP API.
4. PHP validates the role, identifier length, and password, hashes the
   password, and inserts the account into `users`. Passwords require at least
   12 Unicode characters and are limited to 72 UTF-8 bytes.
5. At login, PHP looks up the identifier and verifies the password hash.
6. PHP creates a session. The browser uses that session cookie for later API
   requests.

Admin is not a public signup role. The single Admin account was created
privately during local setup.

### Submit and review feedback

1. A signed-in user submits a message, category, anonymous/reveal choice, and
   optionally a JPG, PNG, or WebP image up to 5 MB.
2. PHP validates the request and stores the report in `feedback`. The report
   stays linked to the account regardless of the public anonymous choice.
3. Recent Feedbacks displays a hidden name for anonymous reports. Signed-in
   users can see each report's status, administrator response, and attachment.
4. The Admin Dashboard returns submitter identities and allows the Admin to
   change status or save a response.
5. Profile only returns the signed-in user's own details and reports.

Anonymous means **name hidden from other signed-in users on public feedback
cards**, not hidden from the Admin or removed from the database.

## 4. Security protections currently implemented

- Passwords are stored as PHP password hashes, not plaintext.
- Admin is absent from public signup, and the schema permits only one Admin.
- Server-side checks require a signed-in session for private API actions and an
  Admin role for admin actions.
- State-changing requests require a session CSRF token.
- Login attempts are limited per account identifier and source IP. Signup
  attempts are limited per source IP. The database stores hashes of rate-limit
  keys rather than raw identifiers or IP addresses.
- Sessions use HTTP-only, SameSite cookies and expire after 30 minutes idle or
  12 hours total. The Secure cookie flag is enabled when the request uses HTTPS.
- SQL uses prepared statements; browser-rendered text is HTML-escaped.
- Uploads are type-checked and size-limited. Direct access to the uploads folder
  is denied by `.htaccess`; signed-in users retrieve images through the API.
- Database credentials are kept in `api/config.php`, which is excluded from
  the staging ZIP and ignored by `.gitignore`.

These are useful baseline controls, not a complete production security review.

## 5. What is working and what is not verified

### Tested locally in XAMPP

- The database schema and app database connection.
- Admin and regular account login.
- Signup for public roles.
- Feedback submission, anonymous/revealed display, and profile history.
- Admin identity visibility, status changes, and responses.
- Image upload and signed-in image access.
- Server-side denial of admin API access for regular users.
- Login throttling and denial of unauthenticated image access.
- A database backup restored successfully into a temporary test database, which
  was then removed.

### Hosted staging copy

- The site files were uploaded and extracted into the hosting `htdocs` folder.
- A separate hosted database was created and given the four required tables.
- A hosted `api/config.php` was created with the provider's database details.
- HTTPS was later confirmed to open without certificate warnings on both phone
  and laptop.
- The hosted API bootstrap returned successfully, and hosted Admin login and
  the application's main features were tested with demo data.
- This confirms the presentation/demo path only; use test data and don't treat
  the free-host deployment as production-ready.

## 6. Deployment concepts

- **Local development:** XAMPP runs Apache, PHP, and MariaDB on this computer.
  The local site is `http://localhost/erhtci/`.
- **Staging:** A separate copy on a host, using a separate hosted database.
  Staging should use test data.
- **Production:** The approved live school system. It needs a valid HTTPS
  certificate, protected credentials, reliable backups, and school-approved
  privacy/access rules.
- **DNS:** Connects a domain name to the hosting server. New domains or
  subdomains may take time to resolve.
- **TLS/SSL certificate:** Lets the browser verify the HTTPS site. Do not
  bypass a full-page certificate warning. The hosted staging site was checked
  and opened without a certificate warning during the demo test.
- **Database configuration:** Local XAMPP and hosted MySQL use different host,
  database name, username, and password. Never copy the local `config.php` to
  hosting; enter the provider's values in the hosted copy only.
- **Database schema:** Defines tables and relationships. `database/schema.sql`
  is for a fresh local database. `database/hosted-schema.sql` creates the
  application's tables inside an already-selected hosted database.
- **Migration:** A small SQL update for an existing database. Import
  `database/migrations/001_auth_rate_limits.sql` before deploying API code that
  uses its table.
- **Backup:** The SQL export protects database records. Uploaded images are
  files, so they need a separate file backup too.

## 7. Next deployment checklist

1. Recheck that the hosted site opens over HTTPS without a browser warning
   before each demo; do not click through certificate warnings.
2. The hosted API bootstrap endpoint, hosted database connection, and main
   demo flows have been tested. Do not share the CSRF token from the response.
3. A hosted Admin account has been provisioned and tested for the demo. The local
   setup scripts use local PHP/CLI and the local config; do not upload them as
   public web pages or point them at production without a safe method.
4. Test signup, login/logout, anonymous and revealed feedback, profile history,
   admin authorization, status/response updates, and image uploads on staging.
5. Confirm the hosted database user has only the required privileges and that
   credentials are not present in public files, ZIPs, or source control.
6. Test both a database backup restore and a separate uploads-file restore.
7. Decide whether institutional email verification is required; the current
   app only checks email format. Agree on who may read reports, what
   “anonymous” promises, data retention/deletion, and incident handling.
8. Only after staging passes and the school approves, plan the production
   domain, HTTPS, monitoring, backups, and launch.

## 8. Capstone questions to practice

**Why does the browser not connect directly to MySQL?**
The browser must not receive database credentials. PHP is the trusted middle
layer that validates input, enforces permissions, and runs database queries.

**Why is hiding the Admin link not enough?**
Browser navigation can be changed by a user. The PHP API must check the
session's role every time it serves admin data or changes a report.

**What does the anonymous option do?**
It hides the name in public feedback views while retaining the account link for
private tracking. Admin can identify the submitter. It is not fully anonymous.

**What does a password hash do?**
It stores a one-way verifier instead of the original password. At login, PHP
checks the entered password with `password_verify`.

**What does CSRF protection do?**
It requires a secret token tied to the user's session on state-changing
requests, helping prevent another site from silently submitting actions using
the user's browser session.

**Why is the site not ready while HTTPS warns?**
Without a trusted certificate, the browser cannot authenticate the server
connection. Do not send passwords, session cookies, or private feedback over a
connection that shows a certificate warning.
