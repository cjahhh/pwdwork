<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\AdminIndexRequest;
use App\Models\AdminAuditLog;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserReport;
use App\Services\AdminDashboardService;
use App\Services\ModerationService;
use App\Support\AdminPayload;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    use PaginatesCollections;

    public function overview(AdminDashboardService $dashboard)
    {
        return response()->json(['stats' => $dashboard->statistics()]);
    }

    public function dashboard(AdminDashboardService $dashboard)
    {
        return response()->json($dashboard->dashboard());
    }

    public function employers(AdminIndexRequest $request)
    {
        $query = Employer::with('user');
        if ($request->filled('status')) {
            $query->where('verification_status', $request->input('status'));
        }
        if ($request->filled('employer_id')) {
            $query->whereKey($request->integer('employer_id'));
        }
        if ($request->filled('query')) {
            $term = '%'.$request->input('query').'%';
            $query->where(fn ($q) => $q->where('company_name', 'like', $term)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'employers', AdminPayload::employer(...));
    }

    public function employer(Employer $employer)
    {
        return response()->json(['employer' => AdminPayload::employer($employer->load('user'))]);
    }

    public function verifyEmployer(AdminActionRequest $request, Employer $employer, ModerationService $service)
    {
        return response()->json(['employer' => AdminPayload::employer($service->verifyEmployer($request->user(), $employer, $request->validated()))]);
    }

    public function jobs(AdminIndexRequest $request)
    {
        $query = JobListing::with(['employer.user', 'accommodations']);
        if ($request->filled('query')) {
            $query->where('title', 'like', '%'.$request->input('query').'%');
        }
        if ($request->filled('status')) {
            $query->where('moderation_status', $request->input('status'));
        }
        if ($request->filled('employer_id')) {
            $query->where('employer_id', $request->integer('employer_id'));
        }
        if ($request->filled('job_id')) {
            $query->whereKey($request->integer('job_id'));
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'jobs', AdminPayload::job(...));
    }

    public function job(JobListing $jobListing)
    {
        return response()->json(['job' => AdminPayload::job($jobListing->load(['employer.user', 'accommodations']))]);
    }

    public function moderateJob(AdminActionRequest $request, JobListing $jobListing, ModerationService $service)
    {
        return response()->json(['job' => AdminPayload::job($service->moderateJob($request->user(), $jobListing, $request->validated()))]);
    }

    public function users(AdminIndexRequest $request)
    {
        $query = User::query();
        foreach (['role', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('user_id')) {
            $query->whereKey($request->integer('user_id'));
        }
        if ($request->filled('query')) {
            $term = '%'.$request->input('query').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'users', AdminPayload::user(...));
    }

    public function user(User $user)
    {
        return response()->json(['user' => AdminPayload::user($user)]);
    }

    public function updateUser(AdminActionRequest $request, User $user, ModerationService $service)
    {
        return response()->json(['user' => AdminPayload::user($service->updateUser($request->user(), $user, $request->validated()))]);
    }

    public function reports(AdminIndexRequest $request)
    {
        $query = UserReport::with(['reporter', 'reviewer']);
        foreach (['status', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('query')) {
            $term = '%'.$request->input('query').'%';
            $query->where(fn ($q) => $q->where('description', 'like', $term)->orWhereHas('reporter', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'reports', fn ($report) => AdminPayload::report($report, true));
    }

    public function report(UserReport $userReport)
    {
        return response()->json(['report' => AdminPayload::report($userReport->load(['reporter', 'reviewer']), true)]);
    }

    public function updateReport(AdminActionRequest $request, UserReport $userReport, ModerationService $service)
    {
        return response()->json(['report' => AdminPayload::report($service->updateReport($request->user(), $userReport, $request->validated()), true)]);
    }

    public function audit(AdminIndexRequest $request)
    {
        $query = AdminAuditLog::with('actor');
        foreach (['action', 'target_type', 'target_id', 'actor_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('query')) {
            $term = '%'.$request->input('query').'%';
            $query->where(fn ($q) => $q->where('action', 'like', $term)->orWhere('reason', 'like', $term)->orWhereHas('actor', fn ($u) => $u->where('name', 'like', $term)));
        }

        return $this->collectionResponse($request, $query->orderByDesc('id'), 'logs', AdminPayload::audit(...));
    }

    public function employerHistory(Request $request)
    {
        $query = $request->user()->employer->verificationHistory()->with('actor')->orderByDesc('id');

        return $this->collectionResponse($request, $query, 'logs', AdminPayload::audit(...));
    }

    public function jobHistory(Request $request, JobListing $jobListing)
    {
        abort_unless($jobListing->employer_id === $request->user()->employer->id, 403);

        return $this->collectionResponse($request, $jobListing->moderationHistory()->with('actor')->orderByDesc('id'), 'logs', AdminPayload::audit(...));
    }
}
