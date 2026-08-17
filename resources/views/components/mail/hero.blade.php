@props(['kicker' => null, 'title', 'subtitle' => null, 'tone' => 'dark'])
@php($bg = $tone === 'red' ? '#7f1d1d' : '#0f172a')
<div style="background:{{ $bg }};border-radius:10px;padding:16px 18px;color:#ffffff;margin-bottom:16px;">
	@if($kicker)
	<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.75;margin-bottom:8px;">{{ $kicker }}</div>
	@endif
	<div style="font-size:18px;line-height:1.35;font-weight:700;">{{ $title }}</div>
	@if($subtitle)
	<div style="font-size:13px;opacity:.8;margin-top:10px;">{{ $subtitle }}</div>
	@endif
</div>
