<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rapportino {{ $report->number }}</title>
    <style>
        @include('pdf.partials.service-report-styles')
    </style>
</head>
<body>
    @include('pdf.partials.service-report-body')
    @include('pdf.partials.page-numbers')
</body>
</html>
