<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Riempie i moduli ancora vuoti (presenze, ferie, rapportini, preventivi
 * raggruppati) con dati plausibili/reali sull'organigramma Alex, cosi' i
 * moduli costruiti in questa sessione hanno qualcosa da mostrare invece di
 * apparire vuoti alla prima demo. Idempotente sui dati con date fisse
 * (Laura); il resto e' generico e rieseguibile senza duplicare in modo
 * pericoloso (usa firstOrCreate dove ha senso).
 */
class DemoOperationalDataSeeder extends Seeder
{
    public function run(): void
    {
        $alex = Tenant::where('slug', 'alex')->first();

        if (! $alex) {
            $this->command?->warn('Tenant Alex non trovato, salto il seed operativo.');

            return;
        }

        $laura = User::where('email', 'lauragrb.1990@gmail.com')->first();
        $alessandro = User::where('email', 's.alessandro@alexcaffe.com')->first();
        $cristina = User::where('email', 'cristina.burato@alexcaffe.com')->first();
        $igor = User::where('email', 'igor.capiotto@alexcaffe.com')->first();

        if ($laura && $alessandro) {
            $this->seedFerie($alex, $laura, $alessandro, '2026-09-08', '2026-09-18');
            $this->seedFerie($alex, $laura, $alessandro, '2027-01-09', '2027-01-22');
            $this->seedGiornata($alex, $laura, '2026-07-13', 8, 17);
        }

        // Un mese "normale" di timbrature (i 5 giorni lavorativi precedenti a oggi)
        // per tutti i dipendenti reali, cosi' il Riepilogo Ore ha righe da mostrare.
        foreach (array_filter([$laura, $cristina, $igor, $alessandro]) as $employee) {
            $this->seedRecentWorkWeek($alex, $employee);
        }

        if ($alessandro && $cristina) {
            $this->seedFerie($alex, $cristina, $alessandro, '2026-08-03', '2026-08-14');
        }
        if ($alessandro && $igor) {
            $this->seedFerie($alex, $igor, $alessandro, '2026-08-17', '2026-08-28');
        }

        $this->seedServiceReport($alex, $laura ?? $alessandro);
        $this->seedQuoteGroup($alex);
    }

    private function seedFerie(Tenant $tenant, User $user, User $approver, string $from, string $to): void
    {
        LeaveRequest::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => LeaveRequest::TYPE_FERIE,
            'date_from' => $from,
            'date_to' => $to,
        ], [
            'status' => 'approvato',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Una singola timbratura entrata/uscita in un giorno preciso, usata per
     * il caso richiesto esplicitamente: "1 ora di straordinario lunedi
     * 13 luglio 2026" con contratto part-time/full-time a 8h -> 08:00-17:00 = 9h.
     */
    private function seedGiornata(Tenant $tenant, User $user, string $date, int $startHour, int $endHour): void
    {
        $day = Carbon::parse($date);

        TimeEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'clock_in' => $day->copy()->setTime($startHour, 0),
        ], [
            'clock_out' => $day->copy()->setTime($endHour, 0),
            'source' => 'manuale',
            'status' => 'chiusa',
        ]);
    }

    private function seedRecentWorkWeek(Tenant $tenant, User $user): void
    {
        $day = now()->subDays(7);

        for ($i = 0; $i < 5; $i++) {
            $day->addDay();

            if ($day->isWeekend()) {
                continue;
            }

            // Non toccare un giorno gia' timbrato (es. il 13/07/2026 di Laura,
            // seedato apposta con un orario preciso per avere esattamente 1h
            // di straordinario: due timbrature nello stesso giorno falserebbero
            // il calcolo del Riepilogo Ore).
            $alreadyLogged = TimeEntry::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->whereDate('clock_in', $day->toDateString())
                ->exists();

            if ($alreadyLogged) {
                continue;
            }

            TimeEntry::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'clock_in' => $day->copy()->setTime(8, 30),
                'clock_out' => $day->copy()->setTime(17, 30),
                'source' => 'app',
                'status' => 'chiusa',
            ]);
        }
    }

    private function seedServiceReport(Tenant $tenant, ?User $technician): void
    {
        if (! $technician || ServiceReport::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $customer = Customer::where('tenant_id', $tenant->id)->first()
            ?? Customer::whereNull('tenant_id')->first();
        $machine = Product::where('type', Product::TYPE_MACHINE)->whereNotNull('name')->first();
        $part = Product::where('name', 'like', '%Guarnizione%')->first();

        if (! $customer || ! $machine) {
            return;
        }

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'machine_product_id' => $machine->id,
            'technician_id' => $technician->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now()->subDays(3),
            'arrival_at' => now()->subDays(3)->setTime(9, 0),
            'departure_at' => now()->subDays(3)->setTime(10, 30),
            'problem_description' => 'Manutenzione ordinaria programmata, nessuna anomalia segnalata dal cliente.',
            'work_performed' => 'Sostituzione guarnizioni gruppo erogazione, pulizia caldaia, verifica pressione.',
            'status' => 'inviato',
        ]);

        if ($part) {
            $report->partsUsed()->create(['product_id' => $part->id, 'quantity' => 1]);
        }
    }

    /**
     * Esempio di preventivi multipli raggruppati (docs/architecture.md §14):
     * due opzioni per lo stesso cliente inviate come un'unica email di
     * confronto, invece di due preventivi separati e slegati.
     */
    private function seedQuoteGroup(Tenant $tenant): void
    {
        if (QuoteGroup::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $customer = Customer::where('tenant_id', $tenant->id)->first()
            ?? Customer::whereNull('tenant_id')->first();
        $machines = Product::where('type', Product::TYPE_MACHINE)->whereNotNull('name')->limit(2)->get();

        if (! $customer || $machines->count() < 2) {
            return;
        }

        $group = QuoteGroup::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => 'inviato',
        ]);

        foreach ($machines as $machine) {
            $price = (float) optional($machine->prices()->latest()->first())->price;

            if ($price <= 0) {
                continue;
            }

            $quote = Quote::create([
                'tenant_id' => $tenant->id,
                'quote_group_id' => $group->id,
                'customer_id' => $customer->id,
                'date' => now(),
                'status' => 'inviato',
            ]);

            $quote->quoteProducts()->create([
                'product_id' => $machine->id, 'quantity' => 1, 'price' => $price, 'discount' => 0, 'tax' => 22,
            ]);
            $quote->updateTotal();
        }
    }
}
