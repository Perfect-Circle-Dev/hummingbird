<?php
// Sending email. Every message is recorded in the email_log table.
// With mail_driver 'log' nothing leaves the server, which is what we want while testing.
declare(strict_types=1);

function send_email(string $to, string $subject, string $body): bool
{
    $status = 'logged';
    if (config('mail_driver') === 'smtp') {
        $status = smtp_send($to, $subject, $body) ? 'sent' : 'failed';
    }
    q('INSERT INTO email_log (to_email, subject, body, status) VALUES (?, ?, ?, ?)',
        [$to, $subject, $body, $status]);
    return $status !== 'failed';
}

/** Where notices about the server go (failed updates, pending restarts). */
function admin_email(): string
{
    // Older config.php files have no admin_email; the "from" address is the organizer's.
    return (string) (config('admin_email') ?: config('mail_from'));
}

/** Sends by SMTP (Resend) using PHPMailer (installed with "composer install"). */
function smtp_send(string $to, string $subject, string $body): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Email not sent: run "composer install" first.');
        return false;
    }
    require_once $autoload;
    $smtp = config('smtp');
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['user'];
        $mail->Password = $smtp['pass'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(config('mail_from'), config('mail_from_name'));
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (Throwable $err) {
        error_log('Email failed: ' . $err->getMessage());
        return false;
    }
}
