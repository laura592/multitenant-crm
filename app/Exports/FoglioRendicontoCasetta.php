<?php

namespace App\Exports;

use App\Models\LetturaCasetta;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Un foglio di LettureCasetteExport: il rendiconto di una casetta, nell'ordine
 * in cui lo legge il Comune.
 *
 * Le colonne "del periodo" sono vuote quando la differenza non si puo' fare
 * (prima lettura in assoluto, o contatore non annotato). E' voluto: il foglio
 * di calcolo da cui arrivavano questi rendiconti sottraeva celle vuote e
 * stampava "Totale mese -1.052.210". Una casella vuota si vede e si chiede;
 * un numero sbagliato si fattura.
 */
class FoglioRendicontoCasetta implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @param  Collection<int, LetturaCasetta>  $letture */
    public function __construct(
        private readonly string $casetta,
        private readonly ?string $comune,
        private readonly Collection $letture,
    ) {}

    /**
     * Excel non accetta piu' di 31 caratteri ne' i segni : \ / ? * [ ] nel
     * nome di un foglio, e non si lamenta: scarta il file.
     */
    public function title(): string
    {
        $nome = $this->comune ?: $this->casetta;

        return Str::limit(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $nome), 31, '');
    }

    /** @return array<int, array<int, string>> */
    public function headings(): array
    {
        $unita = $this->letture->first()?->unitaBreve() ?? 'm³';

        return [
            ['Gestione distributore acqua trattata'.($this->comune ? ' — '.$this->comune : '')],
            ['Casetta '.$this->casetta],
            [],
            [
                'Data',
                'Controllo e pulizia',
                'Sanificazione',
                'Ricarica CO2',
                'Cambio filtro',
                "Lettura contatore ({$unita})",
                "Erogato nel periodo ({$unita})",
                'Incasso totale',
                'Incasso del periodo',
                'Venduto totale',
                'Venduto del periodo',
                'Scarto',
                'Tecnico',
                'Note',
            ],
        ];
    }

    /** @return Collection<int, array<int, mixed>> */
    public function collection(): Collection
    {
        /** @var Collection<int, array<int, mixed>> $righe */
        $righe = $this->letture->map(fn (LetturaCasetta $l): array => [
            $l->data->format('d/m/Y'),
            $l->controllo_pulizia ? 'Sì' : '',
            $l->sanificazione ? 'Sì' : '',
            $l->ricarica_co2 ? 'Sì' : '',
            $l->sostituzione_filtro ? 'Sì' : '',
            $l->contatore_acqua === null ? null : (float) $l->contatore_acqua,
            $l->erogato(),
            $l->incasso_totale === null ? null : (float) $l->incasso_totale,
            $l->incassoPeriodo(),
            $l->valore_vendite === null ? null : (float) $l->valore_vendite,
            $l->vendutoPeriodo(),
            $l->scarto(),
            $l->rilevataDa?->name,
            $l->note,
        ])->values();

        if ($righe->isEmpty()) {
            return $righe;
        }

        return $righe->push($this->totali());
    }

    /**
     * La riga di chiusura. Somma solo le differenze di periodo: i contatori
     * sono progressivi e sommarli non vuol dire niente (il totale di una
     * colonna di letture del contatore e' un numero senza significato, ed e'
     * esattamente quello che i vecchi fogli mettevano in fondo).
     *
     * @return array<int, mixed>
     */
    private function totali(): array
    {
        $somma = fn (callable $valore) => $this->letture
            ->map($valore)
            ->filter(fn (?float $v) => $v !== null)
            ->sum();

        return [
            'Totale periodo',
            $this->letture->where('controllo_pulizia', true)->count() ?: '',
            $this->letture->where('sanificazione', true)->count() ?: '',
            $this->letture->where('ricarica_co2', true)->count() ?: '',
            $this->letture->where('sostituzione_filtro', true)->count() ?: '',
            null,
            round($somma(fn (LetturaCasetta $l) => $l->erogato()), 2),
            null,
            round($somma(fn (LetturaCasetta $l) => $l->incassoPeriodo()), 2),
            null,
            round($somma(fn (LetturaCasetta $l) => $l->vendutoPeriodo()), 2),
            round($somma(fn (LetturaCasetta $l) => $l->scarto()), 2),
            null,
            null,
        ];
    }
}
