<?php

namespace App\Filament\Pages;

use App\Support\Controlli\Controllo;
use App\Support\Controlli\RegistroControlli;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * I controlli sui dati, tutti su una pagina.
 *
 * Il motivo per cui esiste: in una settimana sola sono saltati fuori per
 * puro caso il piano di Patatrac fermo dal 5 settembre, la Casadio ancora
 * intestata a Isola Blu e cinque mezzi senza assicurazione — ciascuno
 * trovato mentre si guardava altro. Nessuno era nascosto: erano visibili,
 * ognuno nella sua pagina, e non c'era nessun posto da cui guardarli
 * insieme (Laura, 24/11/2026).
 *
 * I conteggi NON sono in cache. Una pagina di controlli che mostra numeri
 * di ieri e' peggio che non averla: si sistema qualcosa, si ricarica, e il
 * numero non si muove. Costa qualche query in piu' a ogni apertura, e si
 * apre poche volte al giorno.
 */
class Controlli extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Impostazioni';

    protected static ?string $navigationLabel = 'Controlli';

    protected static ?string $title = 'Controlli sui dati';

    protected static ?string $slug = 'controlli';

    protected static string $view = 'filament.pages.controlli';

    /** @var Collection<int, Controllo>|null */
    protected ?Collection $memo = null;

    /** @return Collection<int, Controllo> */
    public function controlli(): Collection
    {
        return $this->memo ??= RegistroControlli::tutti();
    }

    /**
     * I controlli che hanno qualcosa da dire, in cima; quelli a posto sotto.
     *
     * Due collezioni di righe {controllo, quanti}: la prima con quanti > 0,
     * la seconda con quanti === 0.
     */
    public function divisi(): array
    {
        $righe = $this->controlli()->map(fn (Controllo $c) => [
            'controllo' => $c,
            'quanti' => $c->quanti(),
        ]);

        return [
            $righe->filter(fn (array $r) => $r['quanti'] > 0)
                ->sortByDesc('quanti')->values(),
            $righe->filter(fn (array $r) => $r['quanti'] === 0)->values(),
        ];
    }

    public function getSubheading(): ?string
    {
        [$daFare] = $this->divisi();

        if ($daFare->isEmpty()) {
            return 'Tutti i controlli sono a posto.';
        }

        $cose = $daFare->sum('quanti');

        return $daFare->count() === 1
            ? "Un controllo segnala qualcosa: {$cose} cose da guardare."
            : "{$daFare->count()} controlli segnalano qualcosa, {$cose} cose da guardare in tutto.";
    }
}
