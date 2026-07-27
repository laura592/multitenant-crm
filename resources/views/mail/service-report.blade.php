<x-mail::message>
# Rapportino di intervento {{ $report->number }}

@php($recipient = $report->invoiceRecipient())
Gentile {{ $recipient->company_name ?: $recipient->full_name }},

in allegato il rapportino relativo all'intervento del {{ $report->intervention_date->format('d/m/Y') }}@if($recipient->isNot($report->customer)) presso {{ $report->customer->company_name ?: $report->customer->full_name }}@endif.

**Lavoro svolto:** {{ $report->work_performed }}

Grazie,<br>
{{ $report->tenant->name }}
</x-mail::message>
