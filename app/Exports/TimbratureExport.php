<?php

namespace App\Exports;

use App\Filament\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Support\DisplayName;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * L'export delle timbrature (24/09/2026).
 *
 * Prima lo faceva `pxlrbt/filament-excel` con `->fromTable()`, cioe'
 * riflettendo qualunque colonna fosse visibile in quel momento. Quel pacchetto
 * e' stato tolto perche' la sua versione aggiornata pretende Filament 4, e
 * senza aggiornarlo il progetto restava inchiodato a PHP 8.4
 * (phpspreadsheet 1.x): vedi docs/prova-postgres.md per la catena.
 *
 * Le colonne sono quindi scritte qui, esplicite. E' anche piu' onesto: quello
 * che esce dall'export non dipende piu' da quali colonne l'utente aveva
 * aperto sullo schermo.
 */
class TimbratureExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param  Collection<int, TimeEntry>  $righe */
    public function __construct(private Collection $righe) {}

    public function collection(): Collection
    {
        return $this->righe;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Dipendente', 'Giorno', 'Entrata', 'Uscita', 'Ore', 'Trasferta', 'Origine', 'Stato'];
    }

    /**
     * @param  TimeEntry  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        return [
            DisplayName::titleCase($row->user?->name) ?? '—',
            $row->clock_in->translatedFormat('D d F Y'),
            $row->clock_in->format('H:i'),
            // Nullable davvero, anche se il PHPDoc del modello dice di no:
            // finche' il turno e' aperto l'uscita non c'e' (e' il motivo del
            // segnaposto "In corso" sulla colonna a schermo).
            $row->clock_out?->format('H:i') ?? 'In corso',
            $row->worked_hours ?? '—',
            $row->trasferta ? ($row->destinazione_trasferta ?: 'Sì') : '—',
            $row->source === 'app' ? 'App (tempo reale)' : 'Manuale',
            TimeEntryResource::statusLabels()[$row->status] ?? ucfirst((string) $row->status),
        ];
    }
}
