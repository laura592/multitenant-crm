<?php

namespace App\Support\Pdf;

use App\Models\Customer;
use App\Models\ProdottoCaffe;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

/**
 * L'offerta caffe' di un cliente: i prezzi di listino, eventualmente
 * ritoccati per lui, senza quantita'.
 *
 * Senza quantita' e senza totale di proposito: l'ufficio l'ha voluta
 * separata dal preventivo proprio perche' quanti chili il cliente prendera'
 * non si sa (21/09/2026). Si offre un prezzo, non una fornitura.
 */
final class OffertaCaffePdf
{
    /**
     * Le righe come le propone il form: il listino attivo, nell'ordine del
     * listino.
     *
     * @return array<int, array{gruppo: string, nome: string, formato: ?string, prezzo: string}>
     */
    public static function righeDaListino(): array
    {
        return ProdottoCaffe::query()->inListino()->get()
            ->map(fn (ProdottoCaffe $p) => [
                'gruppo' => $p->gruppo,
                'nome' => $p->nome,
                'formato' => $p->formato,
                'prezzo' => (string) $p->prezzo,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{gruppo?: ?string, nome?: ?string, formato?: ?string, prezzo?: mixed}>  $righe
     */
    public static function crea(Customer $cliente, array $righe, ?CarbonInterface $validaFino = null, ?string $note = null): DomPdf
    {
        return Pdf::loadView('pdf.offerta-caffe', self::datiVista($cliente, $righe, $validaFino, $note))
            ->setPaper('a4');
    }

    /**
     * Quello che la vista riceve. Separato da crea() perche' il PDF compilato
     * non espone l'HTML, e il contenuto si verifica sulla vista.
     *
     * @return array<string, mixed>
     */
    public static function datiVista(Customer $cliente, array $righe, ?CarbonInterface $validaFino = null, ?string $note = null): array
    {
        return [
            'cliente' => $cliente,
            'tenant' => Filament::getTenant() ?? $cliente->tenant,
            'data' => now(),
            'validaFino' => $validaFino,
            'note' => filled($note) ? trim($note) : null,
            'gruppi' => self::perGruppo($righe),
        ];
    }

    /**
     * Un prezzo come arriva, da qualunque parte arrivi: float dal form
     * (MoneyInput lo converte all'invio), "17.80" dal database, "17,80" o
     * "1.234,50" scritto all'italiana. Un (float) secco su "17,80" darebbe 17.
     */
    public static function prezzo(mixed $valore): float
    {
        if (is_int($valore) || is_float($valore)) {
            return (float) $valore;
        }

        $testo = trim((string) $valore);

        if (str_contains($testo, ',')) {
            $testo = str_replace(['.', ','], ['', '.'], $testo);
        }

        return (float) $testo;
    }

    /**
     * Righe raggruppate per gruppo, nell'ordine di ProdottoCaffe::GRUPPI. Una
     * riga aggiunta a mano senza gruppo va con il caffe'; un gruppo che non
     * conosciamo finisce in coda col suo nome, invece di sparire.
     *
     * @return Collection<string, Collection<int, array>>
     */
    private static function perGruppo(array $righe): Collection
    {
        $pulite = collect($righe)
            ->filter(fn ($r) => filled($r['nome'] ?? null))
            ->map(fn ($r) => [
                'gruppo' => ($r['gruppo'] ?? null) ?: 'caffe',
                'nome' => trim($r['nome']),
                'formato' => filled($r['formato'] ?? null) ? trim($r['formato']) : null,
                'prezzo' => self::prezzo($r['prezzo'] ?? 0),
            ]);

        $ordine = array_keys(ProdottoCaffe::GRUPPI);

        return $pulite
            ->groupBy('gruppo')
            ->sortBy(fn ($_, string $gruppo) => array_search($gruppo, $ordine, true) === false
                ? PHP_INT_MAX
                : array_search($gruppo, $ordine, true))
            ->mapWithKeys(fn (Collection $g, string $gruppo) => [
                (ProdottoCaffe::GRUPPI[$gruppo] ?? $gruppo) => $g->values(),
            ]);
    }
}
