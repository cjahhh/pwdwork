<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\ApplicantProfile;
use App\Models\Employer;
use App\Models\User;
use App\Rules\PasswordByteLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Use the configured first-party frontend origin.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', new PasswordByteLimit],
            'role' => ['required', Rule::in(['applicant', 'employer'])],
            'company_name' => ['required_if:role,employer', 'nullable', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            if ($user->role === 'applicant') {
                ApplicantProfile::query()->create([
                    'user_id' => $user->id,
                ]);
            }

            if ($user->role === 'employer') {
                $employer = Employer::query()->create([
                    'user_id' => $user->id,
                    'company_name' => $data['company_name'] ?: $data['name'],
                    'verification_status' => 'pending',
                    'verification_requested_at' => now(),
                ]);
                AdminAuditLog::record($user, 'employer.registered', 'employer', $employer->id, null, 'pending', 'Employer registration awaiting review.');
            }

            return $user;
        });
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Account created.',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Use the configured first-party frontend origin.');
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', new PasswordByteLimit],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user) {
            // Match the configured password-hashing work for an unknown email.
            // Discard the result; never store or log submitted credentials.
            Hash::make($credentials['password']);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Those credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is not active.',
                'code' => 'account_inactive',
            ], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->bearerToken()) {
            $token = $request->user()->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('employer');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'company_name' => $user->employer?->company_name,
            'verification_status' => $user->employer?->verification_status,
        ];
    }
}
