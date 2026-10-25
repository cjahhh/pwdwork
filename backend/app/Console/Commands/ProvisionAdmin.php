<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Rules\PasswordByteLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProvisionAdmin extends Command
{
    protected $signature = 'pwdwork:provision-admin {--email= : Email of a new admin account} {--name= : Display name}';

    protected $description = 'Create a new administrator through authorized console access; passwords are prompted privately';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Use an interactive authorized console so the password is not stored in arguments or environment variables.');

            return self::FAILURE;
        }
        if (app()->isProduction() && ! $this->confirm('Create a privileged administrator in this production database?', false)) {
            return self::FAILURE;
        }
        $email = strtolower(trim($this->option('email') ?: $this->ask('New admin email')));
        $name = trim($this->option('name') ?: $this->ask('Admin display name'));
        $password = $this->secret('Password (12+ characters, mixed case and numbers)');
        $confirmation = $this->secret('Confirm password');
        $validation = Validator::make(['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers(), new PasswordByteLimit],
        ]);
        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($email, $name, $password) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin', 'status' => 'active']);
            AdminAuditLog::record(null, 'admin.provisioned', 'user', $user->id, null, 'active', 'New administrator provisioned through authorized console access.');
        });
        $this->info('Administrator created. Sign in using the normal PWDWork login.');

        return self::SUCCESS;
    }
}
