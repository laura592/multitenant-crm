<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Un foglio di ProblemiGestionaleExport: solo le colonne che servono a quel problema. */
class FoglioProblemiGestionale implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<int, string>  $colonne
     * @param  Collection<int, array<string, string>>  $righe
     */
    public function __construct(
        private readonly string $titolo,
        private readonly array $colonne,
        private readonly Collection $righe,
    ) {}

    public function title(): string
    {
        return $this->titolo;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return $this->colonne;
    }

    public function collection(): Collection
    {
        return $this->righe
            ->map(fn (array $riga) => array_map(fn (string $colonna) => $riga[$colonna] ?? '', $this->colonne))
            ->values();
    }
}
