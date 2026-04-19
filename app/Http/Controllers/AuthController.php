<?php
namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        if (Company::where('email', $data['email'])->exists()) {
            abort(400, 'Email already registered');
        }

        $isFirst = Company::count() === 0;

        $company = Company::create([
            'name' => $data['company_name'],
            'email' => $data['email'],
            'hashed_password' => Hash::make($data['password']),
            'credit_balance' => 0.0,
            'is_admin' => $isFirst,
            'is_approved' => $isFirst,
        ]);

        $token = $company->createToken('auth-token')->plainTextToken;
        return response()->json(['access_token' => $token, 'token_type' => 'bearer']);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $company = Company::where('email', $data['email'])->first();
        if (!$company || !$this->checkPassword($data['password'], $company->hashed_password)) {
            abort(401, 'Invalid credentials');
        }

        // Re-hash with Laravel's $2y$ prefix if stored as Python's $2b$
        if (str_starts_with($company->hashed_password, '$2b$')) {
            $company->hashed_password = Hash::make($data['password']);
            $company->save();
        }

        $token = $company->createToken('auth-token')->plainTextToken;
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'company' => $this->companyProfile($company),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->companyProfile($request->user()));
    }

    private function checkPassword(string $plain, string $hashed): bool
    {
        // Python bcrypt uses $2b$, Laravel expects $2y$ — they are identical algorithms
        $normalized = str_starts_with($hashed, '$2b$') ? '$2y$' . substr($hashed, 4) : $hashed;
        return Hash::check($plain, $normalized);
    }

    private function companyProfile(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'email' => $company->email,
            'credit_balance' => $company->credit_balance,
            'is_admin' => $company->is_admin,
            'is_approved' => $company->is_approved,
            'plan' => $company->plan,
            'plan_expires_at' => $company->plan_expires_at?->toISOString(),
            'daily_send_limit' => $company->daily_send_limit,
            'daily_sends_used' => $company->daily_sends_used,
            'created_at' => $company->created_at?->toISOString(),
        ];
    }
}
