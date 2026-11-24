<?php
// Sending email. Every message is recorded in the email_log table.
// With mail_driver 'log' nothing leaves the server, which is what we want while testing.
declare(strict_types=1);

/**
 * Sends one email now. $headers are extra mail headers, used for the "unsubscribe"
 * line on group emails. $attachments are paths of files to attach (the weekly backup).
 * Returns false if it could not be sent.
 */
function send_email(string $to, string $subject, string $body, array $headers = [], array $attachments = []): bool
{
    $status = 'logged';
    if (config('mail_driver') === 'smtp') {
        $status = smtp_send($to, $subject, $body, $headers, $attachments) ? 'sent' : 'failed';
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

/** How many emails the site has sent in the last 24 hours. Resend's free plan allows 100 a day. */
function emails_last_24h(): int
{
    return (int) q("SELECT COUNT(*) FROM email_log WHERE status IN ('sent', 'logged') AND created_at > ?",
        [now_utc(-86400)])->fetchColumn();
}

/** Sends by SMTP (Resend) using PHPMailer (installed with "composer install"). */
function smtp_send(string $to, string $subject, string $body, array $headers = [], array $attachments = []): bool
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
        foreach ($headers as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }
        foreach ($attachments as $path) {
            $mail->addAttachment($path);
        }
        return $mail->send();
    } catch (Throwable $err) {
        error_log('Email failed: ' . $err->getMessage());
        return false;
    }
}
