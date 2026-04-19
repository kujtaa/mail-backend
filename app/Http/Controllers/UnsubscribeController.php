<?php
namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\UnsubscribedEmail;
use App\Services\EmailService;
use Illuminate\Http\Request;

class UnsubscribeController extends Controller
{
    public function __construct(private EmailService $emailService) {}

    public function handle(string $token)
    {
        $email = $this->emailService->verifyUnsubscribeToken($token);
        if (!$email) abort(400, 'Invalid or expired unsubscribe link.');

        $existing = UnsubscribedEmail::where('email', $email)->first();
        if ($existing) {
            return response()->json(['detail' => 'This email has already been unsubscribed.', 'email' => $email]);
        }

        $bizId = Business::where('email', $email)->value('id');

        UnsubscribedEmail::create([
            'email' => $email,
            'business_id' => $bizId,
            'token' => $token,
            'unsubscribed_at' => now(),
        ]);

        return response()->json(['detail' => 'Successfully unsubscribed.', 'email' => $email]);
    }
}
