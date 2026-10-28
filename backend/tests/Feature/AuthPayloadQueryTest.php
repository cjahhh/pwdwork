<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthPayloadQueryTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['applicant'], ['employer'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_login_and_session_restoration_preserve_payload_without_loading_applicant_profiles(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $user->applicantProfile()->create(['headline' => 'Synthetic private profile']);
        if ($role === 'employer') {
            $company = Employer::create(['user_id' => $user->id, 'company_name' => 'Synthetic company', 'verification_status' => 'pending']);
        }
        $expected = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role,
            'status' => 'active', 'company_name' => $company->company_name ?? null,
            'verification_status' => $company->verification_status ?? null];
        $this->withHeader('Origin', 'http://localhost:5173');
        $before = $user->refresh()->getAttributes();
        foreach (['login', 'restore'] as $operation) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $operation === 'login'
                ? $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
                : $this->getJson('/api/user');
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
            $response->assertOk()->assertJsonPath('user', $expected);
            $this->assertSame($operation === 'login' ? ['message', 'user'] : ['user'], array_keys($response->json()));
            $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'applicant_profiles'))));
            $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "employers"')));
            $this->app['auth']->forgetGuards();
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest('web');
    }
}
