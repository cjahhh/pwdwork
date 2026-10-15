<?php

namespace App\Http\Controllers;

use App\Models\ApplicantProfile;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['applicant', 'employer'])],
            'company_name' => ['required_if:role,employer', 'nullable', 'string', 'max:255'],
        ]);

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
            Employer::query()->create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'] ?: $data['name'],
                'verification_status' => 'pending',
            ]);
        }

        return response()->json([
            'message' => 'Account created.',
            'user' => $this->userPayload($user),
            'token' => $user->createToken('auth')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Those credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is not active.',
            ], 403);
        }

        $user->tokens()->where('name', 'auth')->delete();

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user),
            'token' => $user->createToken('auth')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

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
        $user->loadMissing(['applicantProfile', 'employer']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'company_name' => $user->employer?->company_name,
        ];
    }
}
