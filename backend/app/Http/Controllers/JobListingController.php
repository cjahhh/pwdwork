<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Models\Accommodation;
use App\Models\AdminAuditLog;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class JobListingController extends Controller
{
    use PaginatesCollections;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['query' => ['nullable', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255']]);
        $query = JobListing::query()->with(['employer.user', 'accommodations'])->publiclyVisible();

        if ($request->filled('query')) {
            $term = $request->string('query')->toString();
            $query->where(function ($builder) use ($term) {
                $builder->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('employer', fn ($employer) => $employer->where('company_name', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('location')) {
            $query->where('location', 'like', '%'.$request->string('location')->toString().'%');
        }

        $filters = $request->validate([
            'modes' => ['array', 'max:3'], 'modes.*' => [Rule::in(['Remote', 'Onsite', 'Hybrid'])],
            'types' => ['array', 'max:3'], 'types.*' => [Rule::in(JobListing::EMPLOYMENT_TYPES)],
            'flexible' => ['sometimes', 'boolean'], 'accommodations' => ['array', 'max:6'],
            'accommodations.*' => [Rule::in(Accommodation::STANDARD_LABELS)],
        ]);
        if (! empty($filters['modes'])) {
            $query->whereIn('work_mode', $filters['modes']);
        }
        if (! empty($filters['types'])) {
            $query->whereIn('employment_type', $filters['types']);
        }
        if (! empty($filters['flexible'])) {
            $query->where('flexible_schedule', true);
        }
        foreach ($filters['accommodations'] ?? [] as $label) {
            $query->whereHas('accommodations', fn ($q) => $q->where('label', $label));
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'jobs', fn ($job) => $this->jobPayload($job));
    }

    public function show(JobListing $jobListing): JsonResponse
    {
        abort_unless(JobListing::publiclyVisible()->whereKey($jobListing->id)->exists(), 404);

        return response()->json(['job' => $this->jobPayload($jobListing->load(['employer.user', 'accommodations']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(JobListing::EMPLOYMENT_TYPES)],
            'work_mode' => ['required', Rule::in(['Remote', 'Hybrid', 'Onsite'])],
            'description' => ['required', 'string', 'max:10000'],
            'accommodations' => ['array', 'max:6'],
            'accommodations.*' => [Rule::in(Accommodation::STANDARD_LABELS)],
            'flexible_schedule' => ['sometimes', 'boolean'],
        ]);

        $job = DB::transaction(function () use ($request, $data) {
            $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->status === 'active', 403);
            $employer = $owner->employer()->lockForUpdate()->firstOrFail();
            $labels = $data['accommodations'] ?? [];
            unset($data['accommodations']);
            $job = $employer->jobListings()->create([...$data, 'status' => $employer->verification_status === 'verified' ? 'open' : 'closed', 'moderation_status' => 'pending']);
            $this->syncAccommodations($job, $labels);
            AdminAuditLog::record($owner, 'job.created', 'job', $job->id, null, 'pending', 'New listing saved for administrative review.');

            return $job;
        }, 3);

        return response()->json(['job' => $this->jobPayload($job->refresh()->load(['employer.user', 'accommodations']), true)], 201);
    }

    public function employerListings(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'query' => ['nullable', 'string', 'max:255'],
        ]);
        $jobs = $request->user()->employer->jobListings()
            ->with(['employer.user', 'accommodations']);
        if (! empty($filters['status'])) {
            $jobs->where('status', $filters['status']);
        }
        if (! empty($filters['query'])) {
            $jobs->where('title', 'like', '%'.$filters['query'].'%');
        }

        return $this->collectionResponse($request, $jobs->latest()->orderByDesc('job_listings.id'), 'jobs', fn ($job) => $this->jobPayload($job, true));
    }

    public function update(Request $request, JobListing $jobListing): JsonResponse
    {
        abort_unless($jobListing->employer_id === $request->user()->employer->id, 403);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'employment_type' => ['sometimes', 'required', Rule::in(JobListing::EMPLOYMENT_TYPES)],
            'work_mode' => ['sometimes', 'required', Rule::in(['Remote', 'Hybrid', 'Onsite'])],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'status' => ['sometimes', 'required', Rule::in(['open', 'closed'])],
            'accommodations' => ['sometimes', 'array', 'max:6'],
            'accommodations.*' => [Rule::in(Accommodation::STANDARD_LABELS)],
            'flexible_schedule' => ['sometimes', 'boolean'],
        ]);
        $labels = $data['accommodations'] ?? null;
        unset($data['accommodations']);
        DB::transaction(function () use ($request, $jobListing, $data, $labels) {
            $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->status === 'active', 403);
            $employer = $owner->employer()->lockForUpdate()->firstOrFail();
            $job = JobListing::whereKey($jobListing->id)->lockForUpdate()->firstOrFail();
            if (($data['status'] ?? null) === 'open') {
                abort_unless($employer->verification_status === 'verified' && $job->moderation_status === 'approved', 422, 'Reopening requires employer verification and listing approval. Submit pending or rejected listings for review instead.');
            }
            $before = $job->moderation_status;
            $job->fill($data);
            $contentChanged = $job->isDirty(['title', 'location', 'employment_type', 'work_mode', 'description', 'flexible_schedule']);
            if ($labels !== null) {
                $existing = $job->accommodations()->pluck('label')->sort()->values()->all();
                $contentChanged = $contentChanged || $existing !== collect($labels)->unique()->sort()->values()->all();
            }
            if ($contentChanged && $job->moderation_status === 'approved') {
                $job->moderation_status = 'pending';
                $job->moderation_reason = null;
            }
            $job->review_version++;
            $job->save();
            if ($labels !== null) {
                $this->syncAccommodations($job, $labels);
            }
            AdminAuditLog::record($owner, 'job.updated', 'job', $job->id, $before, $job->moderation_status, $contentChanged ? 'Listing content edited; approved content requires another review.' : 'Listing availability updated.');
        }, 3);

        return response()->json(['job' => $this->jobPayload($jobListing->refresh()->load(['employer.user', 'accommodations']), true)]);
    }

    public function apply(Request $request, JobListing $jobListing): JsonResponse
    {
        abort_unless($request->user()->isApplicant(), 403);
        $request->validate([
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'share_accommodations' => ['sometimes', 'boolean'],
        ]);
        $application = DB::transaction(function () use ($request, $jobListing) {
            $applicant = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($applicant->status === 'active', 403);
            $employer = $jobListing->employer;
            $lockedOwner = User::whereKey($employer->user_id)->lockForUpdate()->firstOrFail();
            $lockedEmployer = $employer->newQuery()->whereKey($employer->id)->lockForUpdate()->firstOrFail();
            $job = JobListing::whereKey($jobListing->id)->lockForUpdate()->firstOrFail();
            // Use current locking reads, including on MySQL REPEATABLE READ.
            $lockedEmployer->setRelation('user', $lockedOwner);
            $job->setRelation('employer', $lockedEmployer);
            abort_unless($job->isPubliclyAvailable(), 422, 'This job is not currently accepting applications.');

            return Application::query()->firstOrCreate(
                ['user_id' => $applicant->id, 'job_listing_id' => $job->id],
                ['cover_letter' => $request->input('cover_letter'), 'status' => 'submitted', 'share_accommodations' => $request->boolean('share_accommodations')]
            );
        }, 3);

        return response()->json(['application' => $application], $application->wasRecentlyCreated ? 201 : 200);
    }

    public function employerApplications(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Application::STATUSES)],
            'job_id' => ['sometimes', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:255'],
        ]);
        $applications = Application::query()
            ->with(['user.applicantProfile.accommodations', 'jobListing', ...Application::confirmedRequestRelations(), ...Application::resumeRelations()])
            ->whereHas('jobListing', fn ($query) => $query->where('employer_id', $request->user()->employer->id));
        if (! empty($filters['status'])) {
            $applications->where('status', $filters['status']);
        }
        if (! empty($filters['job_id'])) {
            $applications->where('job_listing_id', $filters['job_id']);
        }
        if (! empty($filters['query'])) {
            $term = '%'.$filters['query'].'%';
            $applications->where(function ($query) use ($term) {
                $query->whereHas('user', fn ($user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHas('jobListing', fn ($job) => $job->where('title', 'like', $term))
                    ->orWhereHas('user.applicantProfile', fn ($profile) => $profile->where('experience', 'like', $term)->orWhere('summary', 'like', $term));
            });
        }

        return $this->collectionResponse($request, $applications->latest()->orderByDesc('id'), 'applicants', fn ($application) => $this->employerApplicationPayload($application));
    }

    public function updateApplication(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->jobListing->employer_id === $request->user()->employer->id, 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(Application::STATUSES)],
            'interview_date' => ['required_if:status,interview', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'interview_time' => ['required_if:status,interview', 'nullable', 'date_format:H:i'],
            'interview_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['status'] !== 'interview') {
            $data['interview_date'] = null;
            $data['interview_time'] = null;
            $data['interview_notes'] = null;
        }
        DB::transaction(function () use ($request, $application, $data) {
            $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->status === 'active', 403);
            $record = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->jobListing->employer->user_id === $owner->id, 403);
            $record->fill($data);
            // MySQL TIME values include seconds; identical H:i retries must not create notices.
            $interviewChanged = $record->status === 'interview' && (
                $record->isDirty(['status', 'interview_date', 'interview_notes'])
                || substr((string) $record->getRawOriginal('interview_time'), 0, 5) !== substr((string) $record->interview_time, 0, 5)
            );
            $wasInterview = $record->getRawOriginal('status') === 'interview';
            $record->save();
            if ($interviewChanged) {
                // In-app delivery is atomic with scheduling and needs no queue worker.
                UserNotification::create([
                    'user_id' => $record->user_id,
                    'title' => $wasInterview ? 'Interview updated' : 'Interview scheduled',
                    'body' => 'Your interview schedule has an update. View your applications for the current schedule and details.',
                    'action_url' => '/applicant/applications',
                ]);
            }
        }, 3);

        return response()->json(['application' => $this->employerApplicationPayload($application->fresh(['user.applicantProfile.accommodations', 'jobListing', ...Application::confirmedRequestRelations(), ...Application::resumeRelations()]))]);
    }

    public function applicantApplications(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(Application::STATUSES)], 'application_id' => ['sometimes', 'integer', 'min:1']]);
        $applications = $request->user()->applications()->with(['jobListing.employer', ...Application::confirmedRequestRelations(), ...Application::resumeRelations()]);
        if (isset($filters['application_id'])) {
            $applications->whereKey($filters['application_id']);
        }
        if (! empty($filters['status'])) {
            $applications->where('status', $filters['status']);
        }

        return $this->collectionResponse($request, $applications->latest()->orderByDesc('id'), 'applications', fn (Application $application) => [
            'applicationId' => $application->id,
            'jobId' => $application->job_listing_id,
            'jobTitle' => $application->jobListing->title,
            'company' => $application->jobListing->employer?->company_name,
            'employerId' => $application->jobListing->employer_id,
            'appliedAt' => $application->created_at?->toDateString(),
            'status' => $application->statusLabel(),
            'statusCode' => $application->status,
            'shareAccommodations' => $application->share_accommodations,
            'builtResumeShare' => $application->resumeShareMetadata(),
            'interviewDate' => $application->interview_date?->toDateString(),
            'interviewTime' => $application->interview_time,
            'interviewNotes' => $application->interview_notes,
            'confirmedArrangements' => $application->confirmedArrangements(),
        ]);
    }

    public function saved(Request $request): JsonResponse
    {
        $jobs = $request->user()->savedJobs()->with(['employer.user', 'accommodations'])->orderByDesc('saved_jobs.id');

        return $this->collectionResponse($request, $jobs, 'jobs', fn ($job) => $job->isPubliclyAvailable() ? $this->jobPayload($job) : [
            'id' => $job->id, 'title' => 'Unavailable listing', 'company' => 'Listing currently unavailable',
            'companyLogo' => 'CO', 'companyColor' => 'bg-gray-600', 'type' => 'Unavailable', 'mode' => '', 'location' => '',
            'description' => 'This saved listing is closed or awaiting an administrative review. You can keep it saved or remove it.',
            'status' => 'Unavailable', 'verified' => false, 'accommodations' => [], 'publicAvailable' => false,
        ]);
    }

    public function savedState(Request $request, JobListing $jobListing): JsonResponse
    {
        return response()->json(['saved' => $request->user()->savedJobs()->where('job_listings.id', $jobListing->id)->exists()]);
    }

    public function updateSharing(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id, 403);
        $data = $request->validate(['share_accommodations' => ['required', 'boolean']]);
        $application->update($data);

        return response()->json(['shareAccommodations' => $application->share_accommodations]);
    }

    public function resume(Request $request, Application $application)
    {
        abort_unless($application->jobListing->employer_id === $request->user()->employer->id, 403);
        $path = $application->user->applicantProfile?->resume_path;
        abort_unless($this->validResumePath($path), 404, 'No resume is available.');
        $disk = Storage::disk(config('filesystems.resume_disk'));
        abort_unless($disk->exists($path), 404, 'No resume is available.');

        return $disk->download($path, 'resume.'.strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['Cache-Control' => 'private, no-store']);
    }

    private function validResumePath(?string $path): bool
    {
        return $path && ! preg_match('~(^[/\\\\]|[\\\\:]|[\x00-\x1F\x7F]|(^|/)\.\.(/|$))~', $path)
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true);
    }

    private function employerApplicationPayload(Application $application): array
    {
        $profile = $application->user->applicantProfile;
        $resumePath = $profile?->resume_path;
        $resumeAvailable = $this->validResumePath($resumePath)
            && Storage::disk(config('filesystems.resume_disk'))->exists($resumePath);

        return [
            'id' => $application->id, 'accountId' => $application->user_id, 'name' => $application->user->name, 'email' => $application->user->email,
            'jobId' => $application->job_listing_id, 'jobTitle' => $application->jobListing->title,
            'appliedAt' => $application->created_at?->toDateString(),
            'status' => $application->statusLabel(true), 'statusCode' => $application->status,
            'headline' => $profile?->headline ?? '', 'skills' => $profile?->skills ?? [],
            'experience' => $profile?->experience ?? '', 'summary' => $profile?->summary ?? '',
            'coverLetter' => $application->cover_letter ?? '',
            'resumeAvailable' => $resumeAvailable,
            'builtResumeShare' => $application->resumeShareMetadata(),
            'accommodationsShared' => $application->share_accommodations,
            // Only standardized support requests are shared, never arbitrary legacy labels.
            'accommodations' => $application->share_accommodations
                ? ($profile?->accommodations->whereIn('label', Accommodation::STANDARD_LABELS)->pluck('label')->values()->all() ?? []) : [],
            'interviewDate' => $application->interview_date?->toDateString(),
            'interviewTime' => $application->interview_time, 'interviewNotes' => $application->interview_notes,
            'confirmedArrangements' => $application->confirmedArrangements(),
        ];
    }

    public function saveJob(Request $request, JobListing $jobListing): JsonResponse
    {
        abort_unless(JobListing::publiclyVisible()->whereKey($jobListing->id)->exists(), 404);
        $request->user()->savedJobs()->syncWithoutDetaching([$jobListing->id]);

        return response()->json(['message' => 'Job saved.']);
    }

    public function unsaveJob(Request $request, JobListing $jobListing): JsonResponse
    {
        $request->user()->savedJobs()->detach($jobListing->id);

        return response()->json(['message' => 'Job removed.']);
    }

    private function syncAccommodations(JobListing $job, array $labels): void
    {
        $ids = collect($labels)->map(fn (string $label) => Accommodation::query()->firstOrCreate(
            ['slug' => (string) str($label)->slug()],
            ['label' => $label, 'icon' => '']
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

    protected function jobPayload(JobListing $job, bool $owner = false): array
    {
        return [
            'id' => $job->id,
            'employerId' => $job->employer_id,
            'title' => $job->title,
            'company' => $job->employer?->company_name,
            'companyLogo' => $job->employer?->company_logo ?: 'CO',
            'companyColor' => $job->employer?->company_color ?: 'bg-teal-600',
            'verificationStatus' => $job->employer?->verification_status ?: 'pending',
            'verified' => $job->employer?->verification_status === 'verified',
            'location' => $job->location,
            'type' => $job->employment_type,
            'mode' => $job->work_mode,
            'flexibleSchedule' => $job->flexible_schedule,
            'accommodationsVerified' => false,
            'description' => $job->description,
            'status' => ucfirst($job->status),
            'publicAvailable' => $job->isPubliclyAvailable(),
            ...($owner ? ['moderationStatus' => $job->moderation_status, 'moderationReason' => $job->moderation_reason, 'reviewVersion' => $job->review_version] : []),
            'accommodations' => $job->accommodations->map(fn ($accommodation) => ['label' => $accommodation->label, 'icon' => $accommodation->icon])->values(),
            'tagList' => collect([$job->work_mode])->merge($job->accommodations->pluck('label'))->values(),
        ];
    }
}
