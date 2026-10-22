<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
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
            $user->employer->update(['company_name' => $data['companyName'], 'website' => $data['website'] ?? null, 'location' => $data['location'] ?? null, 'description' => $data['description'] ?? null]);
        } else {
            $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'headline' => ['nullable', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255'], 'bio' => ['nullable', 'string', 'max:10000'], 'skills' => ['array', 'max:50'], 'skills.*' => ['string', 'max:100'], 'accommodations' => ['array', 'max:20'], 'accommodations.*' => ['string', 'max:255']]);
            DB::transaction(function () use ($user, $data) {
                $user->update(['name' => $data['name']]);
                $profile = $user->applicantProfile()->updateOrCreate(['user_id' => $user->id], ['headline' => $data['headline'] ?? null, 'location' => $data['location'] ?? null, 'summary' => $data['bio'] ?? null, 'skills' => $data['skills'] ?? []]);
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

            return ['companyName' => $p->company_name, 'website' => $p->website ?? '', 'location' => $p->location ?? '', 'description' => $p->description ?? '', 'verificationStatus' => $p->verification_status];
        }
        $p = $user->applicantProfile;

        return ['name' => $user->name, 'email' => $user->email, 'headline' => $p?->headline ?? '', 'location' => $p?->location ?? '', 'bio' => $p?->summary ?? '', 'skills' => $p?->skills ?? [], 'accommodations' => $p?->accommodations->pluck('label')->all() ?? []];
    }
}
