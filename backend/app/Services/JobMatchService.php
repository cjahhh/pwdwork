<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\JobListing;

class JobMatchService
{
    /** SQL expressions keep ranking and pagination in the database. No profile text is returned. */
    public function ranked(?ApplicantProfile $profile)
    {
        $parts = ['0'];
        $bindings = [];
        $weight = 0;
        $skills = $this->skills($profile);
        if ($skills) {
            $terms = [];
            foreach ($skills as $skill) {
                $terms[] = '(CASE WHEN INSTR(LOWER(title), ?) > 0 OR INSTR(LOWER(description), ?) > 0 THEN 1 ELSE 0 END)';
                array_push($bindings, $skill, $skill);
            }
            $parts[] = '(40.0 * ('.implode(' + ', $terms).') / '.count($skills).')';
            $weight += 40;
        }
        foreach ([['preferred_work_mode', 'work_mode', 25], ['location', 'location', 15]] as [$field, $column, $value]) {
            $preference = strtolower(trim($profile?->$field ?? ''));
            if ($preference !== '') {
                // Missing employer information is neutral (half credit), never evidence of incompatibility.
                $parts[] = "(CASE WHEN $column IS NULL OR TRIM($column) = '' THEN ".($value / 2)." WHEN LOWER(TRIM($column)) = ? THEN $value ELSE 0 END)";
                $bindings[] = $preference;
                $weight += $value;
            }
        }

        return JobListing::publiclyVisible()->with(['employer.user', 'accommodations'])
            ->select('job_listings.*')->selectRaw($weight ? 'ROUND(100.0 * ('.implode(' + ', $parts).') / '.$weight.') AS match_score' : 'NULL AS match_score', $bindings)
            ->orderByDesc('match_score')->orderByDesc('job_listings.id');
    }

    public function skills(?ApplicantProfile $profile): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim($s)), $profile?->skills ?? []))));
    }

    public function explain(JobListing $job, ?ApplicantProfile $profile): array
    {
        $skills = $this->skills($profile);
        $matching = array_values(array_filter($skills, fn ($s) => str_contains(strtolower($job->title), $s) || str_contains(strtolower($job->description), $s)));
        $reasons = [];
        if ($matching) {
            $reasons[] = 'Your listed skills appear in the job title or description: '.implode(', ', $matching).'.';
        }
        foreach ([['preferred_work_mode', 'work_mode', 'work arrangement'], ['location', 'location', 'location']] as [$field, $column, $label]) {
            if (trim($profile?->$field ?? '') !== '') {
                $reasons[] = trim($job->$column ?? '') === '' ? "The employer has not reported a $label; this is unknown (half credit)." : (strcasecmp(trim($profile->$field), trim($job->$column)) === 0 ? "The listed $label matches your preference." : "The listed $label differs from your preference; you can still apply.");
            }
        }
        $requested = $profile?->accommodations->pluck('id') ?? collect();
        $reported = $job->accommodations->whereIn('id', $requested)->pluck('label')->values()->all();
        if ($reported) {
            $reasons[] = 'Employer-reported support matching your optional preferences: '.implode(', ', $reported).'. This support is not independently verified.';
        }
        if ($requested->count() > count($reported)) {
            $reasons[] = 'The employer has not provided enough accessibility information to assess all your preferences. Unreported support is unknown, not incompatible.';
        }
        $coverage = ($skills ? 1 : 0) + (trim($profile?->preferred_work_mode ?? '') !== '' ? 1 : 0) + (trim($profile?->location ?? '') !== '' ? 1 : 0);

        return ['score' => $job->match_score === null ? null : (int) $job->match_score,
            'confidence' => $coverage === 0 ? 'Add skills or work/location preferences to calculate a score. You can browse and apply now.' : "$coverage of 3 professional preference categories available; this is a partial text-based comparison.",
            'matchingSkills' => $matching, 'reportedAccommodationMatches' => $reported, 'reasons' => $reasons,
            'formula' => 'Skills: 40 × fraction of listed skills found in title/description; work arrangement: 25; exact location: 15. Score = 100 × earned points / available profile weights. Unknown employer work/location: half credit. Optional accommodations do not change the score.'];
    }
}
