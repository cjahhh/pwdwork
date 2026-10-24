<?php

// Invoked only by scripts/browser-smoke.mjs with a new, empty temporary database.
use App\Models\Accommodation;
use App\Models\Application;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$database = getenv('DB_DATABASE');
$tempRoot = realpath(sys_get_temp_dir());
$directory = $database ? realpath(dirname($database)) : false;
if (getenv('PWDWORK_BROWSER_TEST') !== '1' || ! $directory
    || realpath(dirname($directory)) !== $tempRoot
    || ! str_starts_with(basename($directory), 'pwdwork-browser-')
    || basename($database) !== 'browser.sqlite' || ! is_file($database) || filesize($database) !== 0) {
    throw new RuntimeException('Refusing to initialize anything except a new temporary browser fixture database.');
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $database
    || config('database.connections.sqlite.url')) {
    throw new RuntimeException('Browser fixture requires its isolated SQLite connection.');
}
Artisan::call('migrate', ['--force' => true]);
$owner = User::factory()->create(['name' => 'Browser Employer', 'email' => 'owner@browser.example', 'role' => 'employer']);
$employer = Employer::create(['user_id' => $owner->id, 'company_name' => 'Browser Company', 'verification_status' => 'verified']);
$candidate = User::factory()->create(['name' => 'Browser Candidate', 'email' => 'candidate@browser.example', 'role' => 'applicant']);
$candidate->applicantProfile()->create(['headline' => 'Support specialist', 'summary' => 'Professional browser summary', 'experience' => 'Support volunteering', 'skills' => ['Excel']]);
$accommodation = Accommodation::create(['slug' => 'accessible-restroom', 'label' => 'Accessible restroom']);
$candidate->applicantProfile->accommodations()->attach($accommodation);
foreach (range(1, 27) as $i) {
    $job = $employer->jobListings()->create([
        'title' => 'Browser Support '.$i, 'location' => 'Manila', 'employment_type' => $i <= 16 ? 'Full Time' : 'Contract',
        'work_mode' => 'Remote', 'description' => 'Support customers with accessible service.', 'status' => 'open', 'moderation_status' => 'approved', 'flexible_schedule' => true,
    ]);
    $job->accommodations()->attach($accommodation);
    $candidate->savedJobs()->attach($job);
    Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id, 'status' => $i === 1 ? 'hired' : 'submitted', 'cover_letter' => 'Browser cover letter']);
}
foreach (range(1, 14) as $i) {
    $person = User::factory()->create(['name' => 'Other Candidate '.$i, 'email' => 'other'.$i.'@browser.example', 'role' => 'applicant']);
    Application::create(['user_id' => $person->id, 'job_listing_id' => 1, 'status' => 'submitted']);
}
echo 'Isolated browser fixtures ready: '.DB::table('job_listings')->count()." jobs.\n";
// Additional Phase 2 fixtures are test-only; no privileged demo account is seeded in real installations.
User::factory()->create(['name' => 'Browser Admin', 'email' => 'admin@browser.example', 'role' => 'admin']);
$pendingOwner = User::factory()->create(['name' => 'Pending Owner', 'email' => 'pending@browser.example', 'role' => 'employer']);
Employer::create(['user_id' => $pendingOwner->id, 'company_name' => 'Pending Browser Company', 'description' => 'Company awaiting verification.', 'location' => 'Manila']);
