<?php

namespace App\Support\Gestionale;

use App\Models\ServiceReport;
use Illuminate\Support\Carbon;

/**
 * Cosa mostra il modale "Fattura" di un rapportino: le fatture Eureka su cui
 * e' finita la sua scheda lavoro, ognuna col link al PDF.
 *
 * Si chiede a Eureka quando il modale si apre, non quando si carica la
 * pagina: la scheda del rapportino non deve aspettare il gestionale, ne'
 * fallire se il gestionale e' giu'. Niente cache: "non ancora fatturata"
 * cambia il giorno in cui la fattura viene emessa.
 */
final class FattureRapportino
{
    /**
     * @return array{esito: 'ok'|'vuoto'|'errore', fatture: array<int, array{etichetta: string, fe: bool, url: string}>}
     */
    public static function per(ServiceReport $rapportino): array
    {
        $idScheda = $rapportino->idSchedaEureka();

        if ($idScheda === null) {
            return ['esito' => 'vuoto', 'fatture' => []];
        }

        try {
            $fatture = (new EurekaClient($rapportino->tenant))->fattureDellaScheda($idScheda);
        } catch (GestionaleEurekaException) {
            $fatture = null;
        }

        if ($fatture === null) {
            return ['esito' => 'errore', 'fatture' => []];
        }

        // Quello che si e' appena saputo va anche nell'elenco, senza
        // aspettare il giro di stanotte (eureka:allinea-fatture-rapportini).
        $rapportino->registraFattureEureka($fatture);

        if ($fatture === []) {
            SenzaFatturaCollegata::aggiorna($rapportino);

            return [
                'esito' => 'vuoto',
                'fatture' => [],
                'motivo' => SenzaFatturaCollegata::descrizione($rapportino->eureka_fattura_motivo, $rapportino->eureka_fattura_indizio),
            ];
        }

        return [
            'esito' => 'ok',
            'fatture' => array_map(fn (array $f) => [
                'etichetta' => self::etichetta($f),
                'fe' => (bool) ($f['has_fe'] ?? false),
                'url' => route('service-reports.fattura-eureka', [$rapportino, (int) $f['id_fattura']]),
            ], $fatture),
        ];
    }

    /** "FT 267 del 30/06/2026" */
    public static function etichetta(array $f): string
    {
        $data = filled($f['data_fattura'] ?? null)
            ? ' del '.Carbon::parse($f['data_fattura'])->format('d/m/Y')
            : '';

        return trim(($f['tipo_doc'] ?? 'FT').' '.($f['numero_fattura'] ?? '')).$data;
    }
}
