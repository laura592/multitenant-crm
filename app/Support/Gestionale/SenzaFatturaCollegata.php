<?php

namespace App\Support\Gestionale;

use App\Models\EurekaFattura;
use App\Models\ServiceReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perche' un rapportino su Eureka non ha una fattura collegata.
 *
 * /show/q/sl_fattura segue il collegamento scheda -> fattura su Eureka, ma
 * una scheda si puo' fatturare anche senza quel collegamento, e "nessuna
 * fattura collegata" non vuol dire "da fatturare". Sui 150 casi guardati a
 * mano il 21/09/2026 le spiegazioni erano sempre le stesse, nell'ordine in
 * cui si provano qui:
 *
 * 1. recente: il mese in corso o quello prima. Le fatture riepilogative
 *    escono a fine mese, e quella di agosto a settembre puo' non esserci.
 * 2. senza_importo: righe a zero, non c'e' niente da fatturare.
 * 3. doppione: lo stesso cliente ha, entro 20 giorni, una scheda fatturata
 *    con lo stesso importo al centesimo (Luminance SL-902/904, gemelle di
 *    SL-907/908 fatturate con la FT 479).
 * 4. fattura_non_collegata: una fattura allo stesso cliente o a chi paga per
 *    lui, da 10 giorni prima a 75 dopo. Fatturata a mano, senza partire dalla
 *    scheda. E' un indizio, non una prova: per questo si scrive "probabile".
 * 5. da_verificare: nessuna delle quattro. Questi vanno guardati.
 */
final class SenzaFatturaCollegata
{
    public const RECENTE = 'recente';

    public const SENZA_IMPORTO = 'senza_importo';

    public const DOPPIONE = 'doppione';

    public const FATTURA_NON_COLLEGATA = 'fattura_non_collegata';

    public const DA_VERIFICARE = 'da_verificare';

    /** Quanto lontano si cerca la gemella fatturata di un doppione. */
    private const GIORNI_DOPPIONE = 20;

    private const FATTURA_GIORNI_PRIMA = 10;

    private const FATTURA_GIORNI_DOPO = 75;

    /** @return array<string, string> */
    public static function etichette(): array
    {
        return [
            self::RECENTE => 'In attesa della fattura del mese',
            self::SENZA_IMPORTO => 'Senza importo',
            self::DOPPIONE => 'Doppione di una scheda fatturata',
            self::FATTURA_NON_COLLEGATA => 'Fatturato senza collegare la scheda',
            self::DA_VERIFICARE => 'Da verificare',
        ];
    }

    /** Il testo per la colonna: il motivo, con la prova quando c'e'. */
    public static function descrizione(?string $motivo, ?string $indizio): ?string
    {
        return match ($motivo) {
            self::RECENTE => 'in attesa della fattura del mese',
            self::SENZA_IMPORTO => 'senza importo',
            self::DOPPIONE => 'doppione di '.($indizio ?? 'una scheda fatturata'),
            self::FATTURA_NON_COLLEGATA => 'probabile '.($indizio ?? 'fattura non collegata'),
            self::DA_VERIFICARE => 'da verificare',
            default => null,
        };
    }

    /**
     * Classifica e salva. Solo sui rapportini controllati e senza fattura:
     * su uno fatturato motivo e indizio si azzerano.
     */
    public static function aggiorna(ServiceReport $rapportino, ?CarbonInterface $oggi = null): void
    {
        [$motivo, $indizio] = $rapportino->eureka_fatturato_il !== null || $rapportino->eureka_fatture_controllate_il === null
            ? [null, null]
            : self::classifica($rapportino, $oggi);

        // Come registraFattureEureka(): una lettura, non una modifica del
        // rapportino — niente registro modifiche, niente "ultima modifica".
        ServiceReport::withTrashed()->whereKey($rapportino->getKey())->toBase()->update([
            'eureka_fattura_motivo' => $motivo,
            'eureka_fattura_indizio' => $indizio,
        ]);

        $rapportino->forceFill(['eureka_fattura_motivo' => $motivo, 'eureka_fattura_indizio' => $indizio])
            ->syncOriginalAttributes(['eureka_fattura_motivo', 'eureka_fattura_indizio']);
    }

