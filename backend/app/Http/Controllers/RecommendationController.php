<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Services\JobMatchService;
use Illuminate\Http\Request;

class RecommendationController extends JobListingController
{
    use PaginatesCollections;

    public function recommendations(Request $request, JobMatchService $matches)
    {
        $profile = $request->user()->applicantProfile()->with('accommodations')->first();
        $jobs = $matches->ranked($profile)->withExists([
            'savedByUsers as saved' => fn ($users) => $users->where('users.id', $request->user()->id),
        ]);

        return $this->collectionResponse($request, $jobs, 'jobs', fn ($job) => [
            ...$this->jobPayload($job), 'match' => $matches->explain($job, $profile), 'saved' => (bool) $job->saved,
        ])->header('Cache-Control', 'private, no-store');
    }
}
