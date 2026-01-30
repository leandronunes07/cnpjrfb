<?php

namespace CnpjRfb\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use CnpjRfb\Utils\Logger;

class NotificationService
{
    private $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->setup();
    }

    private function setup()
    {
        try {
            // Server settings
            // Taking config from ENV if available, else standard fallback or from config.php if it existed
            // User did not provide explicit mail config in the prompt, so I'll try to use standard params
            // or assume local relay if not set.
            
            // However, based on legacy 'automacao.php', it required 'vendor/autoload.php' from '../cnpjrfb'.
            // It didn't show explicit SMTP config in the snippet I saw (maybe it was in 'config.php' or defaults).
            // Actually, in automacao.php I didn't see SMTP setup, just "use PHPMailer".
            // So I will assume the environment provides SMTP settings or use localhost.
            
            $host = getenv('SMTP_HOST');
            if ($host) {
                $this->mailer->isSMTP();
                $this->mailer->Host       = $host;
                $this->mailer->SMTPAuth   = true;
                $this->mailer->Username   = getenv('SMTP_USER');
                $this->mailer->Password   = getenv('SMTP_PASS');
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $this->mailer->Port       = getenv('SMTP_PORT') ?: 587;
            } else {
                // Fallback to mail() if no SMTP env match
                $this->mailer->isMail();
            }

            // Recipients
            $fromEmail = getenv('MAIL_FROM') ?: 'no-reply@agenciataruga.com';
            $this->mailer->setFrom($fromEmail, 'CNPJ Extractor Bot');
            
            $toEmail = getenv('MAIL_TO') ?: 'admin@agenciataruga.com';
            $this->mailer->addAddress($toEmail);
            
            $this->mailer->isHTML(true);

        } catch (Exception $e) {
            Logger::log("Notification Setup Failed: {$this->mailer->ErrorInfo}", 'error');
        }
    }

    public function send(string $subject, string $body): bool
    {
        try {
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;
            $this->mailer->AltBody = strip_tags($body);

            $this->mailer->send();
            Logger::log("Email sent: $subject", 'info');
            return true;
        } catch (Exception $e) {
            Logger::log("Message could not be sent. Mailer Error: {$this->mailer->ErrorInfo}", 'error');
            return false;
        }
    }
}
