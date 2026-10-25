<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $resume->title }}</title>
<style>
@page { size: A4; margin: 18mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; line-height: 1.5; color: #172b36; margin: 0; overflow-wrap: anywhere; word-wrap: break-word; }
h1 { font-size: 24pt; line-height: 1.2; margin: 0 0 8pt; }
h2 { font-size: 14pt; margin: 18pt 0 6pt; border-bottom: 1pt solid #52616b; page-break-after: avoid; }
h3 { font-size: 11pt; margin: 8pt 0 2pt; page-break-after: avoid; }
p { margin: 0 0 6pt; white-space: pre-wrap; }
.modern h1, .modern h2 { color: #164e63; }
.modern h2 { border-bottom: 2pt solid #164e63; }
.classic { font-family: 'DejaVu Serif', serif; }
.contact { font-size: 10pt; }
@media screen { body { max-width: 174mm; padding: 1rem; margin: auto; } }
</style></head>
<body class="{{ $resume->template }}">
@php($content = $resume->content)
<header><h1>{{ $content['personal']['name'] }}</h1>
<p class="contact">{{ implode(' | ', array_filter(\Illuminate\Support\Arr::only($content['personal'], ['email', 'phone', 'location', 'website']))) }}</p></header>
@if(!empty($content['summary']))<section><h2>Professional summary</h2><p>{{ $content['summary'] }}</p></section>@endif
@foreach(\App\Http\Requests\SaveResumeRequest::SECTIONS as $key => $label)
@if(!empty($content[$key]))<section><h2>{{ $label }}</h2>
@foreach($content[$key] as $entry)
@if(!empty($entry['heading']))<h3>{{ $entry['heading'] }}</h3>@endif
@if(!empty($entry['organization']) || !empty($entry['dates']))<p>{{ implode(' | ', array_filter([$entry['organization'] ?? '', $entry['dates'] ?? ''])) }}</p>@endif
@if(!empty($entry['details']))<p>{{ $entry['details'] }}</p>@endif
@endforeach</section>@endif
@endforeach
</body></html>
