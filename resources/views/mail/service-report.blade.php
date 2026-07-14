<x-mail::message>
# Rapportino di intervento {{ $report->number }}

Gentile {{ $report->customer->company_name ?: $report->customer->full_name }},

in allegato il rapportino relativo all'intervento del {{ $report->intervention_date->format('d/m/Y') }}.

**Lavoro svolto:** {{ $report->work_performed }}

Grazie,<br>
{{ $report->tenant->name }}
</x-mail::message>
