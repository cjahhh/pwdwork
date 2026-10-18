<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Application;
use App\Models\JobListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JobListing::query()->with(['employer', 'accommodations'])->where('status', 'open');

        if ($request->filled('query')) {
            $term = $request->string('query')->toString();
            $query->where(function ($builder) use ($term) {
                $builder->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('employer', fn ($employer) => $employer->where('company_name', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('location')) {
            $query->where('location', 'like', '%' . $request->string('location')->toString() . '%');
        }

        $jobs = $query->latest()->get()->unique(fn (JobListing $job) => $this->jobFingerprint($job));

        return response()->json(['jobs' => $jobs->map(fn (JobListing $job) => $this->jobPayload($job))->values()]);
    }

    public function show(JobListing $jobListing): JsonResponse
    {
        abort_unless($jobListing->status === 'open', 404);
        return response()->json(['job' => $this->jobPayload($jobListing->load(['employer', 'accommodations']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', 'string', 'max:32'],
            'work_mode' => ['required', Rule::in(['Remote', 'Hybrid', 'Onsite'])],
            'description' => ['required', 'string'],
            'accommodations' => ['array'],
            'accommodations.*' => ['string', 'max:255'],
        ]);

        $job = $request->user()->employer->jobListings()->firstOrCreate([
            'title' => $data['title'],
            'location' => $data['location'],
            'employment_type' => $data['employment_type'],
            'work_mode' => $data['work_mode'],
            'description' => $data['description'],
        ], [
            'status' => 'open',
        ]);

        $this->syncAccommodations($job, $data['accommodations'] ?? []);

        return response()->json(['job' => $this->jobPayload($job->load(['employer', 'accommodations']))], 201);
    }

    public function employerListings(Request $request): JsonResponse
    {
        $jobs = $request->user()->employer->jobListings()
            ->with(['employer', 'accommodations'])
            ->latest()
            ->get()
            ->unique(fn (JobListing $job) => $this->jobFingerprint($job));
        return response()->json(['jobs' => $jobs->map(fn (JobListing $job) => $this->jobPayload($job))->values()]);
    }

    public function update(Request $request, JobListing $jobListing): JsonResponse
    {
        abort_unless($jobListing->employer_id === $request->user()->employer->id, 403);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'employment_type' => ['sometimes', 'required', 'string', 'max:32'],
            'work_mode' => ['sometimes', 'required', Rule::in(['Remote', 'Hybrid', 'Onsite'])],
            'description' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['open', 'closed'])],
        ]);
        $jobListing->update($data);
        return response()->json(['job' => $this->jobPayload($jobListing->load(['employer', 'accommodations']))]);
    }

    public function apply(Request $request, JobListing $jobListing): JsonResponse
    {
        abort_unless($request->user()->isApplicant(), 403);
        $application = Application::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'job_listing_id' => $jobListing->id],
            ['cover_letter' => $request->input('cover_letter'), 'status' => 'submitted']
        );
        return response()->json(['application' => $application], $application->wasRecentlyCreated ? 201 : 200);
    }

    public function employerApplications(Request $request): JsonResponse
    {
        $applications = Application::query()
            ->with(['user', 'jobListing'])
            ->whereHas('jobListing', fn ($query) => $query->where('employer_id', $request->user()->employer->id))
            ->latest()
            ->get()
            ->unique(fn (Application $application) => implode('|', [
                $application->user_id,
                strtolower(trim((string) $application->jobListing?->title)),
                strtolower(trim((string) $request->user()->employer->company_name)),
            ]))
            ->map(fn (Application $application) => [
                'id' => $application->id,
                'name' => $application->user->name,
                'email' => $application->user->email,
                'jobId' => $application->job_listing_id,
                'jobTitle' => $application->jobListing->title,
                'appliedAt' => $application->created_at?->toDateString(),
                'status' => match ($application->status) {
                    'submitted' => 'New',
                    'reviewing' => 'Reviewing',
                    'interview' => 'Interview',
                    'hired' => 'Shortlisted',
                    'rejected' => 'Rejected',
                    default => ucfirst($application->status),
                },
                'coverLetter' => $application->cover_letter,
                'experience' => 'Applicant profile available',
                'accommodations' => [],
                'interviewDate' => $application->interview_date?->toDateString(),
                'interviewTime' => $application->interview_time,
                'interviewNotes' => $application->interview_notes,
            ]);

        return response()->json(['applicants' => $applications->values()]);
    }

    public function updateApplication(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->jobListing->employer_id === $request->user()->employer->id, 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['submitted', 'reviewing', 'interview', 'hired', 'rejected'])],
            'interview_date' => ['nullable', 'date'],
            'interview_time' => ['nullable', 'date_format:H:i'],
            'interview_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $application->update($data);
        return response()->json(['application' => $application->fresh(['user', 'jobListing'])]);
    }

    public function applicantApplications(Request $request): JsonResponse
    {
        $applications = $request->user()->applications()->with('jobListing.employer')->latest()->get()->map(fn (Application $application) => [
            'applicationId' => $application->id,
            'jobId' => $application->job_listing_id,
            'jobTitle' => $application->jobListing->title,
            'company' => $application->jobListing->employer?->company_name,
            'appliedAt' => $application->created_at?->toDateString(),
            'status' => match ($application->status) {
                'submitted' => 'Under review',
                'reviewing' => 'Under review',
                'interview' => 'Interview',
                'hired' => 'Accepted',
                'rejected' => 'Closed',
                default => ucfirst($application->status),
            },
            'interviewDate' => $application->interview_date?->toDateString(),
            'interviewTime' => $application->interview_time,
            'interviewNotes' => $application->interview_notes,
        ]);

        return response()->json(['applications' => $applications->values()]);
    }

    private function syncAccommodations(JobListing $job, array $labels): void
    {
        $ids = collect($labels)->map(fn (string $label) => Accommodation::query()->firstOrCreate(
            ['label' => $label],
            ['slug' => str($label)->slug(), 'icon' => '']
        )->id)->all();
        $job->accommodations()->sync($ids);
    }

    private function jobFingerprint(JobListing $job): string
    {
        return collect([
            $job->employer_id,
            $job->title,
            $job->location,
            $job->employment_type,
            $job->work_mode,
            $job->description,
        ])->map(fn ($value) => strtolower(trim((string) $value)))->implode('|');
    }

    private function jobPayload(JobListing $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'company' => $job->employer?->company_name,
            'companyLogo' => $job->employer?->company_logo ?: 'CO',
            'companyColor' => $job->employer?->company_color ?: 'bg-teal-600',
            'verificationStatus' => $job->employer?->verification_status ?: 'pending',
            'verified' => $job->employer?->verification_status === 'verified',
            'location' => $job->location,
            'type' => $job->employment_type,
            'mode' => $job->work_mode,
            'description' => $job->description,
            'status' => ucfirst($job->status),
            'accommodations' => $job->accommodations->map(fn ($accommodation) => ['label' => $accommodation->label, 'icon' => $accommodation->icon])->values(),
            'tagList' => collect([$job->work_mode])->merge($job->accommodations->pluck('label'))->values(),
        ];
    }
}
