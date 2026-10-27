
<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an HTML email using SMTP.
 *
 * @param string $recipientEmail
 * @param string $firstName
 * @param string $lastName
 * @param string $subject
 * @param string $message HTML email content
 *
 * @return bool
 */
function sendEmail(
    string $recipientEmail,
    string $firstName,
    string $lastName,
    string $subject,
    string $message
): bool {

    $mail = new PHPMailer(true);

    try {

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;

        $mail->Username   = 'no-reply@rccgdominioncentre.org';
        $mail->Password   = getenv('SMTP_PASSWORD');

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        // Sender
        $mail->setFrom(
            'no-reply@rccgdominioncentre.org',
            'RCCG Dominion Centre'
        );

        // Recipient
        $mail->addAddress($recipientEmail);

        // Reply-to Support
        $mail->addReplyTo(
            'support@rccgdominioncentre.org',
            'RCCG Dominion Centre Support'
        );

        // Email Subject
        $mail->Subject = $subject;

        // Recipient Full Name
        $fullName = ucwords(
            strtolower(trim($firstName . ' ' . $lastName))
        );

        $safeName = htmlspecialchars(
            $fullName,
            ENT_QUOTES,
            'UTF-8'
        );

        // Logo URLs
        $logo1 = 'https://rccgdominioncentre.org/assets/media/logos/favicon.png';

        $logo2 = 'https://rccgdominioncentre.org/assets/media/logos/logo.png';

        // Email Body
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
        </head>

        <body style='
            font-family:Arial,Helvetica,sans-serif;
            background:#f5f5f5;
            padding:30px;
            margin:0;
        '>

            <div style='
                max-width:700px;
                margin:auto;
                background:#ffffff;
                border-radius:6px;
                padding:30px;
                border:1px solid #dddddd;
            '>

                <!-- Logos -->
                <div style='
                    text-align:center;
                    margin-bottom:25px;
                '>

                    <img src='{$logo1}'
                         alt='RCCG Logo'
                         style='height:60px;margin-right:15px;'>

                    <img src='{$logo2}'
                         alt='Dominion Centre'
                         style='height:60px;'>

                </div>

                <!-- Greeting -->
                <p>Dear <strong>{$safeName}</strong>,</p>

                <!-- Dynamic Message -->
                {$message}

                <br><br>

                <!-- Signature -->
                <p>
                    Regards,<br>
                    <strong>RCCG Dominion Centre</strong>
                </p>

                <br>

                <hr style='border:0;border-top:1px solid #eee;'>

                <!-- Footer -->
                <p style='
                    font-size:12px;
                    color:#777777;
                    line-height:1.6;
                '>

                    <em>
                        This is an automated message from
                        RCCG Dominion Centre.

                        Please do not reply to this email.

                        For assistance, contact
                        support@rccgdominioncentre.org.
                    </em>

                </p>

            </div>

        </body>
        </html>
        ";

        $mail->Body = $body;

        // Plain Text Alternative
        $mail->AltBody =
            "Dear {$fullName},\n\n" .
            strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>'],
                "\n",
                $message
            )) .
            "\n\nRegards,\nRCCG Dominion Centre";

        // Send Email
        $mail->send();

        error_log(
            'Email sent successfully to ' . $recipientEmail
        );

        return true;

    } catch (Exception $e) {

        error_log(
            'Email sending failed: ' . $mail->ErrorInfo
        );

        return false;
    }
}
