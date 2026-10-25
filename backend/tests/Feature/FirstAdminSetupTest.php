<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureUserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use FirstAdminRefusal;
use FirstAdminSetup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirstAdminSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/.github/scripts/first-admin-setup.php';
        // Only synthetic SQLite :memory: fixtures. No migrations or seeders.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->collation('NOCASE')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('status');
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });
        Schema::create('employers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            foreach (['actor_role', 'action', 'target_type', 'before_status', 'after_status', 'reason'] as $field) {
                $table->string($field)->nullable();
            }
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at');
        });
    }

    private function account(string $email = 'dedicated-admin@example.test', string $role = 'applicant', string $status = 'active'): int
    {
        return DB::table('users')->insertGetId(['name' => 'Synthetic Account', 'email' => $email,
            'password' => 'synthetic-existing-hash', 'role' => $role, 'status' => $status,
            'remember_token' => 'synthetic-token', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }

    private function refuse(string $status, callable $operation): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        try {
            $operation();
            $this->fail('Unsafe bootstrap request was accepted.');
        } catch (FirstAdminRefusal $error) {
            $this->assertSame($status, $error->getMessage());
        }
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    public function test_only_selected_role_changes_and_audit_is_recorded(): void
    {
        $id = $this->account();
        $this->account('jobseeker-test@example.test');
        $this->account('employer-test@example.test', 'employer');
        $before = DB::table('users')->orderBy('id')->get()->keyBy('id')->toArray();
        FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN');
        $after = DB::table('users')->orderBy('id')->get()->keyBy('id')->toArray();
        $before[$id]->role = 'admin';
        $this->assertEquals($before, $after);
        $this->assertSame(1, DB::table('users')->where('role', 'admin')->where('status', 'active')->count());
        $audit = AdminAuditLog::sole();
        $this->assertSame('admin.first_bootstrapped', $audit->action);
        $this->assertSame($id, $audit->target_id);
        $this->assertNull($audit->actor_id);
        $this->assertSame('cli', $audit->actor_role);
        $promoted = User::findOrFail($id);
        $this->assertTrue($promoted->isAdmin());
        $request = Request::create('/api/admin/overview');
        $request->setUserResolver(fn () => $promoted);
        $response = (new EnsureUserRole)->handle($request, fn () => response('', 204), 'admin');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_confirmation_is_required(): void
    {
        $this->account();
        $this->refuse('confirmation_required', fn () => FirstAdminSetup::promote('dedicated-admin@example.test', ''));
    }

    public function test_a_second_invocation_cannot_promote_another_account(): void
    {
        $this->account();
        $other = $this->account('other@example.test');
        FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN');
        try {
            FirstAdminSetup::promote('other@example.test', 'CREATE FIRST PWDWORK ADMIN');
            $this->fail('A repeated bootstrap was accepted.');
        } catch (FirstAdminRefusal $error) {
            $this->assertSame('administrator_already_exists', $error->getMessage());
        }
        $this->assertSame(1, DB::table('users')->where('role', 'admin')->count());
        $this->assertSame('applicant', DB::table('users')->where('id', $other)->value('role'));
        $this->assertSame(1, DB::table('admin_audit_logs')->count());
    }

    public function test_missing_or_non_exact_email_is_rejected(): void
    {
        $this->account();
        foreach (['missing@example.test', 'DEDICATED-admin@example.test'] as $email) {
            $this->refuse('exact_account_not_found', fn () => FirstAdminSetup::promote($email, 'CREATE FIRST PWDWORK ADMIN'));
        }
        $this->refuse('invalid_email_secret', fn () => FirstAdminSetup::promote(' dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN'));
    }

    public function test_existing_active_or_inactive_admin_prevents_bootstrap(): void
    {
        $this->account();
        $id = $this->account('existing-admin@example.test', 'admin');
        foreach (['active', 'suspended'] as $status) {
            DB::table('users')->where('id', $id)->update(['status' => $status]);
            $this->refuse('administrator_already_exists', fn () => FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN'));
        }
    }

    public function test_ineligible_roles_statuses_and_employer_relations_are_rejected(): void
    {
        $id = $this->account();
        foreach ([['employer', 'active'], ['applicant', 'suspended'], ['unexpected', 'active']] as [$role, $status]) {
            DB::table('users')->where('id', $id)->update(compact('role', 'status'));
            $this->refuse('account_not_eligible', fn () => FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN'));
        }
        DB::table('users')->where('id', $id)->update(['role' => 'applicant', 'status' => 'active']);
        DB::table('employers')->insert(['user_id' => $id]);
        $this->refuse('employer_account_refused', fn () => FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN'));
    }

    public function test_audit_failure_rolls_back_promotion(): void
    {
        $id = $this->account();
        AdminAuditLog::creating(static function (): void {
            throw new \RuntimeException('Synthetic audit failure');
        });
        try {
            FirstAdminSetup::promote('dedicated-admin@example.test', 'CREATE FIRST PWDWORK ADMIN');
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertSame('applicant', DB::table('users')->where('id', $id)->value('role'));
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    public function test_target_guards_reject_wrong_environment_and_injection(): void
    {
        $valid = ['host' => 'service.aivencloud.com', 'expected_host' => 'service.aivencloud.com',
            'port' => '12345', 'expected_port' => '12345', 'database' => 'pwdwork_prod'];
        FirstAdminSetup::validateTarget($valid);
        foreach ([['host' => 'other.aivencloud.com'], ['port' => '3306'], ['database' => 'pwdwork'], ['port' => '12345;id']] as $change) {
            try {
                FirstAdminSetup::validateTarget(array_replace($valid, $change));
                $this->fail('Unexpected database target accepted.');
            } catch (FirstAdminRefusal) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_applicants_and_employers_cannot_access_existing_admin_api(): void
    {
        foreach (['applicant', 'employer'] as $role) {
            $id = $this->account($role.'@example.test', $role);
            Sanctum::actingAs(User::findOrFail($id));
            $this->getJson('/api/admin/overview')->assertForbidden();
            $this->assertSame($role, DB::table('users')->where('id', $id)->value('role'));
        }
    }

    public function test_existing_public_registration_rejects_admin_role(): void
    {
        $request = Request::create('/api/register', 'POST', ['name' => 'Synthetic',
            'email' => 'public-admin@example.test', 'password' => 'Synthetic-password-123', 'role' => 'admin']);
        $request->setLaravelSession($this->app['session']->driver());
        try {
            app(AuthController::class)->register($request);
            $this->fail('Public registration accepted an admin role.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('role', $error->errors());
        }
        $this->assertSame(0, DB::table('users')->count());
    }
}
