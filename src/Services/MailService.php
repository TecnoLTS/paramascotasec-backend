<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService {
    private static function resolveSmtpEncryption(?string $secure, int $port): string
    {
        $normalized = strtolower(trim((string)$secure));

        if (in_array($normalized, ['ssl', 'smtps'], true)) {
            return PHPMailer::ENCRYPTION_SMTPS;
        }

        if (in_array($normalized, ['tls', 'starttls'], true)) {
            // Compatibilidad con configuraciones comunes donde 465 se marca como "tls"
            // aunque en realidad requiere SMTP implícito.
            return $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }

        if (in_array($normalized, ['off', 'none', 'plain'], true)) {
            return '';
        }

        return $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    }

    private static function normalizedSmtpPassword(?string $host, string $password): string
    {
        $password = trim($password);
        if ($password === '' || !$host) {
            return $password;
        }

        $normalizedHost = strtolower(trim($host));
        $collapsed = preg_replace('/\s+/', '', $password) ?? $password;

        if ($collapsed !== $password && str_contains($normalizedHost, 'gmail')) {
            return $collapsed;
        }

        return $password;
    }

    public static function send(
        string $to,
        string $subject,
        string $message,
        ?string $replyTo = null,
        ?string $replyToName = null
    ): bool {
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
                $mail->Password = self::normalizedSmtpPassword($smtpHost, (string)($_ENV['SMTP_PASS'] ?? ''));
                $mail->SMTPSecure = self::resolveSmtpEncryption($_ENV['SMTP_SECURE'] ?? 'tls', $mail->Port);
                $mail->Timeout = max(3, (int)($_ENV['SMTP_TIMEOUT'] ?? 10));
                $mail->setFrom($fromAddress, $fromName);
                if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    $mail->addReplyTo($replyTo, $replyToName ?: $replyTo);
                }
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

        $replyToHeader = $fromAddress;
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyToHeader = $replyTo;
        }

        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyToHeader,
            'Content-Type: text/plain; charset=UTF-8'
        ];

        $result = mail($to, $subject, $message, implode("\r\n", $headers));
        if (!$result) {
            error_log('Mail() failed for: ' . $to);
        }
        return $result;
    }
}
