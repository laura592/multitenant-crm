<?php

namespace App\Support;

use App\Models\ServiceReport;
use App\Support\Gestionale\FattureRapportino;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * I rapportini raggruppati per fattura, pronti da stampare.
 *
 * Richiesta dell'ufficio (12/10/2026): dall'elenco dei rapportini tirare
 * fuori un PDF per fattura, con dentro i rapportini che quella fattura ha
 * fatturato. Serve quando il cliente chiede conto di una fattura: un
 * allegato per fattura, non venti rapportini da cercare a mano.
 *
 * La fattura di un rapportino e' quella annotata da
 * eureka:allinea-fatture-rapportini (campo eureka_fatture, la prima della
 * lista, la piu' recente). Chi non ce l'ha finisce nel gruppo "senza
 * fattura", che e' un'informazione e non uno scarto: sono i rapportini da
 * controllare.
 */
final class RapportiniPerFattura
{
    public const SENZA_FATTURA = 'senza-fattura';

    /**
     * @param  Collection<int, ServiceReport>  $rapportini
     * @return array<string, array{etichetta: ?string, cliente: string, rapportini: Collection<int, ServiceReport>}>
     */
    public static function raggruppa(Collection $rapportini): array
    {
        $gruppi = [];

        foreach ($rapportini as $rapportino) {
            $fattura = $rapportino->eureka_fatture[0] ?? null;
            $chiave = $fattura ? 'ft-'.($fattura['id_fattura'] ?? md5(json_encode($fattura))) : self::SENZA_FATTURA;

            $gruppi[$chiave] ??= [
                'etichetta' => $fattura ? FattureRapportino::etichetta($fattura) : null,
                'rapportini' => collect(),
            ];

            $gruppi[$chiave]['rapportini']->push($rapportino);
        }

        // Prima le fatture in ordine di data, "senza fattura" in fondo: e'
        // il gruppo che si guarda per ultimo, quando i conti sono fatti.
        uasort($gruppi, function (array $a, array $b): int {
            if (($a['etichetta'] === null) !== ($b['etichetta'] === null)) {
                return $a['etichetta'] === null ? 1 : -1;
            }

            return $a['rapportini']->min('intervention_date') <=> $b['rapportini']->min('intervention_date');
        });

        foreach ($gruppi as $chiave => $gruppo) {
            // Il cliente del gruppo: quello di tutti, se e' uno solo. Una
            // fattura puo' raccogliere rapportini di sedi diverse dello
            // stesso pagante, e allora si dice quanti sono invece di
            // scegliere il primo e mentire.
            $clienti = $gruppo['rapportini']
                ->map(fn (ServiceReport $r) => DisplayName::titleCase($r->customer?->company_name))
                ->filter()
                ->unique()
                ->values();

            $gruppi[$chiave]['cliente'] = $clienti->count() === 1
                ? (string) $clienti->first()
                : $clienti->count().' clienti';
        }

        return $gruppi;
    }

    /**
     * @param  array{etichetta: ?string, cliente: string, rapportini: Collection<int, ServiceReport>}  $gruppo
     */
    public static function pdf(array $gruppo, bool $showPrices): string
    {
        $primo = $gruppo['rapportini']->first();

        // Vedi App\Support\OutsideLivewireRender: chiamato da un'azione
        // Livewire, senza questo il PDF esce con dentro i commenti
        // <!--[if BLOCK]><![endif]--> attorno a ogni @if.
        return OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.rapportini-per-fattura', [
            'fattura' => $gruppo['etichetta'],
            'cliente' => $gruppo['cliente'],
            'rapportini' => $gruppo['rapportini']->sortBy('intervention_date')->values(),
            'showPrices' => $showPrices,
            'tenant' => $primo->tenant,
        ])->output());
    }

    /**
     * @param  array{etichetta: ?string, cliente: string, rapportini: Collection<int, ServiceReport>}  $gruppo
     */
    public static function nomeFile(array $gruppo): string
    {
        $pezzo = $gruppo['etichetta']
            ? Str::slug($gruppo['etichetta'])
            : 'senza-fattura-'.Str::slug($gruppo['cliente']);

        return 'rapportini-'.$pezzo.'.pdf';
    }

    /**
     * Le relazioni che servono alla stampa, caricate in un colpo invece di
     * una query per rapportino (una fattura puo' averne ventisette).
     *
     * @return array<int, string>
     */
    public static function relazioni(): array
    {
        return [
            'customer', 'technician', 'machineProduct', 'machineMaterial',
            'machineUnit.product', 'machineUnit.billingCustomer',
            'partsUsed.product', 'materialsUsed.material', 'tenant',
        ];
    }
}
