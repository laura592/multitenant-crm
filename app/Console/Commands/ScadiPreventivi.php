<?php

namespace App\Console\Commands;

use App\Models\Quote;
use App\Models\QuoteGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Porta a "scaduto" i preventivi inviati che hanno passato la validita'.
 *
 * NON MANDA NESSUNA MAIL, ne' al cliente ne' a noi. Cambia lo stato e
 * basta. Avvisare il cliente che la sua offerta e' decaduta e' una
 * decisione commerciale, e in questa azienda le mail ai clienti le manda
 * una persona: il comando prepara il terreno, non parla al posto di
 * nessuno.
 *
 * Il preventivo scaduto resta comunque accettabile dal link del cliente
 * (vedi Quote::isDecided()): un si' in ritardo si incassa. Scaduto vuol
 * dire "non lo conto piu' fra le trattative aperte", non "non lo voglio
 * piu'".
 *
 * Con --dry-run stampa cosa farebbe senza toccare niente: il primo giro
 * su uno storico mai ripulito sposta decine di preventivi insieme, ed e'
 * giusto guardarli prima.
 */
class ScadiPreventivi extends Command
{
    protected $signature = 'preventivi:scadi
                            {--dry-run : stampa i preventivi che scadrebbero senza modificarli}
                            {--giorni= : considera scaduti i preventivi fermi da piu di N giorni, invece di usare la colonna valid_until}';

    protected $description = 'Marca scaduti i preventivi inviati e mai risposti oltre la data di validita';

    public function handle(): int
    {
        $prova = (bool) $this->option('dry-run');
        $giorni = $this->option('giorni') !== null ? (int) $this->option('giorni') : null;

        $query = Quote::withoutGlobalScopes()
            ->where('status', Quote::STATUS_INVIATO)
            ->with(['customer', 'quoteGroup']);

        if ($giorni !== null) {
            // Scorciatoia per i casi in cui la validita' non e' stata
            // scritta (preventivi importati, o colonna lasciata vuota).
            $query->whereDate('date', '<', now()->subDays($giorni)->toDateString());
        } else {
            $query->whereNotNull('valid_until')
                ->whereDate('valid_until', '<', now()->toDateString());
        }

        /** @var Collection<int, Quote> $preventivi */
        $preventivi = $query->orderBy('date')->get();

        if ($preventivi->isEmpty()) {
            $this->info('Nessun preventivo da far scadere.');

            return self::SUCCESS;
        }

        // Si raggruppa per trattativa, non per preventivo: le alternative
        // dentro la stessa offerta OFF sono la stessa trattativa, e
        // contarle separate gonfia il totale fino a sei volte.
        $trattative = $preventivi->groupBy(fn (Quote $q) => $q->quote_group_id ?: 'singolo-'.$q->id);

        $this->line('');
        $this->line($prova
            ? '<comment>PROVA</comment> — scadrebbero '.$preventivi->count().' preventivi, '.$trattative->count().' trattative:'
            : 'Scadono '.$preventivi->count().' preventivi, '.$trattative->count().' trattative:');
        $this->line('');

        $valore = 0.0;

        foreach ($trattative as $gruppo) {
            /** @var Quote $primo */
            $primo = $gruppo->first();
            // Del gruppo si conta l'alternativa piu' cara: e' il massimo
            // che quella trattativa poteva valere.
            $valore += (float) $gruppo->max('total');

            $this->line(sprintf(
                '  %-18s %-38s %s  fermo da %d giorni  %s',
                $primo->quoteGroup?->number ?? $primo->number,
                mb_substr((string) ($primo->customer?->search_name ?? '—'), 0, 38),
                $primo->date?->format('d/m/Y') ?? '—',
                $primo->giorniDiSilenzio() ?? 0,
                number_format((float) $gruppo->max('total'), 2, ',', '.').' EUR'
                    .($gruppo->count() > 1 ? '  ('.$gruppo->count().' alternative)' : '')
            ));
        }

        $this->line('');
        $this->line('  valore complessivo: '.number_format($valore, 2, ',', '.').' EUR');
        $this->line('');

        if ($prova) {
            $this->comment('Niente e\' stato modificato. Rilancia senza --dry-run per applicare.');

            return self::SUCCESS;
        }

        foreach ($preventivi as $preventivo) {
            $preventivo->update(['status' => Quote::STATUS_SCADUTO]);
        }

        $offerte = $this->scadiLeOfferte($preventivi);

        $this->info('Fatto: '.$preventivi->count().' preventivi marcati come scaduti'
            .($offerte > 0 ? ", $offerte offerte chiuse." : '.'));

        return self::SUCCESS;
    }

    /**
     * Chiude anche l'offerta, quando tutte le sue alternative sono scadute.
     *
     * Senza questo un'offerta restava "Inviato" per sempre con dentro sei
     * preventivi scaduti: nell'elenco delle offerte sembrava ancora in
     * ballo. Si guarda che non resti nessuna alternativa viva — inviata o
     * accettata: se una e' stata accettata l'offerta e' "scelta", non
     * scaduta, e non la si tocca.
     *
     * @param  Collection<int, Quote>  $preventivi
     * @return int quante offerte sono state chiuse
     */
    private function scadiLeOfferte(Collection $preventivi): int
    {
        $chiuse = 0;

        foreach ($preventivi->pluck('quote_group_id')->filter()->unique() as $gruppo) {
            $restaQualcosa = Quote::withoutGlobalScopes()
                ->where('quote_group_id', $gruppo)
                ->whereIn('status', [Quote::STATUS_INVIATO, Quote::STATUS_ACCETTATO])
                ->exists();

            if ($restaQualcosa) {
                continue;
            }

            $chiuse += QuoteGroup::withoutGlobalScopes()
                ->where('id', $gruppo)
                ->where('status', Quote::STATUS_INVIATO)
                ->update(['status' => Quote::STATUS_SCADUTO]);
        }

        return $chiuse;
    }
}
