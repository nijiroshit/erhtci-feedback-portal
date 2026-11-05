ERHTCI FEEDBACK PORTAL
=====================

Current configuration
---------------------
This project uses PHP and MySQL/MariaDB through XAMPP. The pages must be
opened through Apache, not file preview or the static start-server.ps1.
Accounts, feedback, status updates, and administrator responses are stored in
the erhtci_feedback database.

Public account roles are Student, Staff, and Instructor. Student accounts use
an institutional ID; Staff and Instructor accounts use an email address.
Email addresses are format-checked but are not restricted to a school domain or
verified by email. Passwords require at least 12 Unicode characters and may
not exceed 72 UTF-8 bytes.
Admin is not offered in signup. One administrator account is provisioned
privately with create-admin.ps1. The administrator can see submitter names,
including on submissions marked anonymous. Anonymous submissions remain
linked to their account for private profile tracking; their names are hidden
from the recent-feedback page. Signed-in users can see each report's status
and administrator response on the recent-feedback page.

Run locally with XAMPP
----------------------
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Copy this project folder to C:\xampp\htdocs\erhtci. Keep editing the
   workspace copy in VS Code, and copy any changes to htdocs before testing.
3. In the htdocs copy, duplicate api\config.example.php as api\config.php.
   Edit db_pass to the password you chose for the erhtci_app MySQL user.
   Do not send the password in chat or commit api\config.php.
4. For an existing database, import the new SQL files in database\migrations
   through phpMyAdmin before copying in API updates that require those tables.
   The initial schema includes the tables for a fresh database.
5. Open http://localhost/erhtci/ in the browser. Confirm the home page loads
   and that the browser's Network tab shows a successful response from
   api/index.php?action=bootstrap.
6. To create the one administrator account, open PowerShell in the htdocs
   copy and run:

       powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\create-admin.ps1

   Enter the administrator name, email, and a password with at least 12
   characters and no more than 72 UTF-8 bytes. The password prompt is hidden.
   Admin creation is allowed only once; there is no public admin signup or
   password-reset flow.
7. Create a Student, Staff, or Instructor account from the signup page, then
   test login, anonymous/revealed feedback, profile history, and admin updates.

Database
--------
- database\schema.sql creates the erhtci_feedback database and its tables.
- The database user should be limited to this database with SELECT, INSERT,
  UPDATE, and DELETE privileges. Do not use MySQL root for the application.
- api\config.php is ignored by git. Keep its credentials private.
- Passwords are stored as PHP password hashes. Session-based API actions use
  CSRF tokens; page redirects are for navigation only, not authorization.
- Login/signup requests are rate-limited. Login attempts are limited per
  account identifier and source IP; signup attempts are limited per source IP.
  The limiter stores hashed keys, not raw login names or IP addresses.
- Signed-in sessions expire after 30 minutes without activity and after 12
  hours total.
- After importing updates into an existing database, import each new SQL
  migration from database\migrations before deploying code that needs it.
- The API validates feedback, restricts image uploads to JPG/PNG/WebP up to
  5 MB, and protects API configuration and uploaded scripts with Apache rules.

Files
-----
- index.html, login.html, signup.html, feedbacks.html, profile.html,
  admin.html, and about.html are the interface.
- script.js connects the pages to api\index.php.
- styles.css contains shared visual styles.
- create-admin.ps1 provisions the single Admin account locally.

Source code repository
----------------------
The source repository contains the application code and setup documentation.
It does not contain the live database, private api\config.php, uploaded files,
or the generated staging ZIP. Do not commit credentials, database exports,
real account data, or real feedback. The deployment website and its database
are managed separately by the hosting provider.

This is a local development setup, not a production deployment. Before using
real users, obtain institutional approval for the privacy policy, access
rules, data retention, backups, HTTPS, account recovery, monitoring, and
hosting security. Do not expose phpMyAdmin or development database credentials
to the internet.
