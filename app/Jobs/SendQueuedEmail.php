<?php
namespace App\Jobs;

use App\Models\Business;
use App\Models\Company;
use App\Models\SentEmail;
use App\Models\UnsubscribedEmail;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendQueuedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sentEmailId) {}

    public function handle(EmailService $emailService): void
    {
        $record = SentEmail::find($this->sentEmailId);
        if (!$record || $record->status === 'sent') {
            return;
        }

        $company = Company::find($record->company_id);
        if (!$company || !$company->smtp_enabled || !$company->smtp_host || !$company->smtp_pass) {
            $record->update([
                'status' => 'failed',
                'sent_at' => now(),
                'error_message' => 'SMTP is not configured or enabled.',
            ]);
            return;
        }

        $recipient = Business::join('batch_emails', 'businesses.id', '=', 'batch_emails.business_id')
            ->where('batch_emails.id', $record->batch_email_id)
            ->value('businesses.email');

        if (!$recipient) {
            $record->update([
                'status' => 'failed',
                'sent_at' => now(),
                'error_message' => 'Recipient email not found.',
            ]);
            return;
        }

        $isUnsubscribed = UnsubscribedEmail::whereRaw('LOWER(email) = ?', [strtolower($recipient)])->exists();
        if ($isUnsubscribed) {
            $record->update([
                'status' => 'unsubscribed',
                'sent_at' => now(),
                'error_message' => 'Recipient is unsubscribed.',
            ]);
            return;
        }

        $unsubUrl = $emailService->buildUnsubscribeUrl($recipient);
        [$success, $error] = $emailService->sendSingle(
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

        $record->update([
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => now(),
            'error_message' => $success ? null : $error,
        ]);
    }
}
