@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('img/logo.png') }}" class="brand-logo" alt="{{ config('app.name') }}">
</a>
<br>
<img src="{{ asset('img/franke_partner_logo.png') }}" class="franke-badge" alt="Franke Approved Partner">
</td>
</tr>
