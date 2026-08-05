<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Pulisce le note dei rapportini importati dal gestionale, rimuovendo i tag RTF
        DB::table('service_reports')
            ->where('eureka_service_report_id', '!=', null)
            ->get(['id', 'notes'])
            ->each(function ($report) {
                if ($report->notes && str_starts_with($report->notes, '{\\rtf')) {
                    $cleaned = $this->stripRtf($report->notes);
                    DB::table('service_reports')
                        ->where('id', $report->id)
                        ->update(['notes' => $cleaned]);
                }
            });
    }

    public function down(): void
    {
        // Non è possibile recuperare il formato RTF originale
    }

    private function stripRtf(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!str_starts_with($value, '{\\rtf')) {
            return $value;
        }

        // Rimuove gli ultimi caratteri di chiusura RTF
        $value = (string) preg_replace('/\}\s*$/', '', $value);

        // Sostituisce \par (paragrafi) con newlines
        $value = (string) preg_replace('/\\\\par\b/', "\n", $value);

        // Rimuove i tag RTF (es. \f0, \fs22, \ansi, ecc.)
        $value = (string) preg_replace('/\\\\[a-z]+\d*\s?/', '', $value);

        // Rimuove i gruppi di comandi (es. {\*\generator...})
        $value = (string) preg_replace('/\{[^}]*\}/', '', $value);

        // Rimuove le parentesi graffe rimanenti
        $value = (string) preg_replace('/[{}]/', '', $value);

        return trim($value) ?: null;
    }
};
