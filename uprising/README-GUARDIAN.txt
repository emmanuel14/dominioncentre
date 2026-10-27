UPRISING 2026 — GUARDIAN VERIFICATION ADD-ON

FILES: updated submit.php, new verify-guardian.php, migration-guardian.sql, plus unchanged register.html, continue.php, migration-stage2.sql.

INSTALL
1. BACK UP live database and PHP files.
2. Confirm migration-declarations.sql already added guardian_verification_status, guardian_verification_token_hash, guardian_consent, guardian_email, etc. The new flow requires those columns. Confirm guardian_verification_status allows 'pending', 'verified', 'not_required'; if it is an ENUM, extend it before testing.
3. Run migration-guardian.sql ONCE in phpMyAdmin (change table name if needed). This adds expiry and verified timestamp columns.
4. Upload updated submit.php and new verify-guardian.php to the SAME directory as continue.php. Keep register.html, continue.php, and PHPMailer/src unchanged.
5. Existing SMTP settings in this package are Titan: smtp.titan.email / no-reply@rccgdominioncentredb.org. Check these are the actual working settings. Configure SMTP_PASSWORD securely and test.
6. Verify that https://rccgdominioncentre.org/uprising/verify-guardian.php loads over HTTPS.
7. Test with a new MINOR registration and guardian mailbox you control. The applicant should receive the Stage 2 link, and guardian should receive a separate one-time verification link. Visiting the guardian link must NOT approve consent; approval happens only after checking the box and submitting the form.
8. Verify the database row changes from pending to verified, guardian_verified_at populated, token_hash and expiry cleared. Repeat verification should fail. Check expired link after 7 days. Adult registrations must not send guardian email.
9. Guardian verification and audition submission are independent; both must be complete before shortlisting. Ensure your admin/shortlisting workflow enforces this rule; this package does NOT change admin queries or create resend links.

IMPORTANT
- The minor's registration is committed before mail is sent. A guardian SMTP failure leaves verification pending. There is no resend mechanism yet; do not advertise it as automatic.
- Links expire after 7 days. A staff-operated authenticated resend feature would need to issue and save a NEW token and expiry and send a new email.
- Do not use SMTP passwords in public code; rotate any previously exposed credentials.
- Protect submit-errors.log and config.php from direct web access.
- No live database or SMTP tests have been performed; PHP syntax checks pass.
