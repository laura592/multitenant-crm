<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;

/**
 * Rilegge chiusure e aperture sui lavaggi gia' registrati (28/09/2026).
 *
 * La pausa stagionale, nata il 24/09, cercava "chiusura" nella descrizione
 * del lavaggio. Ma quando la riga nasce da un rapportino quella descrizione
 * e' "Generato da rapportino RT-...": la parola sta nel testo del
 * rapportino, e nessuno la leggeva. Risultato: la pausa scattava solo sui
 * lavaggi scritti a mano nella lista del cliente, cioe' quasi mai.
 *
 * Corretto Lavaggio::seguiLaStagione(), resta da recuperare quello che era
 * gia' passato: i piani chiusi a settembre che risultano ancora attivi, e
 * che a ottobre finirebbero nel promemoria con il locale serrato.
 */
class RicontrollaStagione extends Command
{
    protected $signature = 'lavaggi:ricontrolla-stagione
                            {--tenant=alex : slug del tenant}
                            {--dal= : solo i lavaggi da questa data (YYYY-MM-DD), default inizio anno}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Rilegge chiusure e aperture sui lavaggi gia\' registrati e mette in pausa i piani rimasti attivi';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $dal = $this->option('dal') ?: now()->startOfYear()->toDateString();

        $lavaggi = Lavaggio::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereDate('data', '>=', $dal)
            ->with(['serviceReport', 'maintenanceSchedule', 'customer'])
            ->orderBy('data')
            ->get();

        // Di ogni piano conta solo l'ULTIMO evento stagionale, non tutti.
        // Applicandoli tutti, un'apertura di aprile annullava la chiusura di
        // settembre: su Chiosco Soleado il comando ha prima ripreso i piani
        // con il lavaggio del 15/04 e solo al giro dopo li ha rimessi in
        // pausa con quello del 23/09 (28/09/2026).
        $ultimoPerPiano = [];

        foreach ($lavaggi as $lavaggio) {
            if ($lavaggio->maintenance_schedule_id) {
                $ultimoPerPiano[$lavaggio->maintenance_schedule_id] = $lavaggio;
            }
        }

        $daFare = [];

        foreach ($ultimoPerPiano as $lavaggio) {
            $piano = $lavaggio->maintenanceSchedule;

            if (! $piano) {
                continue;
            }

            $testo = mb_strtolower((string) $lavaggio->descrizione.' '.(string) $lavaggio->serviceReport?->work_performed);

            $chiude = str_contains($testo, 'chiusura');
            $apre = str_contains($testo, 'apertura');

            // Ambiguo: vedi Lavaggio::seguiLaStagione().
            if ($chiude === $apre) {
                continue;
            }

            if ($chiude && ! $piano->in_pausa) {
                $daFare[] = ['lavaggio' => $lavaggio, 'azione' => 'pausa'];
            } elseif ($apre && $piano->in_pausa) {
                $daFare[] = ['lavaggio' => $lavaggio, 'azione' => 'riprendi'];
            }
        }

        if ($daFare === []) {
            $this->info('Tutti i piani sono gia\' allineati alla stagione.');

            return self::SUCCESS;
        }

        $this->table(
            ['Data', 'Cliente', 'Impianto', 'Bevanda', 'Cosa'],
            collect($daFare)->map(fn (array $r) => [
                $r['lavaggio']->data->format('d/m/Y'),
                mb_substr(DisplayName::titleCase($r['lavaggio']->customer?->company_name) ?? '—', 0, 28),
                $r['lavaggio']->maintenanceSchedule?->machineUnit?->serial_number ?? '—',
                $r['lavaggio']->maintenanceSchedule?->beverage_type ?? '—',
                $r['azione'] === 'pausa' ? 'mette in pausa' : 'riprende',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Allineo '.count($daFare).' piani alla stagione?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($daFare as $r) {
            // Il metodo corretto fa entrambe le cose e tiene il motivo con la
            // data giusta: meglio richiamarlo che rifarne la logica qui.
            $r['lavaggio']->seguiLaStagione($r['lavaggio']->maintenanceSchedule);
        }

        $this->info('Fatto: '.count($daFare).' piani allineati.');
        $this->line('I piani in pausa restano attivi e con la loro storia, ma non scadono e non entrano nei promemoria.');

        return self::SUCCESS;
    }
}
