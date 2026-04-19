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
        if (!$company || !Hash::check($data['password'], $company->hashed_password)) {
            abort(401, 'Invalid credentials');
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
