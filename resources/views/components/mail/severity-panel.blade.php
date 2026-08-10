@props(['color' => 'gray'])

@php
    $palette = [
        'green' => ['border' => '#16a34a', 'bg' => '#f0fdf4', 'text' => '#166534'],
        'amber' => ['border' => '#d97706', 'bg' => '#fffbeb', 'text' => '#92400e'],
        'blue' => ['border' => '#316eb4', 'bg' => '#eff6ff', 'text' => '#1e40af'],
        'red' => ['border' => '#dc2626', 'bg' => '#fef2f2', 'text' => '#991b1b'],
        'gray' => ['border' => '#71717a', 'bg' => '#fafafa', 'text' => '#3f3f46'],
    ];
    $c = $palette[$color] ?? $palette['gray'];
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 10px 0 20px; border-left: 4px solid {{ $c['border'] }};">
<tr>
<td style="background-color: {{ $c['bg'] }}; padding: 14px 16px; color: {{ $c['text'] }};">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
