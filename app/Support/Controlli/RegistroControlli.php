<?php

namespace App\Support\Controlli;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Resources\ServiceReportResource;
use App\Filament\Resources\VehicleResource;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * I controlli sui dati, in un posto solo.
 *
 * Nascono da una settimana in cui ogni problema e' saltato fuori per caso:
 * il piano di Patatrac fermo dal 5 settembre scoperto guardando un'altra
 * cosa, la Casadio intestata a Isola Blu trovata mentre se ne sistemava
 * un'altra, i mezzi senza assicurazione visti solo perche' si stava
 * scrivendo il promemoria. Nessuno di questi era nascosto: erano tutti
 * visibili, ciascuno nella sua pagina, e nessuno li guardava insieme.
 *
 * Ogni controllo risponde a una domanda sola e porta dove si sistema. Lo
 * zero non e' "nessun risultato", e' "questa cosa e' a posto": per questo
 * i controlli a zero restano in pagina, in verde, invece di sparire come
 * fanno i blocchi della dashboard.
 */
class RegistroControlli
{
    /** @return Collection<int, Controllo> */
    public static function tutti(): Collection
    {
        return collect([
            self::anagraficheDoppie(),
            self::codiciFiscaliRipetuti(),
            self::clientiSenzaDatiFiscali(),
            self::pianiScaduti(),
            self::macchineSenzaPiano(),
            self::rapportiniMaiInviati(),
            self::mezziScoperti(),
            self::lavaggiSenzaVie(),
            self::macchineForseSpostate(),
            self::fusioniDaDecidere(),
        ])->filter(fn (Controllo $c) => $c->visibile())->values();
    }

    private static function puo(string $permesso): bool
    {
        return Auth::user()?->can($permesso) ?? false;
    }

    private static function anagraficheDoppie(): Controllo
    {
        return new Controllo(
            titolo: 'Anagrafiche che sembrano doppie',
            spiegazione: 'Stessa P.IVA, stesso nome e stessa citta\'. I punti vendita della stessa societa\' (Airest, Gromart) non compaiono: hanno nomi diversi ed e\' giusto che siano anagrafiche separate.',
            icona: 'heroicon-o-document-duplicate',
            conta: fn () => AnagraficheDoppie::gruppi()->count(),
            url: fn () => CustomerResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Nessuna anagrafica sdoppiata.',
            azione: 'Apri i clienti',
        );
    }

    private static function codiciFiscaliRipetuti(): Controllo
    {
        return new Controllo(
            titolo: 'Codici fiscali su piu\' anagrafiche',
            spiegazione: 'Spesso e\' lo stesso titolare con due attivita\', ed e\' corretto. Ma e\' anche il modo in cui un codice finisce incollato sul cliente sbagliato.',
            icona: 'heroicon-o-identification',
            conta: fn () => AnagraficheDoppie::codiciFiscaliRipetuti()->count(),
            url: fn () => CustomerResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Nessun codice fiscale ripetuto.',
            azione: 'Apri i clienti',
        );
    }

    private static function clientiSenzaDatiFiscali(): Controllo
    {
        return new Controllo(
            titolo: 'Clienti con lavoro aperto e senza dati fiscali',
            spiegazione: 'Hanno gia\' un preventivo o un rapportino ma non hanno ne\' P.IVA ne\' codice fiscale: non si possono mandare in Eureka, quindi non si puo\' fatturare quello che e\' gia\' stato fatto.',
            icona: 'heroicon-o-exclamation-circle',
            // Solo chi ha gia' qualcosa in ballo. Senza questo filtro il
            // controllo contava 954 anagrafiche (24/11/2026): i lead dal
            // sito e i 101 soci ABA, che un dato fiscale non ce l'hanno
            // perche' non l'hanno mai avuto e per ora va bene cosi'. Un
            // numero cosi' grande non e' un controllo, e' uno sfondo.
            conta: fn () => Customer::query()
                ->where(fn ($q) => $q->whereNull('vat_number')->orWhereRaw("TRIM(vat_number) = ''"))
                ->where(fn ($q) => $q->whereNull('tax_code')->orWhereRaw("TRIM(tax_code) = ''"))
                ->where(fn ($q) => $q->whereHas('quotes')->orWhereHas('serviceReports'))
                ->count(),
            url: fn () => CustomerResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Tutti i clienti hanno almeno un dato fiscale.',
            azione: 'Apri i clienti',
        );
    }

    private static function pianiScaduti(): Controllo
    {
        return new Controllo(
            titolo: 'Piani scaduti e non in pausa',
            spiegazione: 'Lavaggi e manutenzioni la cui data e\' passata senza che arrivasse un rapportino. Chi ha chiuso per la stagione non c\'e\': quello si mette in pausa e smette di scadere.',
            icona: 'heroicon-o-clock',
            conta: fn () => MaintenanceSchedule::query()
                ->inCorso()
                ->whereNotNull('next_due_date')
                ->whereDate('next_due_date', '<', now())
                ->count(),
            url: fn () => MaintenanceScheduleResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_maintenance::schedule'),
            quandoZero: 'Nessun piano indietro.',
            azione: 'Apri i piani',
        );
    }

