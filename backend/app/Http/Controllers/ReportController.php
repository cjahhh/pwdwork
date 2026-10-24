<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Http\Requests\SubmitReportRequest;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserReport;
use App\Support\AdminPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use PaginatesCollections;

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(UserReport::STATUSES)]]);
        $query = $request->user()->reports();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $this->collectionResponse($request, $query->latest()->orderByDesc('id'), 'reports', fn ($report) => AdminPayload::report($report));
    }

    public function store(SubmitReportRequest $request)
    {
        $data = $request->validated();
        $this->validateTarget($request->user(), $data['target_type'], $data['target_id'] ?? null);
        $type = $data['target_type'];
        $allowed = match ($type) {
            'job' => ['suspicious_job', 'misleading_description', 'inaccurate_accessibility', 'discrimination'],
            'employer' => ['suspicious_employer', 'inaccurate_accessibility', 'discrimination'],
            'account' => ['account_problem', 'suspicious_employer', 'discrimination'],
            'platform' => ['platform_problem', 'account_problem'],
        };
        abort_unless(in_array($data['category'], $allowed, true), 422, 'Choose a category appropriate to the reported target.');
        $fingerprint = hash('sha256', implode('|', [$request->user()->id, $type, $data['target_id'] ?? '', $data['category']]));
        try {
            $report = DB::transaction(function () use ($request, $data, $fingerprint) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->status === 'active', 403);
                abort_if(UserReport::where('open_fingerprint', $fingerprint)->exists(), 409, 'You already have an open report for this target and category. Follow its status in Your reports.');

                return UserReport::create([...$data, 'reporter_id' => $user->id, 'status' => 'pending', 'open_fingerprint' => $fingerprint]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'You already have an open report for this target and category.');
        }

        return response()->json(['message' => 'Report submitted. Only you and authorized administrators can view it.', 'report' => AdminPayload::report($report)], 201);
    }

    private function validateTarget(User $reporter, string $type, ?int $id): void
    {
        if ($type === 'platform') {
            return;
        }
        if ($type === 'job') {
            $visible = JobListing::publiclyVisible()->whereKey($id)->exists();
            $related = JobListing::whereKey($id)->where(fn ($q) => $q->whereHas('applications', fn ($a) => $a->where('user_id', $reporter->id))
                ->orWhereHas('employer', fn ($e) => $e->where('user_id', $reporter->id)))->exists();
        } elseif ($type === 'employer') {
            $visible = Employer::whereKey($id)->where('verification_status', 'verified')->whereHas('user', fn ($u) => $u->where('status', 'active')->where('role', 'employer'))->exists();
            $related = Employer::whereKey($id)->whereHas('jobListings.applications', fn ($a) => $a->where('user_id', $reporter->id))->exists();
        } else {
            $visible = User::whereKey($id)->where('role', 'employer')->where('status', 'active')->whereHas('employer', fn ($e) => $e->where('verification_status', 'verified'))->exists();
            $related = Application::where(fn ($q) => $q->where('user_id', $reporter->id)->whereHas('jobListing.employer', fn ($e) => $e->where('user_id', $id)))
                ->orWhere(fn ($q) => $q->where('user_id', $id)->whereHas('jobListing.employer', fn ($e) => $e->where('user_id', $reporter->id)))->exists();
        }
        abort_unless($visible || $related, 404, 'That target is unavailable or is not linked to an interaction with your account.');
    }
}
