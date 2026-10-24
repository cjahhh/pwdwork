<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['profile' => $this->payload($request->user())]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if ($user->isEmployer()) {
            $data = $request->validate(['companyName' => ['required', 'string', 'max:255'], 'website' => ['nullable', 'url', 'max:255'], 'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000']]);
            DB::transaction(function () use ($user, $data) {
                $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($owner->status === 'active', 403);
                $employer = $owner->employer()->lockForUpdate()->firstOrFail();
                $employer->fill(['company_name' => $data['companyName'], 'website' => array_key_exists('website', $data) ? $data['website'] : $employer->website,
                    'location' => array_key_exists('location', $data) ? $data['location'] : $employer->location, 'description' => array_key_exists('description', $data) ? $data['description'] : $employer->description]);
                if ($employer->isDirty()) {
                    $before = $employer->verification_status;
                    if ($before === 'verified') {
                        $employer->verification_status = 'pending';
                        $employer->verification_reason = null;
                        $employer->verification_requested_at = now();
                    }
                    $employer->review_version++;
                    $employer->save();
                    AdminAuditLog::record($owner, 'employer.details_updated', 'employer', $employer->id, $before, $employer->verification_status, 'Company details corrected; verified companies require another review after changes.');
                }
            }, 3);
        } else {
            $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'headline' => ['nullable', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255'], 'bio' => ['nullable', 'string', 'max:10000'], 'experience' => ['nullable', 'string', 'max:10000'], 'preferredWorkMode' => ['nullable', 'in:Remote,Hybrid,Onsite'], 'skills' => ['array', 'max:50'], 'skills.*' => ['string', 'max:100'], 'accommodations' => ['array', 'max:20'], 'accommodations.*' => ['string', 'max:255']]);
            DB::transaction(function () use ($user, $data) {
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedUser->status === 'active', 403);
                $user->update(['name' => $data['name']]);
                $profile = $user->applicantProfile()->updateOrCreate(['user_id' => $user->id], ['headline' => $data['headline'] ?? null, 'location' => $data['location'] ?? null, 'summary' => $data['bio'] ?? null, 'skills' => $data['skills'] ?? []]);
                if (array_key_exists('preferredWorkMode', $data)) {
                    $profile->update(['preferred_work_mode' => $data['preferredWorkMode']]);
                }
                if (array_key_exists('experience', $data)) {
                    $profile->update(['experience' => $data['experience']]);
                }
                $ids = collect($data['accommodations'] ?? [])->map(fn ($label) => Accommodation::firstOrCreate(['slug' => (string) str($label)->slug()], ['label' => $label])->id);
                $profile->accommodations()->sync($ids);
            });
        }

        return response()->json(['profile' => $this->payload($user->fresh())]);
    }

    private function payload($user): array
    {
        if ($user->isEmployer()) {
            $p = $user->employer;

            return ['companyName' => $p->company_name, 'website' => $p->website ?? '', 'location' => $p->location ?? '', 'description' => $p->description ?? '', 'verificationStatus' => $p->verification_status,
                'verificationReason' => $p->verification_reason, 'reviewVersion' => $p->review_version, 'reviewedAt' => $p->verification_reviewed_at?->toIso8601String()];
        }
        $p = $user->applicantProfile;

        return ['name' => $user->name, 'email' => $user->email, 'headline' => $p?->headline ?? '', 'location' => $p?->location ?? '', 'preferredWorkMode' => $p?->preferred_work_mode ?? '', 'bio' => $p?->summary ?? '', 'experience' => $p?->experience ?? '', 'skills' => $p?->skills ?? [], 'accommodations' => $p?->accommodations->pluck('label')->all() ?? []];
    }
}
