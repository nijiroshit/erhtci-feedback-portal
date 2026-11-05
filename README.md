# ERHTCI Feedback Portal

A PHP and MariaDB/MySQL feedback portal for students, staff, and instructors,
with a private administrator dashboard for reviewing reports and responding.

## Features

- Student ID or email-based accounts
- Anonymous and named feedback submissions
- Feedback status tracking and administrator responses
- Optional image attachments
- Admin-only report management

## Local setup

1. Install XAMPP with PHP, Apache, and MariaDB.
2. Copy the project folder into `C:\xampp\htdocs\erhtci`.
3. Create the database by importing `database\schema.sql` in phpMyAdmin.
4. Copy `api\config.example.php` to `api\config.php` and enter local database
   credentials in the private config file. Never commit `api\config.php`.
5. Open `http://localhost/erhtci/`.
6. Follow `README.txt` to provision the single local Admin account and test the
   app.

For an existing database, apply the migrations in `database\migrations` as
needed. For a hosted database, `database\hosted-schema.sql` creates the tables
in the selected database.

## Demo and data safety

The project has a separate hosted demo deployment. Keep demo accounts and
feedback fictional. Do not store real personal or sensitive information in a
public repository, staging database, or presentation demo.

The source repository does not include deployment credentials, database
exports, uploaded user files, or the generated staging ZIP. See
[`REVIEWER.md`](REVIEWER.md) for the architecture, security notes, and
deployment checklist.
