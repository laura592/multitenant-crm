<?php

namespace App\Support\Rapportini;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Support\TariffeIntervento;
use Filament\Forms;
use Illuminate\Support\Str;

/**
 * I campi lavaggio di un rapportino: valori iniziali e sincronizzazione
 * verso le righe materiali.
 *
 * Stava dentro ServiceReportResource, ma non e' interfaccia: e' la regola
 * di cosa significa "lavaggio impianti a N vie" in termini di materiali e
 * codici tariffa. Lo usavano gia' tre file — la resource e le sue pagine
 * di creazione e modifica — quindi era di fatto una libreria condivisa
 * travestita da metodo privato di una schermata.
 *
 * Quattro dei cinque metodi non sanno niente di Filament: prendono un
 * rapportino e restituiscono valori. Solo syncLavaggioViaMaterials riceve
 * Set/Get, perche' deve riscrivere le righe materiali mentre l'utente
 * cambia il numero di vie, e quelle righe vivono nel form finche' non si
 * salva. Sta qui lo stesso: la regola che decide quali materiali servono
 * per N vie e' la stessa che usano gli altri quattro, e separarla
 * significherebbe tenerne due copie.
 */
class LavaggioFields
{
    /**
     * "LAVAGGIO 2 VIE" e' la tariffa minima gia' agevolata per i tecnici,
     * dovuta anche lavando una sola via; "ULTERIORE VIA LAVATA" copre solo le
     * vie oltre la seconda. Vedi syncLavaggioViaMaterials() piu' sotto.
     *
     * Sono i codici di ripiego: se il pagante ha un listino suo,
     * codiciTariffa() restituisce i suoi e questi non entrano in gioco.
     */
    private const LAVAGGIO_VIE_BASE_MATERIAL_CODE = 'LAV2';

    private const LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE = 'ULTVIA';

    /**
     * Stato "reale" (da DB) del campo "Impianti e vie lavate" per un
     * rapportino esistente: niente pivot dedicata, le vie lavate si leggono
     * direttamente dalle righe Lavaggio gia' generate per questo rapportino
     * (stesso criterio univoco service_report_id+maintenance_schedule_id di
     * Lavaggio::firstOrNew() dentro ServiceReport::syncGeneratedLavaggi()).
     * Iniettato da EditServiceReport::mutateFormDataBeforeFill(), stesso
     * motivo di resolveLavaggioShortcutDefaults() qui sotto.
     */
    public static function resolveLavaggioImpiantiDefaults(?ServiceReport $record): array
    {
        if (! $record) {
            return [];
        }

        return $record->maintenanceSchedules()->get()
            ->map(fn (MaintenanceSchedule $schedule) => [
                'maintenance_schedule_id' => $schedule->id,
                'lines_washed' => Lavaggio::where('service_report_id', $record->id)
                    ->where('maintenance_schedule_id', $schedule->id)
                    ->value('lines_washed'),
            ])
            ->all();
    }

    /**
     * Applica le righe del Repeater "Impianti e vie lavate": prima la
     * selezione esplicita dei piani coinvolti (attach nudo, senza dati extra
     * sulla pivot — vince sulla regola implicita di
     * ServiceReport::syncMaintenanceSchedule(), vedi il commento sul campo
     * nel form), poi la generazione/aggiornamento delle righe Lavaggio, poi
     * le vie lavate scritte direttamente su quelle righe (niente colonna
     * pivot dedicata: piu' semplice riscrivere lines_washed a colpo sicuro
     * sulla riga Lavaggio che il sync ha appena creato/toccato). Chiamata da
     * CreateServiceReport::afterCreate() ed EditServiceReport::afterSave().
     */
    public static function syncLavaggioImpianti(ServiceReport $record, array $rows): void
    {
        $scheduleIds = collect($rows)
            ->pluck('maintenance_schedule_id')
            ->filter()
            ->values();

        $record->maintenanceSchedules()->sync($scheduleIds);
        $record->syncMaintenanceSchedule();

        foreach ($rows as $row) {
            if (blank($row['maintenance_schedule_id'] ?? null) || blank($row['lines_washed'] ?? null)) {
                continue;
            }

            Lavaggio::where('service_report_id', $record->id)
                ->where('maintenance_schedule_id', $row['maintenance_schedule_id'])
                ->update(['lines_washed' => $row['lines_washed']]);
        }
    }

