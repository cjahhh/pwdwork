<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Services\ResumeRenderer;
use Tests\TestCase;

class PhaseSixCTest extends TestCase
{
    public function test_both_templates_omit_completely_blank_entries_and_keep_intentional_text(): void
    {
        foreach (['classic', 'modern'] as $template) {
            $content = [
                'personal' => ['name' => 'Jordan Example', 'phone' => '0'],
                'summary' => " \n\t ",
                'education' => [['heading' => '', 'organization' => null, 'dates' => '', 'details' => " \n "]],
                'work' => [['heading' => '  ']], 'volunteer' => [],
                'skills' => [['heading' => 'NA'], ['heading' => '0'], ['details' => 'Explicit content']],
                'certifications' => [[]], 'projects' => [], 'languages' => [[]], 'references' => [['details' => '  ']],
            ];
            $resume = new Resume(['title' => 'Intentional content', 'template' => $template, 'content' => $content]);
            $html = app(ResumeRenderer::class)->html($resume);
            foreach (['Professional summary', 'Education', 'Work experience', 'Volunteer experience', 'Certifications and training', 'Projects', 'Languages', 'References'] as $heading) {
                $this->assertStringNotContainsString('<h2>'.$heading.'</h2>', $html);
            }
            $this->assertStringContainsString('<h2>Skills</h2>', $html);
            $this->assertStringContainsString('<h3>NA</h3>', $html);
            $this->assertStringContainsString('<h3>0</h3>', $html);
            $this->assertStringContainsString('Explicit content', $html);
            $this->assertSame($content, $resume->content, 'Rendering must not change stored resume/snapshot content.');
            $pdf = app(ResumeRenderer::class)->pdf($resume);
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
    }
}
