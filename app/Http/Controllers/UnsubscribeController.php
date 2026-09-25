<?php
namespace App\Http\Controllers;

use App\Models\UnsubscribedEmail;
use App\Services\EmailService;
use App\Services\UnsubscribeService;

class UnsubscribeController extends Controller
{
    public function __construct(
        private EmailService $emailService,
        private UnsubscribeService $unsubscribeService,
    ) {}

    public function handle(string $token)
    {
        $email = $this->emailService->verifyUnsubscribeToken($token);
        if (!$email) abort(400, 'Invalid or expired unsubscribe link.');

        [$row, $created] = $this->unsubscribeService->suppress($email, UnsubscribedEmail::SOURCE_LINK);

        return response()->json([
            'detail' => $created ? 'Successfully unsubscribed.' : 'This email has already been unsubscribed.',
            'email' => $row->email,
        ]);
    }
}
