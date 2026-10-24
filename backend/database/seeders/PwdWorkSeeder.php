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
            ['slug' => 'wheelchair-access', 'label' => 'Step-free or wheelchair-accessible entrance', 'icon' => '♿'],
            ['slug' => 'flexible-hours', 'label' => 'Flexible Hours', 'icon' => '⏰'],
            ['slug' => 'sign-language-support', 'label' => 'Sign-language interpretation', 'icon' => '🤟'],
            ['slug' => 'screen-reader-optimized', 'label' => 'Assistive technology support', 'icon' => '🎧'],
        ])->mapWithKeys(function (array $row) {
            $model = Accommodation::query()->updateOrCreate(
                ['slug' => $row['slug']],
                ['label' => $row['label'], 'icon' => $row['icon']]
            );

            return [$row['label'] => $model];
        });

        // Privileged accounts are provisioned through the authorized CLI only.

        $applicant = User::query()->firstOrCreate(
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
            $accommodations['Assistive technology support']->id,
        ]);

        $companies = [
            [
                'email' => 'inclusive@pwdwork.org',
                'name' => 'Alex Rivera',
                'company_name' => 'CareDirect Hub',
                'company_logo' => 'IT',
                'company_color' => 'bg-blue-600',
                'location' => 'Remote (Global)',
                'description' => 'Inclusive product studio specializing in WCAG-compliant software.',
            ],
            [
                'email' => 'abledev@pwdwork.org',
                'name' => 'Sam Ortega',
                'company_name' => 'Mabuhay Distribution',
                'company_logo' => 'AD',
                'company_color' => 'bg-teal-600',
                'location' => 'Remote (PH & Asia)',
                'description' => 'Frontend engineering team building ARIA-first component libraries.',
            ],
            [
                'email' => 'caredirect@pwdwork.org',
                'name' => 'Mia Santos',
                'company_name' => 'Good Grain Bakery',
                'company_logo' => 'CD',
                'company_color' => 'bg-indigo-600',
                'location' => 'Metro Manila',
                'description' => 'Accessible customer support campus with tactile paving and ramps.',
            ],
            [
                'email' => 'equaldata@pwdwork.org',
                'name' => 'Noah Reyes',
                'company_name' => 'Community Health Center',
                'company_logo' => 'ED',
                'company_color' => 'bg-emerald-600',
                'location' => 'Remote (Global)',
                'description' => 'Workforce inclusion analytics and accessible dashboards.',
            ],
            [
                'email' => 'horizon@pwdwork.org',
                'name' => 'Leah Tan',
                'company_name' => 'Everyday Market',
                'company_logo' => 'HM',
                'company_color' => 'bg-purple-600',
                'location' => 'Cebu City',
                'description' => 'Plain-language educational content studio.',
            ],
            [
                'email' => 'openaccess@pwdwork.org',
                'name' => 'Chris Lim',
                'company_name' => 'GreenPath Cooperative',
                'company_logo' => 'OA',
                'company_color' => 'bg-sky-600',
                'location' => 'Remote',
                'description' => 'Assistive-technology QA and accessibility testing lab.',
            ],
        ];

        $employers = [];
        foreach ($companies as $company) {
            $user = User::query()->firstOrCreate(
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
                    'verification_status' => 'pending',
                ]
            );
        }

        $listings = [
            [
                'company' => 'CareDirect Hub',
                'title' => 'Customer Service Representative',
                'location' => 'Remote or Metro Manila',
                'employment_type' => 'Full Time',
                'work_mode' => 'Remote',
                'description' => 'Answer customer questions by phone, chat, or email and help resolve everyday service concerns. Training and scripts are provided.',
                'perks' => ['Remote', 'Flexible Hours', 'Screen Reader Optimized'],
            ],
            [
                'company' => 'Mabuhay Distribution',
                'title' => 'Warehouse Inventory Assistant',
                'location' => 'Quezon City / Onsite',
                'employment_type' => 'Part Time',
                'work_mode' => 'Onsite',
                'description' => 'Count and label incoming goods, update simple inventory sheets, and prepare small orders. Seated and light-duty tasks are available.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Flexible Hours'],
            ],
            [
                'company' => 'Good Grain Bakery',
                'title' => 'Bakery Production Assistant',
                'location' => 'Pasig City / Onsite',
                'employment_type' => 'Part Time',
                'work_mode' => 'Onsite',
                'description' => 'Pack baked goods, place labels, and keep the work area organized. Tasks can be adjusted for seated work and shorter shifts.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Flexible Hours'],
            ],
            [
                'company' => 'Community Health Center',
                'title' => 'Receptionist and Records Clerk',
                'location' => 'Cebu City / Onsite',
                'employment_type' => 'Full Time',
                'work_mode' => 'Onsite',
                'description' => 'Welcome visitors, answer calls, organize appointment records, and enter basic information. A quiet desk and accessible workstation are provided.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Flexible Hours'],
            ],
            [
                'company' => 'Everyday Market',
                'title' => 'Retail Stock and Checkout Assistant',
                'location' => 'Davao City / Onsite',
                'employment_type' => 'Part Time',
                'work_mode' => 'Onsite',
                'description' => 'Restock light products, keep shelves tidy, and assist customers at the checkout counter. Flexible shifts and an accessible entrance are available.',
                'perks' => ['Onsite', 'Wheelchair Access', 'Flexible Hours'],
            ],
            [
                'company' => 'GreenPath Cooperative',
                'title' => 'Community Garden Assistant',
                'location' => 'Antipolo / Onsite',
                'employment_type' => 'Contract',
                'work_mode' => 'Onsite',
                'description' => 'Help water plants, sort seeds, prepare produce boxes, and maintain garden paths. Adaptive tools and task coaching are available.',
                'perks' => ['Onsite', 'Flexible Hours', 'Wheelchair Access'],
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
                    'flexible_schedule' => in_array('Flexible Hours', $listing['perks'], true),
                ]
            );

            $job->accommodations()->sync(
                collect($listing['perks'])->reject(fn ($label) => in_array($label, ['Remote', 'Onsite', 'Flexible Hours'], true))->map(fn ($label) => match ($label) {
                    'Wheelchair Access' => 'Step-free or wheelchair-accessible entrance', 'Screen Reader Optimized' => 'Assistive technology support', 'Sign Language Support' => 'Sign-language interpretation', default => $label
                })->map(fn ($label) => $accommodations[$label]->id)->all()
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

    }
}
