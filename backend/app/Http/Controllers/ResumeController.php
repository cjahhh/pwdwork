<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesCollections;
use App\Http\Requests\SaveResumeRequest;
use App\Http\Requests\ShareResumeRequest;
use App\Models\Application;
use App\Models\Resume;
use App\Models\ResumeApplicationShare;
use App\Models\User;
use App\Services\ResumeRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResumeController extends Controller
{
    use PaginatesCollections;

    private function activeOwner(Request $request): void
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->status === 'active' && $user->isApplicant(), 403);
    }

    private function owned(Request $request, int $id): Resume
    {
        return Resume::where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        return $this->collectionResponse($request, Resume::where('user_id', $request->user()->id)->latest('updated_at')->orderByDesc('id'), 'resumes', fn ($resume) => $resume->only(['id', 'title', 'template', 'version', 'updated_at']));
    }

    public function store(SaveResumeRequest $request)
    {
        $resume = DB::transaction(function () use ($request) {
            $this->activeOwner($request);

            return Resume::create([...$request->safe()->except('version'), 'user_id' => $request->user()->id]);
        });

        return response()->json(['resume' => $resume], 201);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['resume' => $this->owned($request, $id)]);
    }

    public function update(SaveResumeRequest $request, int $id)
    {
        $resume = DB::transaction(function () use ($request, $id) {
            $this->activeOwner($request);
            $resume = Resume::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($resume->version === $request->integer('version'), 409, 'This resume changed in another window. Reload before saving.');
            $resume->update([...$request->safe()->except('version'), 'version' => $resume->version + 1]);

            return $resume;
        });

        return response()->json(['resume' => $resume]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $this->activeOwner($request);
            $this->owned($request, $id)->delete();
        });

        return response()->json(['message' => 'Personal resume deleted. Previously shared snapshots remain available until revoked.']);
    }

    public function duplicate(Request $request, int $id)
    {
        $copy = DB::transaction(function () use ($request, $id) {
            $this->activeOwner($request);
            $source = $this->owned($request, $id);

            return Resume::create(['user_id' => $request->user()->id, 'title' => mb_substr($source->title, 0, 113).' (copy)', 'template' => $source->template, 'content' => $source->content]);
        });

        return response()->json(['resume' => $copy], 201);
    }

    public function importProfile(Request $request)
    {
        $request->validate(['consent' => ['required', 'accepted']]);
        $user = $request->user();
        $profile = $user->applicantProfile;

        return response()->json(['content' => [
            'personal' => ['name' => $user->name, 'email' => $user->email, 'location' => $profile?->location ?? ''],
            'summary' => $profile?->summary ?? '',
            'skills' => array_map(fn ($skill) => ['heading' => $skill], $profile?->skills ?? []),
            // The existing profile stores work and volunteer experience in one free-text field.
            'work' => $profile?->experience ? [['heading' => 'Experience', 'details' => $profile->experience]] : [],
        ]]);
    }

    public function preview(Request $request, int $id, ResumeRenderer $renderer)
    {
        return response($renderer->html($this->owned($request, $id)))->header('Content-Type', 'text/html; charset=UTF-8')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }

    public function pdf(Request $request, int $id, ResumeRenderer $renderer)
    {
        return $renderer->pdf($this->owned($request, $id));
    }

    private function application(Request $request, int $id): Application
    {
        $query = Application::query();
        if ($request->user()->isApplicant()) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->whereHas('jobListing.employer', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        return $query->findOrFail($id);
    }

    public function share(ShareResumeRequest $request, int $id)
    {
        $share = DB::transaction(function () use ($request, $id) {
            $this->activeOwner($request);
            $application = $this->application($request, $id);
            Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $resume = Resume::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($request->integer('resume_id'));

            return ResumeApplicationShare::updateOrCreate(['application_id' => $application->id], [
                'resume_id' => $resume->id, 'title' => $resume->title, 'template' => $resume->template,
                'version' => $resume->version, 'content' => $resume->content, 'shared_at' => now(), 'revoked_at' => null,
            ]);
        });

        return response()->json(['share' => $share->only(['title', 'version', 'shared_at', 'revoked_at'])]);
    }

    public function revoke(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $this->activeOwner($request);
            $application = $this->application($request, $id);
            Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            ResumeApplicationShare::where('application_id', $application->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        return response()->json(['message' => 'Future access revoked. Downloaded copies cannot be revoked.']);
    }

    public function shared(Request $request, int $id, ResumeRenderer $renderer, string $format = 'metadata')
    {
        $application = $this->application($request, $id);
        $share = ResumeApplicationShare::where('application_id', $application->id)->first();
        if ($format === 'metadata') {
            return response()->json(['share' => $share && ! $share->revoked_at ? $share->only(['title', 'version', 'shared_at']) : null]);
        }
        abort_unless($share && ! $share->revoked_at, 404, 'No shared resume is available.');
        if ($format === 'pdf') {
            return $renderer->pdf($share);
        }

        return response($renderer->html($share))->header('Content-Type', 'text/html; charset=UTF-8')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }
}