    /**
     * Stato "reale" (da DB, non dal form) delle righe CHIVE/CHIORD e
     * LAV2/ULTVIA gia' presenti su un rapportino esistente: serve a far
     * partire i toggle/hidden di cui sopra gia' allineati a quello che c'e'
     * davvero in "Ricambi/materiali utilizzati" (es. un rapportino importato
     * da Eureka con CHIORD+LAV2 gia' in elenco), invece che sempre spenti.
     * Le key restituite sono gli id delle righe ServiceReportMaterial con
     * prefisso "record-", cosi' da poter essere riusate cosi' come sono come
     * chiavi del repeater ->relationship() (che su un edit e' keyed per
     * "record-{id}", non per id nudo ne' per uuid generato al volo).
     *
     * Pubblico perche' su EditServiceReport i ->default() qui sotto NON
     * bastano: Filament valuta getDefaultState() solo quando fill() e'
     * chiamato senza dati (create), non quando gli si passa l'array del
     * record da modificare (edit) — in quel caso i campi senza chiave in
     * quell'array vengono azzerati da fillStateWithNull(), ->default()
     * incluso. Serve quindi iniettare questi valori PRIMA, in
     * EditServiceReport::mutateFormDataBeforeFill().
     */
    public static function resolveLavaggioShortcutDefaults(?ServiceReport $record): array
    {
        $empty = [
            'chiamata_key' => null,
            'manodopera_key' => null,
            'lavaggio_base_key' => null,
            'lavaggio_ult_key' => null,
            'vie_count' => null,
        ];

        if (! $record) {
            return $empty;
        }

        $rows = $record->materialsUsed()->with('material')->get();

        // Riconosce sia i codici standard sia quelli dei paganti con listino
        // proprio: un rapportino con CHIMART deve accendere il toggle
        // "Chiamata" come uno con CHIORD. Solo lettura: le righe gia' salvate
        // non vengono mai riscritte.
        $chiamataRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('chiamata'), true));
        $manodoperaRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('manodopera'), true));
        $baseRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('lavaggio'), true));
        $ultRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('lavaggio_ulteriore_via'), true));

        return [
            // Prefisso "record-": il repeater ->relationship() tiene le righe
            // gia' persistite nello stato con chiave "record-{id}", non
            // l'id nudo (vedi Repeater::getCachedExistingRecords()) — senza
            // prefisso i toggle "spengono" solo in apparenza, l'unset() sulla
            // key sbagliata non trova mai la riga e questa resta in elenco.
            'chiamata_key' => $chiamataRow ? "record-{$chiamataRow->id}" : null,
            'manodopera_key' => $manodoperaRow ? "record-{$manodoperaRow->id}" : null,
            'lavaggio_base_key' => $baseRow ? "record-{$baseRow->id}" : null,
            'lavaggio_ult_key' => $ultRow ? "record-{$ultRow->id}" : null,
            // Vince sempre il numero digitato dal tecnico, ora che c'e' una
            // colonna che lo conserva: le righe materiali non bastano a
            // ricostruirlo, perche' 1 via e 2 vie generano entrambe il solo
            // LAV2 e il calcolo qui sotto rileggerebbe 2 anche dopo aver
            // scritto 1. Il calcolo resta come ripiego per i rapportini in
            // cui la colonna e' vuota (quelli salvati prima di questa
            // colonna e i 200+ importati da Eureka): li' l'unico dato
            // disponibile sono le righe, ULTVIA qty = vie - 2 al contrario,
            // e 2 e' quello che il codice materiale dichiara letteralmente.
            'vie_count' => $record->lavaggio_vie_count
                ?? ($baseRow ? 2 + (int) ($ultRow?->quantity ?? 0) : null),
        ];
    }

    /**
     * Le vie lavate si dichiarano impianto per impianto in cima al
     * rapportino ("Impianti e vie lavate"): quello e' il dato che il tecnico
     * ha davanti tornando dal lavoro, e da li' discende tutto il resto —
     * il lavaggio e' stato eseguito, e le righe tariffa LAV2/ULTVIA lo
     * devono dire. Senza questo, la stessa informazione andava ridigitata
     * piu' in basso in "Ricambi/materiali utilizzati" (toggle + numero vie),
     * e chi si fermava al repeater consegnava un rapportino senza le voci
     * da fatturare.
     *
     * Agisce solo quando almeno una riga ha le vie valorizzate: un repeater
     * vuoto, o con le righe appena aggiunte e ancora da compilare, non deve
     * spegnere un "Lavaggio eseguito" acceso a mano (capita sui clienti che
     * un piano lavaggio collegato non ce l'hanno).
     */
    public static function syncVieDaImpianti(Forms\Set $set, Forms\Get $get, string $su = ''): void
    {
        $totaleVie = collect($get($su.'lavaggio_impianti') ?? [])
            ->sum(fn (array $riga) => filled($riga['lines_washed'] ?? null) ? (int) $riga['lines_washed'] : 0);

        if ($totaleVie < 1) {
            return;
        }

        // Prima il toggle e il conteggio, poi le righe materiali:
        // syncLavaggioViaMaterials() rilegge entrambi da $get e con il
        // toggle ancora spento cancellerebbe le righe invece di scriverle.
        $set($su.'_lavaggio_vie_eseguito', true);
        $set($su.'lavaggio_vie_count', $totaleVie);

        self::syncLavaggioViaMaterials($set, $get, $su);
    }

    /**
     * Stesso meccanismo per key di add_chiamata_material/syncManodoperaMaterial
     * sopra: ricalcola da zero le righe generate da questo widget a ogni
     * cambio di toggle/numero vie, senza toccare righe uguali aggiunte a
     * mano (dedupe via $alreadyAdded, come altrove in questo file).
     */
    public static function syncLavaggioViaMaterials(Forms\Set $set, Forms\Get $get, string $su = ''): void
    {
        $materialsUsed = $get($su.'materialsUsed') ?? [];

        foreach (['_lavaggio_base_material_key', '_lavaggio_ult_material_key'] as $keyField) {
            $key = $get($su.$keyField);

            if ($key && array_key_exists($key, $materialsUsed)) {
                unset($materialsUsed[$key]);
            }
        }

        $eseguito = (bool) $get($su.'_lavaggio_vie_eseguito');
        $vieCount = (int) $get($su.'lavaggio_vie_count');

        if (! $eseguito || $vieCount < 1) {
            $set($su.'materialsUsed', $materialsUsed);
            $set($su.'_lavaggio_base_material_key', null);
            $set($su.'_lavaggio_ult_material_key', null);
            // Spegnere il toggle azzera anche il conteggio: il campo e' una
            // colonna vera, senza questo resterebbe in DB il numero di vie
            // del lavaggio appena tolto dal rapportino.
            $set($su.'lavaggio_vie_count', null);

            return;
        }

        $tariffe = TariffeIntervento::per(Customer::find($get($su.'customer_id')));
        $baseMaterial = Material::where('code', $tariffe['lavaggio'] ?? self::LAVAGGIO_VIE_BASE_MATERIAL_CODE)->first();
        $ultMaterial = Material::where('code', $tariffe['lavaggio_ulteriore_via'] ?? self::LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE)->first();

        $newBaseKey = null;
        $newUltKey = null;

        if ($baseMaterial) {
            $baseAlreadyPresent = collect($materialsUsed)->contains(
                fn (array $item) => ($item['material_id'] ?? null) === $baseMaterial->id
            );

            if (! $baseAlreadyPresent) {
                $newBaseKey = (string) Str::uuid();
                $materialsUsed[$newBaseKey] = ['material_id' => $baseMaterial->id, 'quantity' => 1];
            }
        }

        if ($ultMaterial && $vieCount > 2) {
            $ultAlreadyPresent = collect($materialsUsed)->contains(
                fn (array $item) => ($item['material_id'] ?? null) === $ultMaterial->id
            );

            if (! $ultAlreadyPresent) {
                $newUltKey = (string) Str::uuid();
                $materialsUsed[$newUltKey] = ['material_id' => $ultMaterial->id, 'quantity' => $vieCount - 2];
            }
        }

        $set($su.'materialsUsed', $materialsUsed);
        $set($su.'_lavaggio_base_material_key', $newBaseKey);
        $set($su.'_lavaggio_ult_material_key', $newUltKey);
    }

    /**
     * Tutti i codici che valgono come una certa voce: quello standard, la sua
     * variante festiva e le varianti dei paganti con listino proprio.
     *
     * @return array<int, string>
     */
    public static function codiciTariffa(string $voce): array
    {
        $standard = config('tariffe.standard');
        $codici = array_filter([
            $standard[$voce] ?? null,
            $standard[$voce.'_festiva'] ?? null,
            $voce === 'chiamata' ? ($standard['chiamata_venezia'] ?? null) : null,
        ]);

        foreach (config('tariffe.paganti') as $listino) {
            $codici[] = $listino[$voce] ?? null;
            $codici[] = $listino[$voce.'_festiva'] ?? null;
        }

        return array_values(array_unique(array_filter($codici)));
    }
}
