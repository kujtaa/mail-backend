<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

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

    public function embedInlineDataImages(Email $email, string $htmlBody): string
    {
        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc=)(["\'])data:(image\/[a-zA-Z0-9.+-]+);base64,([^"\']+)\2/i',
            function (array $matches) use ($email) {
                $imageData = base64_decode($matches[4], true);
                if ($imageData === false) {
                    return $matches[0];
                }

                $extension = match (strtolower($matches[3])) {
                    'image/jpeg' => 'jpg',
                    'image/svg+xml' => 'svg',
                    default => strtolower(substr($matches[3], strlen('image/'))),
                };
                $contentId = 'inline-image-' . count($email->getAttachments()) . '@professionalclean.local';
                $part = (new DataPart($imageData, "signature-logo.{$extension}", $matches[3]))
                    ->setContentId($contentId)
                    ->asInline();
                $email->addPart($part);

                return $matches[1] . $matches[2] . 'cid:' . $contentId . $matches[2];
            },
            $htmlBody,
        );
    }

    private function normalizeHtmlForEmail(string $html): string
    {
        return preg_replace_callback(
            '/<p(\b[^>]*)>/i',
            function (array $m) {
                $attrs = $m[1];
                if (preg_match('/\bstyle=["\']([^"\']*)["\']/', $attrs, $s)) {
                    $style = rtrim($s[1], ';') . ';margin:0 0 14px 0;padding:0;';
                    $attrs = preg_replace('/\bstyle=["\'][^"\']*["\']/', "style=\"{$style}\"", $attrs);
                } else {
                    $attrs .= ' style="margin:0 0 14px 0;padding:0;"';
                }
                return '<p' . $attrs . '>';
            },
            $html,
        );
    }

    public function sendSingle(
        string $host, int $port, string $user, string $pass,
        string $fromEmail, string $fromName, string $toEmail,
        string $subject, string $htmlBody,
        ?string $unsubUrl = null, ?string $signature = null
    ): array {
        try {
            $htmlBody = $this->normalizeHtmlForEmail($htmlBody);

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
                ->text($plainText);

            $htmlBody = $this->embedInlineDataImages($email, $htmlBody);
            $email->html($htmlBody);

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

    public function checkSmtpAuth(string $host, int $port, string $user, string $pass): array
    {
        try {
            $isDirectSsl = $port === 465;
            $address = ($isDirectSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

            $fp = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
            if (!$fp) {
                return [false, "Cannot connect to {$host}:{$port} — {$errstr}"];
            }
            stream_set_timeout($fp, 10);

            $read = function () use ($fp): array {
                $code = 0; $text = '';
                while ($line = fgets($fp, 1024)) {
                    $code = (int)substr($line, 0, 3);
                    $text .= trim(substr($line, 4)) . ' ';
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
                return [$code, trim($text)];
            };
            $send = fn(string $cmd) => fwrite($fp, $cmd . "\r\n");

            [$code] = $read();
            if ($code !== 220) { fclose($fp); return [false, "Unexpected greeting (code {$code})"]; }

            $send("EHLO localhost");
            [$code, $ehloText] = $read();
            if ($code !== 250) { fclose($fp); return [false, "EHLO failed (code {$code})"]; }

            if (!$isDirectSsl && stripos($ehloText, 'STARTTLS') !== false) {
                $send("STARTTLS");
                [$code] = $read();
                if ($code !== 220) { fclose($fp); return [false, "STARTTLS rejected (code {$code})"]; }
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $send("EHLO localhost");
                $read();
            }

            $send("AUTH LOGIN");
            [$code] = $read();
            if ($code === 334) {
                $send(base64_encode($user));
                $read();
                $send(base64_encode($pass));
                [$code, $msg] = $read();
            } else {
                $send("AUTH PLAIN " . base64_encode("\0{$user}\0{$pass}"));
                [$code, $msg] = $read();
            }

            $send("QUIT");
            fclose($fp);

            if ($code === 235) return [true, null];
            return [false, "Authentication failed ({$code}): {$msg}"];
        } catch (\Throwable $e) {
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

}
