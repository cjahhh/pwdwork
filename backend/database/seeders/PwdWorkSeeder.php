<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PwdWorkSeeder extends Seeder
{
    public function run(): void
    {
        $accommodations = collect([
            ['slug' => 'remote', 'label' => 'Remote', 'icon' => '🌐'],
            ['slug' => 'onsite', 'label' => 'Onsite', 'icon' => '🏢'],
            ['slug' => 'wheelchair-access', 'label' => 'Wheelchair Access', 'icon' => '♿'],
            ['slug' => 'flexible-hours', 'label' => 'Flexible Hours', 'icon' => '⏰'],
            ['slug' => 'sign-language-support', 'label' => 'Sign Language Support', 'icon' => '🤟'],
            ['slug' => 'screen-reader-optimized', 'label' => 'Screen Reader Optimized', 'icon' => '🎧'],
        ])->mapWithKeys(function (array $row) {
            $model = Accommodation::query()->updateOrCreate(
                ['slug' => $row['slug']],
                ['label' => $row['label'], 'icon' => $row['icon']]
            );

            return [$row['label'] => $model];
        });

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@pwdwork.org'],
            [
                'name' => 'PWDWork Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $applicant = User::query()->updateOrCreate(
            ['email' => 'applicant@pwdwork.org'],
            [
                'name' => 'Jordan Cruz',
                'password' => Hash::make('password'),
                'role' => 'applicant',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $profile = ApplicantProfile::query()->updateOrCreate(
            ['user_id' => $applicant->id],
            [
                'phone' => '+63 900 555 0101',
                'location' => 'Metro Manila',
                'preferred_work_mode' => 'Remote',
                'summary' => 'Accessibility-focused jobseeker looking for inclusive remote or hybrid roles.',
                'pwd_id_number' => 'PWD-000123',
            ]
        );
        $profile->accommodations()->sync([
            $accommodations['Remote']->id,
            $accommodations['Flexible Hours']->id,
            $accommodations['Screen Reader Optimized']->id,
        ]);

        $companies = [
            [
                'email' => 'inclusive@pwdwork.org',
                'name' => 'Alex Rivera',
                'company_name' => 'InclusiveTech Labs',
                'company_logo' => 'IT',
                'company_color' => 'bg-blue-600',
                'location' => 'Remote (Global)',
                'description' => 'Inclusive product studio specializing in WCAG-compliant software.',
            ],
            [
                'email' => 'abledev@pwdwork.org',
                'name' => 'Sam Ortega',
                'company_name' => 'AbleDev Solutions',
                'company_logo' => 'AD',
                'company_color' => 'bg-teal-600',
                'location' => 'Remote (PH & Asia)',
                'description' => 'Frontend engineering team building ARIA-first component libraries.',
            ],
            [
                'email' => 'caredirect@pwdwork.org',
                'name' => 'Mia Santos',
                'company_name' => 'CareDirect Hub',
                'company_logo' => 'CD',
                'company_color' => 'bg-indigo-600',
                'location' => 'Metro Manila',
                'description' => 'Accessible customer support campus with tactile paving and ramps.',
            ],
            [
                'email' => 'equaldata@pwdwork.org',
                'name' => 'Noah Reyes',
                'company_name' => 'EqualData Inc.',
                'company_logo' => 'ED',
                'company_color' => 'bg-emerald-600',
                'location' => 'Remote (Global)',
                'description' => 'Workforce inclusion analytics and accessible dashboards.',
            ],
            [
                'email' => 'horizon@pwdwork.org',
                'name' => 'Leah Tan',
                'company_name' => 'Horizon Media',
                'company_logo' => 'HM',
                'company_color' => 'bg-purple-600',
                'location' => 'Cebu City',
                'description' => 'Plain-language educational content studio.',
            ],
            [
                'email' => 'openaccess@pwdwork.org',
                'name' => 'Chris Lim',
                'company_name' => 'OpenAccess Labs',
                'company_logo' => 'OA',
                'company_color' => 'bg-sky-600',
                'location' => 'Remote',
                'description' => 'Assistive-technology QA and accessibility testing lab.',
            ],
        ];

        $employers = [];
        foreach ($companies as $company) {
            $user = User::query()->updateOrCreate(
                ['email' => $company['email']],
                [
                    'name' => $company['name'],
                    'password' => Hash::make('password'),
                    'role' => 'employer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $employers[$company['company_name']] = Employer::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_name' => $company['company_name'],
                    'company_logo' => $company['company_logo'],
                    'company_color' => $company['company_color'],
                    'location' => $company['location'],
                    'description' => $company['description'],
                    'verification_status' => 'verified',
                ]
            );
        }

        $listings = [
            [
                'company' => 'InclusiveTech Labs',
                'title' => 'Senior UX/UI Accessibility Designer',
                'location' => 'Remote (Global)',
                'employment_type' => 'Full Time',
                'work_mode' => 'Remote',
                'description' => 'Lead the design of inclusive web applications adhering strictly to WCAG 2.1 AAA standards. Collaborate with user researchers with lived disability experience.',
                'perks' => ['Remote', 'Wheelchair Access', 'Screen Reader Optimized', 'Flexible Hours'],
            ],
            [
                'company' => 'AbleDev Solutions',
                'title' => 'Frontend Developer (Accessibility)',
                'location' => 'Remote (PH & Asia)',
                'employment_type' => 'Full Time',
                'work_mode' => 'Remote',
                'description' => 'Build semantic, ARIA-compliant component libraries. Work alongside sign language interpreters and team members using voice interface tools.',
                'perks' => ['Remote', 'Flexible Hours', 'Sign Language Support'],
            ],
            [
                'company' => 'CareDirect Hub',
                'title' => 'Customer Support Specialist',
                'location' => 'Metro Manila / Onsite',
                'employment_type' => 'Full Time',
                'work_mode' => 'Onsite',
                'description' => 'Handle incoming customer inquiries via live chat, email, and video calls. Fully equipped campus with tactile paving and wheelchair ramps.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Sign Language Support'],
            ],
            [
                'company' => 'EqualData Inc.',
                'title' => 'Data Analyst',
                'location' => 'Remote (Global)',
                'employment_type' => 'Part Time',
                'work_mode' => 'Remote',
                'description' => 'Analyze workforce inclusion metrics and construct accessible dashboards using screen-reader friendly data visualization frameworks.',
                'perks' => ['Remote', 'Flexible Hours', 'Screen Reader Optimized'],
            ],
            [
                'company' => 'Horizon Media',
                'title' => 'Content Specialist',
                'location' => 'Cebu City / Onsite',
                'employment_type' => 'Full Time',
                'work_mode' => 'Onsite',
                'description' => 'Write clear, plain-language text and craft comprehensive alt-text captions for digital educational campaigns.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Flexible Hours'],
            ],
            [
                'company' => 'OpenAccess Labs',
                'title' => 'Software QA Engineer',
                'location' => 'Remote',
                'employment_type' => 'Contract',
                'work_mode' => 'Remote',
                'description' => 'Perform automated and manual accessibility testing across assistive technology screen readers, switch devices, and eye-tracking software.',
                'perks' => ['Remote', 'Sign Language Support', 'Wheelchair Access'],
            ],
        ];

        $firstListing = null;
        foreach ($listings as $listing) {
            $job = JobListing::query()->updateOrCreate(
                [
                    'employer_id' => $employers[$listing['company']]->id,
                    'title' => $listing['title'],
                ],
                [
                    'location' => $listing['location'],
                    'employment_type' => $listing['employment_type'],
                    'work_mode' => $listing['work_mode'],
                    'description' => $listing['description'],
                    'status' => 'open',
                ]
            );

            $job->accommodations()->sync(
                collect($listing['perks'])->map(fn (string $label) => $accommodations[$label]->id)->all()
            );

            $firstListing ??= $job;
        }

        Application::query()->updateOrCreate(
            [
                'user_id' => $applicant->id,
                'job_listing_id' => $firstListing->id,
            ],
            [
                'cover_letter' => 'I would like to apply. This workplace lists the accommodations I need.',
                'status' => 'reviewing',
            ]
        );

        $applicant->savedJobs()->syncWithoutDetaching([$firstListing->id]);

        UserNotification::query()->updateOrCreate(
            [
                'user_id' => $applicant->id,
                'title' => 'Application received',
            ],
            [
                'body' => 'InclusiveTech Labs is reviewing your application for Senior UX/UI Accessibility Designer.',
                'read_at' => null,
            ]
        );

        UserNotification::query()->updateOrCreate(
            [
                'user_id' => $admin->id,
                'title' => 'Employer verification queue',
            ],
            [
                'body' => 'Six inclusive employers are marked verified in the demo seed.',
                'read_at' => null,
            ]
        );
    }
}
