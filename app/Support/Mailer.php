<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function sendPlainText(string $to, string $subject, string $body): bool
    {
        $phpMailerBase = BASE_PATH . '/vendor/phpmailer/phpmailer/src';
        $requiredFiles = [
            $phpMailerBase . '/Exception.php',
            $phpMailerBase . '/PHPMailer.php',
            $phpMailerBase . '/SMTP.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!is_file($file)) {
                error_log('[Mailer] Missing PHPMailer file: ' . $file);
                return false;
            }
            require_once $file;
        }

        $host = trim((string)env('SMTP_HOST', ''));
        $port = (int)env_int('SMTP_PORT', 587);
        $user = trim((string)env('SMTP_USER', ''));
        $pass = (string)env('SMTP_PASS', '');
        $secure = strtolower(trim((string)env('SMTP_SECURE', 'tls')));
        $fromEmail = trim((string)env('SMTP_FROM_EMAIL', ''));
        $fromName = trim((string)env('SMTP_FROM_NAME', 'Pakowacz'));

        if ($host === '' || $port <= 0 || $fromEmail === '') {
            error_log('[Mailer] Missing SMTP configuration: host/port/from');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->Timeout = 15;
            $mail->SMTPAutoTLS = true;
            $mail->SMTPAuth = ($user !== '' || $pass !== '');

            if ($mail->SMTPAuth) {
                $mail->Username = $user;
                $mail->Password = $pass;
            }

            if ($secure === 'ssl' || $secure === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls' || $secure === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            return $mail->send();
        } catch (Throwable $e) {
            error_log('[Mailer] SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }
}