    /** @return array{0: string, 1: ?string} [motivo, indizio] */
    public static function classifica(ServiceReport $rapportino, ?CarbonInterface $oggi = null): array
    {
        $oggi ??= now();
        $data = self::data($rapportino);

        if ($data !== null && $data->greaterThanOrEqualTo($oggi->copy()->startOfMonth()->subMonthNoOverflow())) {
            return [self::RECENTE, null];
        }

        $importo = self::importo($rapportino->getKey());

        if ($importo < 0.01) {
            return [self::SENZA_IMPORTO, null];
        }

        if ($data !== null && ($gemella = self::gemellaFatturata($rapportino, $data, $importo))) {
            return [self::DOPPIONE, $gemella];
        }

        if ($data !== null && ($fattura = self::fatturaAlCliente($rapportino, $data))) {
            return [self::FATTURA_NON_COLLEGATA, $fattura];
        }

        return [self::DA_VERIFICARE, null];
    }

    /** La data del documento Eureka, o in mancanza quella dell'intervento. */
    private static function data(ServiceReport $r): ?Carbon
    {
        $d = $r->gestionale_document_date ?? $r->intervention_date;

        return $d ? Carbon::parse($d)->startOfDay() : null;
    }

    /** Il totale delle righe del rapportino, al prezzo fissato sulla riga. */
    private static function importo(string $idRapportino): float
    {
        return round((float) DB::table('service_report_materials')
            ->where('service_report_id', $idRapportino)
            ->whereNull('deleted_at')
            ->sum('line_total_snapshot'), 2);
    }

    /** Il numero CRM della scheda fatturata di cui questa e' il doppione. */
    private static function gemellaFatturata(ServiceReport $r, Carbon $data, float $importo): ?string
    {
        $candidate = ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $r->tenant_id)
            ->where('customer_id', $r->customer_id)
            ->whereKeyNot($r->getKey())
            ->whereNotNull('eureka_fatturato_il')
            ->whereRaw('DATE(COALESCE(gestionale_document_date, intervention_date)) BETWEEN ? AND ?', [
                $data->copy()->subDays(self::GIORNI_DOPPIONE)->toDateString(),
                $data->copy()->addDays(self::GIORNI_DOPPIONE)->toDateString(),
            ])
            ->get(['id', 'number', 'gestionale_document_date', 'intervention_date']);

        return $candidate
            ->filter(fn (ServiceReport $g) => abs(self::importo($g->id) - $importo) < 0.01)
            ->sortBy(fn (ServiceReport $g) => abs(self::data($g)?->diffInDays($data) ?? PHP_INT_MAX))
            ->first()?->number;
    }

    /**
     * La fattura piu' vicina, allo stesso cliente o a chi paga per lui:
     * "FT 479 del 07/11/2025". Chi paga puo' stare sull'anagrafica
     * (billing_customer_id) o sulla scheda Eureka (eureka_destinazione_code).
     */
    private static function fatturaAlCliente(ServiceReport $r, Carbon $data): ?string
    {
        $cliente = $r->customer()->withoutGlobalScopes()->first(['id', 'billing_customer_id', 'gestionale_code']);

        $clienti = array_values(array_filter([$r->customer_id, $cliente?->billing_customer_id]));
        $codici = array_values(array_filter([$cliente?->gestionale_code, $r->eureka_destinazione_code]));

        if ($clienti === [] && $codici === []) {
            return null;
        }

        $fattura = EurekaFattura::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $r->tenant_id)
            ->where('tipo', 'cliente')
            ->where(fn ($q) => $q->whereIn('customer_id', $clienti ?: ['-'])->orWhereIn('gestionale_code', $codici ?: ['-']))
            ->whereBetween('data_doc', [
                $data->copy()->subDays(self::FATTURA_GIORNI_PRIMA)->toDateString(),
                $data->copy()->addDays(self::FATTURA_GIORNI_DOPO)->toDateString(),
            ])
            ->get(['numero_doc', 'data_doc'])
            ->sortBy(fn ($f) => abs(Carbon::parse($f->data_doc)->diffInDays($data, false)))
            ->first();

        return $fattura
            ? 'FT '.$fattura->numero_doc.' del '.Carbon::parse($fattura->data_doc)->format('d/m/Y')
            : null;
    }
}
