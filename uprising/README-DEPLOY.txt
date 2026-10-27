UPRISING 2026 — TWO-STAGE REGISTRATION UPDATE

Stage 1: register.html -> submit.php
- Collects personal details, talent, review and declarations.
- Does NOT ask for a YouTube video link.
- Saves application and declarations in a database transaction.
- Saves a SHA-256 hash of a random continuation token in uprising_continuations.
- Sends applicant an email containing the registration code and secure continuation URL.
- Returns JSON with ok, reference, message and email_sent.

Stage 2: continue.php
- Applicant opens unique link from email.
- Submits YouTube URL and required video confirmations.
- Server checks token and prevents a second submission.
- Updates existing application video_url/video_permission and marks stage completed.
- Returns JSON.

INSTALLATION (BACK UP EXISTING FILES AND DATABASE FIRST)
1. Run migration-stage2.sql in phpMyAdmin. The SQL assumes the table is named applications and registration_reference is indexed UNIQUE/PRIMARY. If your config.php uses a different registrations_table, change the FOREIGN KEY target before running. If your schema cannot support the foreign key, ask your DBA to adjust it.
2. Upload register.html, submit.php, continue.php to the same /uprising/ directory.
3. Retain your existing config.php and PHPMailer/src/{Exception.php,PHPMailer.php,SMTP.php}.
4. Set SMTP_PASSWORD securely in the PHP process environment. SMTP host and username remain the values from your uploaded code (Titan / no-reply@rccgdominioncentredb.org); verify they are correct. Do not embed the password in the PHP source.
5. Verify the public continue URL is https://rccgdominioncentre.org/uprising/continue.php. If your hosting rewrites URLs differently, adjust the continuationUrl in submit.php.
6. Test adult and minor registration, invalid link, and successful Stage 2. Do not use the real registration database without a backup.
7. Ensure the database accepts empty video_url and video_permission=0 at Stage 1. If your schema requires other values, adjust the schema before deployment.

IMPORTANT
- Existing guardian verification function remains a placeholder from the supplied code. It does NOT send a guardian email or verify guardians. Minors still require a separate implemented guardian verification workflow before shortlisting.
- If SMTP fails after saving Stage 1, the continuation link will not reach the applicant. An authenticated resend/recovery workflow is recommended before public launch.
- Continuation links remain valid until Stage 2 is completed. Protect the token as a password; don't share it. Add an expiration/resend policy if desired.
- Existing declaration version/wording is retained, even though audition media is now submitted at Stage 2.
- A plaintext SMTP password was present in the uploaded submit.php. The updated file removes it. ROTATE THAT PASSWORD IMMEDIATELY in the mailbox provider.
- Existing submit-errors.log location is under the application directory. Protect it from web access.