    private static function macchineSenzaPiano(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine che si manutengono senza un piano',
            spiegazione: 'Ci si va sopra regolarmente — ci sono rapportini di manutenzione o sanificazione — ma nessun piano le tiene: se saltano un giro non se ne accorge nessuno. E\' il caso dell\'impianto acqua di Patatrac, lavorato il 9 ottobre e il 24 novembre senza che nulla lo registrasse.',
            icona: 'heroicon-o-wrench-screwdriver',
            // "Installata e senza piano" da solo contava 705 macchine
            // (24/11/2026): moltissime non devono avere un piano, e un
            // numero cosi' nasconde il caso vero invece di mostrarlo. Il
            // segnale e' la contraddizione: la manuteniamo davvero ma non
            // la pianifichiamo.
            //
            // MachineUnit non ha una relazione verso i piani (il legame
            // vive dall'altra parte, MaintenanceSchedule->machine_unit_id),
            // quindi la domanda si fa con una sottoquery.
            conta: fn () => MachineUnit::query()
                ->where('status', 'installata')
                ->whereNotExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('maintenance_schedules')
                    ->whereColumn('maintenance_schedules.machine_unit_id', 'machine_units.id'))
                ->whereExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('service_reports')
                    ->whereColumn('service_reports.machine_unit_id', 'machine_units.id')
                    ->whereNull('service_reports.deleted_at')
                    ->whereIn('service_reports.intervention_type', ['manutenzione_ordinaria', 'sanificazione']))
                ->count(),
            url: fn () => MachineUnitResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Ogni macchina installata ha il suo piano.',
            azione: 'Apri le macchine',
        );
    }

    private static function rapportiniMaiInviati(): Controllo
    {
        return new Controllo(
            titolo: 'Rapportini chiusi e mai inviati',
            spiegazione: 'Il lavoro e\' fatto e il rapportino e\' chiuso, ma in Eureka non e\' mai arrivato: finche\' non ci arriva, non si fattura.',
            icona: 'heroicon-o-arrow-up-tray',
            conta: fn () => ServiceReport::query()
                ->where('source', '!=', 'eureka')
                ->whereIn('status', ServiceReport::CLOSED_STATUSES)
                ->where('status', '!=', 'in_gestionale')
                ->whereNull('gestionale_sync_status')
                ->count(),
            url: fn () => ServiceReportResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_service::report'),
            quandoZero: 'Tutti i rapportini chiusi sono passati in Eureka.',
            azione: 'Apri i rapportini',
        );
    }

    private static function mezziScoperti(): Controllo
    {
        return new Controllo(
            titolo: 'Mezzi senza documenti obbligatori',
            spiegazione: 'Manca assicurazione, bollo o revisione in corso. Non e\' una scadenza vicina: e\' una scadenza che non c\'e\', e quindi nessun promemoria potra\' mai avvisare.',
            icona: 'heroicon-o-truck',
            conta: fn () => Vehicle::query()->conScadenzeMancanti()->count(),
            url: fn () => VehicleResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_vehicle'),
            quandoZero: 'Ogni mezzo in flotta e\' coperto.',
            azione: 'Apri i mezzi',
        );
    }

    private static function lavaggiSenzaVie(): Controllo
    {
        return new Controllo(
            titolo: 'Lavaggi senza numero di vie',
            spiegazione: 'Senza le vie il lavaggio non si puo\' valorizzare. Quelli ricavabili dal piano o dalla descrizione li sistema «php artisan lavaggi:vie-mancanti»; questi sono quelli rimasti, da leggere sul rapportino.',
            icona: 'heroicon-o-beaker',
            conta: fn () => Lavaggio::query()->whereNull('lines_washed')->count(),
            url: fn () => null,
            visibile: fn () => self::puo('view_any_maintenance::schedule'),
            quandoZero: 'Tutti i lavaggi hanno le vie.',
        );
    }

    private static function macchineForseSpostate(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine che sembrano spostate',
            spiegazione: 'Eureka le vede lavorare da un cliente diverso da quello a cui sono intestate. Finche\' non si decide, i rapportini finiscono sull\'anagrafica sbagliata — e\' il caso della Casadio ancora intestata a Isola Blu.',
            icona: 'heroicon-o-arrows-right-left',
            conta: fn () => MachineUnit::query()
                ->whereNotNull('spostamento_suggerito_customer_id')
                ->whereNull('spostamento_scartato')
                ->count(),
            url: fn () => MachineUnitResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Nessuno spostamento in sospeso.',
            azione: 'Apri le macchine',
        );
    }

    private static function fusioniDaDecidere(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine forse doppie, da decidere',
            spiegazione: 'Due matricole che potrebbero essere la stessa macchina. Dove Eureka elenca entrambe puo\' trattarsi davvero di due apparecchi: vanno guardate una per una.',
            icona: 'heroicon-o-square-2-stack',
            conta: fn () => MachineUnit::query()
                ->whereNotNull('fusione_suggerita_id')
                ->whereNull('fusa_in_id')
                ->count(),
            url: fn () => MachineUnitResource::getUrl('index'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Nessuna fusione in sospeso.',
            azione: 'Apri le macchine',
        );
    }
}
