@props(['overdue' => false, 'status', 'date', 'title'])
{{-- Una scheda per riga al posto di una tabella a piu' colonne: sul telefono le colonne si schiacciavano fino a spezzare nomi e date. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 10px; border: 1px solid #e2e8f0; border-left: 4px solid {{ $overdue ? '#dc2626' : '#d97706' }}; border-radius: 6px;">
<tr>
<td style="padding: 12px 14px;">
<p style="margin: 0 0 6px; font-size: 12px; font-weight: bold; color: {{ $overdue ? '#991b1b' : '#92400e' }}; text-transform: uppercase; letter-spacing: 0.04em;">{{ $status }} &middot; {{ $date }}</p>
<p style="margin: 0 0 2px; font-size: 16px; font-weight: bold; color: #18181b;">{{ $title }}</p>
{{ $slot }}
</td>
</tr>
</table>
