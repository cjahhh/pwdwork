<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

class ResumeRenderer
{
    public function html($resume): string
    {
        return view('resumes.document', ['resume' => $resume])->render();
    }

    public function pdf($resume)
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('allowedProtocols', []);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', storage_path('framework/cache'));
        $options->set('fontCache', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($this->html($resume), 'UTF-8');
        $pdf->render();
        $filename = (Str::slug($resume->title) ?: 'resume').'-v'.$resume->version.'.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }
}
