<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService {
    public static function send(string $to, string $subject, string $message): bool {
        $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@paramascotasec.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Para Mascotas EC';
        $smtpHost = $_ENV['SMTP_HOST'] ?? null;

        if ($smtpHost && class_exists(PHPMailer::class)) {
            try {
                $mail = new PHPMailer(true);
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
                $mail->SMTPAuth = true;
                $mail->Username = $_ENV['SMTP_USER'] ?? '';
                $mail->Password = $_ENV['SMTP_PASS'] ?? '';
                $secure = strtolower((string)($_ENV['SMTP_SECURE'] ?? 'tls'));
                $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->setFrom($fromAddress, $fromName);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->isHTML(false);
                $mail->CharSet = 'UTF-8';
                $mail->send();
                return true;
            } catch (Exception $e) {
                error_log('SMTP send failed: ' . $e->getMessage());
                return false;
            }
        }

        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'Content-Type: text/plain; charset=UTF-8'
        ];

        $result = mail($to, $subject, $message, implode("\r\n", $headers));
        if (!$result) {
            error_log('Mail() failed for: ' . $to);
        }
        return $result;
    }
}
