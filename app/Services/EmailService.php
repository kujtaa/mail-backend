<?php
namespace App\Services;

use App\Models\Company;
use App\Models\Business;
use App\Models\UnsubscribedEmail;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class EmailService
{
    private function secret(): string
    {
        return config('app.jwt_secret') ?? env('JWT_SECRET', 'change-me');
    }

    public function generateUnsubscribeToken(string $email): string
    {
        $b64 = str_replace(['+', '/'], ['-', '_'], base64_encode($email));
        $sig = hash_hmac('sha256', $email, $this->secret());
        return $b64 . '.' . $sig;
    }

    public function verifyUnsubscribeToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        [$b64, $sig] = $parts;
        $email = base64_decode(str_replace(['-', '_'], ['+', '/'], $b64));
        if ($email === false) return null;
        $expected = hash_hmac('sha256', $email, $this->secret());
        return hash_equals($expected, $sig) ? $email : null;
    }

    public function buildUnsubscribeUrl(string $email): string
    {
        $token = $this->generateUnsubscribeToken($email);
        return rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/unsubscribe/' . $token;
    }

    public function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '***';
        [$local, $domain] = explode('@', $email, 2);
        if (strlen($local) <= 2) {
            $masked = $local[0] . '***';
        } else {
            $masked = $local[0] . '***' . substr($local, -1);
        }
        return $masked . '@' . $domain;
    }

    public function sendSingle(
        string $host, int $port, string $user, string $pass,
        string $fromEmail, string $fromName, string $toEmail,
        string $subject, string $htmlBody,
        ?string $unsubUrl = null, ?string $signature = null
    ): array {
        try {
            if ($signature) {
                $htmlBody .= '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;">'
                    . $signature . '</div>';
            }

            $plainText = strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>'],
                ["\n", "\n", "\n", "\n\n"],
                $htmlBody
            ));

            if ($unsubUrl) {
                $htmlBody .= '<div style="margin-top:30px;padding-top:15px;border-top:1px solid #e5e7eb;'
                    . 'text-align:center;font-size:12px;color:#9ca3af;">If you no longer wish to receive '
                    . 'these emails, <a href="' . $unsubUrl . '" style="color:#6366f1;">unsubscribe here</a>.</div>';
                $plainText .= "\n\n---\nTo unsubscribe: " . $unsubUrl;
            }

            $transport = new EsmtpTransport($host, $port);
            $transport->setUsername($user);
            $transport->setPassword($pass);

            $email = (new Email())
                ->from(new Address($fromEmail, $fromName))
                ->replyTo($fromEmail)
                ->to($toEmail)
                ->subject($subject)
                ->html($htmlBody)
                ->text($plainText);

            if ($unsubUrl) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }

            (new SymfonyMailer($transport))->send($email);
            return [true, null];
        } catch (\Exception $e) {
            Log::warning('SMTP send failed', [
                'host' => $host,
                'port' => $port,
                'to_email' => $toEmail,
                'error' => $e->getMessage(),
            ]);
            return [false, $e->getMessage()];
        }
    }

    public function sendTestEmail(
        string $host, int $port, string $user, string $pass,
        string $fromEmail, string $fromName, string $toEmail
    ): array {
        $body = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;">'
            . '<h2 style="color:#4f46e5;">SMTP Connection Successful</h2>'
            . '<p style="color:#374151;line-height:1.6;">Your SMTP settings are working correctly.</p>'
            . '</div>';
        return $this->sendSingle($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, 'ProfessionalClean — SMTP Test', $body);
    }

    public function sendBatch(array $sentEmails): void
    {
        if (empty($sentEmails)) return;

        $company = Company::find($sentEmails[0]->company_id);

        if (!$company || !$company->smtp_enabled || !$company->smtp_host || !$company->smtp_pass) {
            foreach ($sentEmails as $record) {
                $record->update(['status' => 'failed', 'sent_at' => now()]);
            }
            return;
        }

        $unsubscribed = array_flip(
            UnsubscribedEmail::pluck('email')->map(fn($e) => strtolower($e))->toArray()
        );

        foreach ($sentEmails as $record) {
            $recipient = Business::join('batch_emails', 'businesses.id', '=', 'batch_emails.business_id')
                ->where('batch_emails.id', $record->batch_email_id)
                ->value('businesses.email');

            if (!$recipient) {
                $record->update(['status' => 'failed', 'sent_at' => now()]);
                continue;
            }

            if (isset($unsubscribed[strtolower($recipient)])) {
                $record->update(['status' => 'unsubscribed', 'sent_at' => now()]);
                continue;
            }

            $unsubUrl = $this->buildUnsubscribeUrl($recipient);
            [$success] = $this->sendSingle(
                $company->smtp_host,
                $company->smtp_port ?? 587,
                $company->smtp_user,
                $company->smtp_pass,
                $company->smtp_from_email ?? $company->smtp_user,
                $company->smtp_from_name ?? $company->name,
                $recipient,
                $record->subject,
                $record->body,
                $unsubUrl,
                $company->email_signature,
            );

            $record->update(['status' => $success ? 'sent' : 'failed', 'sent_at' => now()]);
        }
    }
}
