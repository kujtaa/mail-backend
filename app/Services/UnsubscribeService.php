<?php
namespace App\Services;

use App\Models\Business;
use App\Models\Company;
use App\Models\SentEmail;
use App\Models\UnsubscribedEmail;
use Illuminate\Database\QueryException;

class UnsubscribeService
{
    public function __construct(private EmailService $emailService) {}

    /**
     * Add an address to the suppression list. Idempotent: returns the existing
     * row (and $created = false) when the address is already suppressed.
     *
     * @return array{0: UnsubscribedEmail, 1: bool} [row, created]
     */
    public function suppress(
        string $email,
        string $source = UnsubscribedEmail::SOURCE_LINK,
        ?Company $addedBy = null,
        ?string $note = null,
    ): array {
        $email = UnsubscribedEmail::normalize($email);

        if ($existing = UnsubscribedEmail::where('email', $email)->first()) {
            return [$existing, false];
        }

        try {
            $row = UnsubscribedEmail::create([
                'email' => $email,
                'business_id' => Business::whereRaw('LOWER(email) = ?', [$email])->value('id'),
                'token' => $this->emailService->generateUnsubscribeToken($email),
                'unsubscribed_at' => now(),
                'source' => $source,
                'added_by' => $addedBy?->id,
                'note' => $note,
            ]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent insert for the same address; that is fine.
            $row = UnsubscribedEmail::where('email', $email)->first();
            if (!$row) throw $e;
            return [$row, false];
        }

        $this->cancelPendingSends($email);

        return [$row, true];
    }

    /**
     * Any email still waiting in the queue for this address must not go out.
     * The send job re-checks the list too, but marking rows here makes the
     * outcome visible in Sent History immediately and keeps retry from picking them up.
     */
    private function cancelPendingSends(string $email): int
    {
        $ids = SentEmail::where('sent_emails.status', 'pending')
            ->join('batch_emails', 'sent_emails.batch_email_id', '=', 'batch_emails.id')
            ->join('businesses', 'batch_emails.business_id', '=', 'businesses.id')
            ->whereRaw('LOWER(businesses.email) = ?', [$email])
            ->pluck('sent_emails.id');

        if ($ids->isEmpty()) return 0;

        return SentEmail::whereIn('id', $ids)->update([
            'status' => 'unsubscribed',
            'sent_at' => now(),
            'error_message' => 'Recipient unsubscribed before this email was sent.',
        ]);
    }

    public function isSuppressed(string $email): bool
    {
        return UnsubscribedEmail::contains($email);
    }
}
