<?php

use App\Models\Resume;
use App\Services\ResumeRenderer;
use Illuminate\Contracts\Console\Kernel;

// Pure rendering fixture: never connects to or migrates a database.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$renderer = $app->make(ResumeRenderer::class);
foreach (['classic', 'modern'] as $template) {
    $resume = new Resume(['title' => 'PDF verification', 'version' => 1, 'template' => $template, 'content' => [
        'personal' => ['name' => 'Alex Example', 'email' => 'alex@example.com', 'location' => 'Manila'],
        'summary' => 'SEARCHABLE RESUME — Customer service and community support.',
        'education' => [['heading' => 'Diploma', 'organization' => 'Example College', 'dates' => '2024–2026']],
        'work' => array_map(fn ($n) => ['heading' => 'Support role '.$n, 'organization' => 'Example team', 'dates' => '2020–2026', 'details' => str_repeat('Accessible customer support, training and documentation. ', 20).' END ENTRY '.$n], range(1, 12)),
        'skills' => [['heading' => 'Communication'], ['heading' => str_repeat('LongSkill', 40)]],
        'languages' => [['heading' => 'English', 'organization' => 'Fluent']],
    ]]);
    file_put_contents(storage_path('framework/cache/resume-'.$template.'.pdf'), $renderer->pdf($resume)->getContent());
}
echo "Generated two synthetic PDF fixtures in storage/framework/cache. No database used.\n";
