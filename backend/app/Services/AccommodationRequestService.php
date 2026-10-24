<?php

namespace App\Services;

use App\Models\AccommodationRequest;
use App\Models\Application;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

class AccommodationRequestService
{
    private function activeActor(User $user): User
    {
        $actor = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->status === 'active' && in_array($actor->role, ['applicant', 'employer'], true), 403);

        return $actor;
    }

    private function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function interview(Application $application): array
    {
        return ['date' => $application->interview_date?->toDateString(), 'time' => $application->interview_time ? substr($application->interview_time, 0, 5) : null];
    }

    public function create(User $user, array $data): AccommodationRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $actor = $this->activeActor($user);
            abort_unless($actor->role === 'applicant', 403);
            $application = Application::where('user_id', $actor->id)->whereKey($data['application_id'])->lockForUpdate()->firstOrFail();
            $hash = $this->fingerprint($data);
            $existing = AccommodationRequest::where('application_id', $application->id)->where('submission_key', $data['action_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_hash, $hash), 409, 'This submission key was already used for different content.');

                return $existing;
            }
            $record = AccommodationRequest::create([
                'application_id' => $application->id, 'submission_key' => $data['action_key'], 'submission_hash' => $hash,
                'categories' => $data['categories'], 'stage' => $data['stage'], 'details' => $data['details'] ?? null,
                'status' => 'submitted', 'version' => 1, 'shared_at' => now(), 'interview_snapshot' => $this->interview($application),
            ]);
            $this->event($record, $actor, 'create', $data['action_key'], $hash, null);
            $this->notify($record, $actor, 'Request submitted');

            return $record;
        }, 3);
    }

    public function act(User $user, int $id, array $data): AccommodationRequest
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $actor = $this->activeActor($user);
            $record = AccommodationRequest::forParticipant($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = $this->fingerprint($data);
            $previous = $record->events()->where('action_key', $data['action_key'])->first();
            if ($previous) {
                abort_unless($previous->actor_id === $actor->id && hash_equals($previous->action_hash, $hash), 409, 'This action key was already used.');

                return $record;
            }
            abort_if($record->status === 'withdrawn', 409, 'This request has been withdrawn and cannot be changed.');
            abort_unless($record->version === (int) $data['expected_version'], 409, 'This request changed. Refresh it before trying again.');
            $action = $data['action'];
            $before = $record->status;
            $isApplicant = $actor->role === 'applicant';
            if (in_array($action, ['withdraw', 'edit', 'accept', 'decline'], true)) {
                abort_unless($isApplicant, 403);
            } elseif ($action !== 'message') {
                abort_if($isApplicant, 403);
            }
            switch ($action) {
                case 'withdraw':
                    $record->status = 'withdrawn';
                    $record->withdrawn_at = now();
                    break;
                case 'edit':
                    $record->fill(['categories' => $data['categories'], 'stage' => $data['stage'], 'details' => $data['details'] ?? null,
                        'status' => 'submitted', 'arrangement' => null, 'arrangement_interview' => null]);
                    break;
                case 'accept':
                case 'decline':
                    abort_unless($before === 'alternative_proposed', 409, 'There is no pending proposal to accept or decline.');
                    $record->status = $action === 'accept' ? 'agreed' : 'under_discussion';
                    if ($action === 'accept') {
                        $record->arrangement_interview = $this->interview($record->application);
                    } else {
                        $record->arrangement = null;
                        $record->arrangement_interview = null;
                    }
                    break;
                case 'acknowledge':
                    abort_unless($before === 'submitted', 409, 'Only newly submitted requests can be acknowledged.');
                    $record->status = 'under_discussion';
                    break;
                case 'propose':
                    $record->status = 'alternative_proposed';
                    $record->arrangement = $data['text'];
                    $record->arrangement_interview = null;
                    break;
                case 'agree':
                    abort_unless(in_array($before, ['submitted', 'under_discussion'], true), 409, 'Only the applicant can accept a pending proposal. Edit or discuss other changes first.');
                    $record->status = 'agreed';
                    $record->arrangement = $data['text'];
                    $record->arrangement_interview = $this->interview($record->application);
                    break;
                case 'unable':
                    abort_unless(in_array($before, ['submitted', 'under_discussion', 'alternative_proposed'], true), 409, 'This request cannot be marked unable to provide from its current status.');
                    $record->status = 'unable_to_provide';
                    $record->arrangement = null;
                    $record->arrangement_interview = null;
                    break;
                case 'message':
                    $record->messages()->create(['sender_id' => $actor->id, 'sender_role' => $actor->role, 'body' => $data['text']]);
                    break;
            }
            $record->version++;
            $record->save();
            $this->event($record, $actor, $action, $data['action_key'], $hash, $before, $action === 'message' ? null : ($data['text'] ?? null));
            $this->notify($record, $actor, match ($action) {
                'acknowledge' => 'Request acknowledged', 'propose' => 'Arrangement proposed: response needed',
                'agree', 'accept' => 'Arrangement confirmed', 'decline' => 'Proposal declined', 'unable' => 'Request response available',
                'withdraw' => 'Request withdrawn', 'edit' => 'Request updated', default => 'New request message',
            });

            return $record;
        }, 3);
    }

    private function event(AccommodationRequest $record, User $actor, string $action, string $key, string $hash, ?string $before, ?string $explanation = null): void
    {
        $record->events()->create([
            'actor_id' => $actor->id, 'actor_role' => $actor->role, 'action' => $action, 'action_key' => $key, 'action_hash' => $hash,
            'before_status' => $before, 'after_status' => $record->status, 'version' => $record->version, 'explanation' => $explanation,
            'snapshot' => $record->only(['categories', 'stage', 'details', 'arrangement', 'interview_snapshot', 'arrangement_interview']),
        ]);
    }

    private function notify(AccommodationRequest $record, User $actor, string $title): void
    {
        $application = $record->application;
        $employer = $application->jobListing->employer;
        $role = $actor->role === 'applicant' ? 'employer' : 'applicant';
        UserNotification::create([
            'user_id' => $role === 'employer' ? $employer->user_id : $application->user_id,
            'title' => $title, 'body' => 'A private accommodation request has an update. Sign in to review authorized information.',
            'action_url' => '/'.$role.'/accommodation-requests'.($record->status === 'withdrawn' ? '' : '/'.$record->id),
        ]);
    }

    public function payload(AccommodationRequest $record): array
    {
        $application = $record->application;
        $job = $application->jobListing;
        $current = $this->interview($application);

        return [
            'id' => $record->id, 'applicationId' => $application->id, 'applicant' => $application->user->name,
            'jobTitle' => $job->title, 'company' => $job->employer->company_name, 'employerId' => $job->employer_id,
            'categories' => $record->categories, 'stage' => $record->stage, 'details' => $record->details,
            'status' => $record->status, 'arrangement' => $record->arrangement, 'version' => $record->version,
            'interview' => [...$current, 'notes' => $application->interview_notes, 'timezone' => 'Asia/Manila'],
            'originalInterview' => $record->interview_snapshot, 'arrangementInterview' => $record->arrangement_interview,
            'scheduleChanged' => $record->stage === 'interview' && $current !== ($record->arrangement_interview ?? $record->interview_snapshot),
            'sharedAt' => $record->shared_at->toIso8601String(), 'withdrawnAt' => $record->withdrawn_at?->toIso8601String(),
            'createdAt' => $record->created_at->toIso8601String(), 'updatedAt' => $record->updated_at->toIso8601String(),
        ];
    }
}
