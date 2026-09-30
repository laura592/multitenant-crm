<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\InformationRequestNote;
use App\Models\Lavaggio;
use App\Models\LeaveRequest;
use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\MaterialOrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use App\Models\Quote;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\ServiceReportProduct;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Rende leggibile il contenuto di una riga di audit log.
 *
 * spatie/laravel-activitylog salva il diff in activity_log.attribute_changes
 * come `{"old": {...}, "attributes": {...}}`, con i nomi delle colonne del
 * database e i valori grezzi: una macchina spostata di pagante risultava
 * `billing_customer_id: 019f... → 01a0...`, cioe' illeggibile (e' successo
 * davvero il 30/09/2026, con l'audit che non sapeva rispondere a "chi ha
 * messo Il Filare come pagante?"). Qui le colonne diventano etichette
 * italiane e le chiavi esterne diventano il nome del record collegato.
 *
 * Attenzione: prima della v5 del package il diff stava in `properties`.
 * Quella colonna ora resta vuota, e le righe vecchie del CRM hanno comunque
 * tutte il diff in attribute_changes: non serve leggere entrambe.
 */
final class Modifiche
{
    /**
     * Colonne che non dicono niente a chi legge: valori tecnici, copie
     * denormalizzate o payload interi.
     */
    private const NASCOSTE = [
        'password',
        'remember_token',
        'search_name',
        'raw_payload',
        'customer_signature_path',
        'technician_signature_path',
        'gestionale_sync_error',
    ];

    /**
     * Colonne booleane: in database sono 0/1 o true/false, a schermo Si'/No.
     */
    private const BOOLEANE = [
        'is_super_admin',
        'is_active',
        'is_master',
        'in_pausa',
        'filtro_sostituito',
        'trasferta',
    ];

    /**
     * Chiave esterna -> modello da cui prendere il nome da mostrare.
     *
     * @var array<string, class-string<Model>>
     */
    private const RIFERIMENTI = [
        'tenant_id' => Tenant::class,
        'customer_id' => Customer::class,
        'billing_customer_id' => Customer::class,
        'current_customer_id' => Customer::class,
        'spostamento_suggerito_customer_id' => Customer::class,
        'user_id' => User::class,
        'technician_id' => User::class,
        'entered_by_user_id' => User::class,
        'approved_by_user_id' => User::class,
        'handled_by_user_id' => User::class,
        'supplier_id' => Supplier::class,
        'product_id' => Product::class,
        'machine_product_id' => Product::class,
        'material_id' => Material::class,
        'machine_material_id' => Material::class,
        'machine_unit_id' => MachineUnit::class,
        'fusa_in_id' => MachineUnit::class,
        'fusione_suggerita_id' => MachineUnit::class,
        'service_report_id' => ServiceReport::class,
        'last_service_report_id' => ServiceReport::class,
        'last_filter_change_id' => ServiceReport::class,
        'duplicato_suggerito_id' => ServiceReport::class,
        'maintenance_schedule_id' => MaintenanceSchedule::class,
        'last_lavaggio_id' => Lavaggio::class,
        'material_order_id' => MaterialOrder::class,
        'quote_id' => Quote::class,
        'quote_group_id' => \App\Models\QuoteGroup::class,
        'parent_quote_product_id' => \App\Models\QuoteProduct::class,
        'assigned_user_id' => User::class,
        'caricato_da' => User::class,
        'information_request_id' => InformationRequest::class,
        'category_id' => Category::class,
        'brand_id' => Brand::class,
        'product_family_id' => ProductFamily::class,
    ];

    /**
     * Etichette valide per tutti i modelli tracciati.
     *
     * @var array<string, string>
     */
    private const ETICHETTE = [
        'tenant_id' => 'Partner',
        'customer_id' => 'Cliente',
        'billing_customer_id' => 'Chi paga',
        'current_customer_id' => 'Cliente attuale',
        'user_id' => 'Utente',
        'technician_id' => 'Tecnico',
        'entered_by_user_id' => 'Inserita da',
        'approved_by_user_id' => 'Approvata da',
        'approved_at' => 'Approvata il',
        'handled_by_user_id' => 'Gestita da',
        'supplier_id' => 'Fornitore',
        'product_id' => 'Prodotto',
        'material_id' => 'Materiale',
        'machine_unit_id' => 'Macchina',
        'service_report_id' => 'Rapportino',
        'maintenance_schedule_id' => 'Piano manutenzione',
        'last_service_report_id' => 'Ultimo rapportino',
        'last_lavaggio_id' => 'Ultimo lavaggio',
        'last_filter_change_id' => 'Ultimo cambio filtro',
        'visita_id' => 'Visita',
        'fusa_in_id' => 'Fusa nella macchina',
        'fusione_suggerita_id' => 'Fusione proposta con',
        'duplicato_suggerito_id' => 'Doppione proposto',
        'machine_product_id' => 'Macchina (prodotto)',
        'machine_material_id' => 'Macchina (materiale)',
        'machine_serial_number' => 'Matricola scritta sopra',
        'spostamento_suggerito_customer_id' => 'Spostamento proposto verso',
        'spostamento_suggerito_il' => 'Spostamento proposto dal',
        'spostamento_suggerito_motivo' => 'Perché si propone lo spostamento',
        'spostamento_suggerito_pagante_code' => 'Pagante proposto (codice Eureka)',
        'spostamento_scartato' => 'Spostamento scartato',
        'fusione_suggerita_motivo' => 'Perché sembra la stessa macchina',
        'duplicato_suggerito_motivo' => 'Perché sembra un doppione',
        'material_order_id' => 'Ordine',
        'quote_id' => 'Preventivo',
        'information_request_id' => 'Richiesta informazioni',
        'category_id' => 'Categoria',
        'brand_id' => 'Marca',
        'product_family_id' => 'Famiglia',
        'quote_group_id' => 'Gruppo di preventivi',
        'parent_quote_product_id' => 'Riga principale',
        'assigned_user_id' => 'Assegnato a',
        'caricato_da' => 'Caricato da',
        'discount' => 'Sconto',
        'extra_discount' => 'Sconto extra',
        'tax' => 'IVA',
        'tax_total' => 'Totale IVA',
        'subtotal' => 'Imponibile',
        'total' => 'Totale',
        'payment_method' => 'Pagamento',
        'rental_monthly_fee' => 'Canone mensile',
        'rental_months' => 'Mesi di noleggio',
        'sent_at' => 'Inviato il',
        'due_date' => 'Scadenza',
        'reminder_days_before' => 'Promemoria (giorni prima)',
        'policy_number' => 'Numero polizza',
        'plate' => 'Targa',
        'brand' => 'Marca',
        'model' => 'Modello',
        'year' => 'Anno',
        'file_path' => 'File',
        'path' => 'File',
        'mime' => 'Tipo di file',
        'dimensione' => 'Dimensione',
        'titolo' => 'Titolo',
        'tipo' => 'Tipo',
        'note' => 'Note',
        'scade_il' => 'Scade il',
        'stato' => 'Stato',
        'momento' => 'Momento',
        'fatto_il' => 'Fatto il',
        'contratto_assistenza' => 'Contratto di assistenza',
        'name' => 'Nome',
        'number' => 'Numero',
        'notes' => 'Note',
        'status' => 'Stato',
        'type' => 'Tipo',
        'source' => 'Origine',
        'email' => 'Email',
        'emails' => 'Email',
        'phone' => 'Telefono',
        'phones' => 'Telefoni',
        'fax' => 'Fax',
        'pec' => 'PEC',
        'sdi' => 'Codice SDI',
        'iban' => 'IBAN',
        'street' => 'Indirizzo',
        'address' => 'Indirizzo',
        'postal_code' => 'CAP',
        'city' => 'Città',
        'province' => 'Provincia',
        'latitude' => 'Latitudine',
        'longitude' => 'Longitudine',
        'website' => 'Sito web',
        'website_checked_at' => 'Sito controllato il',
        'vat_number' => 'Partita IVA',
        'tax_code' => 'Codice fiscale',
        'legal_name' => 'Ragione sociale (legale)',
        'company_name' => 'Ragione sociale',
        'first_name' => 'Nome',
        'last_name' => 'Cognome',
        'code' => 'Codice',
        'sku' => 'Codice articolo',
        'description' => 'Descrizione',
        'descrizione' => 'Descrizione',
        'image' => 'Immagine',
        'price' => 'Prezzo',
        'list_price' => 'Prezzo di listino',
        'quantity' => 'Quantità',
        'unit_cost_snapshot' => 'Prezzo unitario',
        'line_total_snapshot' => 'Totale riga',
        'valid_from' => 'Valido dal',
        'valid_to' => 'Valido al',
        'data' => 'Data',
        'serial_number' => 'Matricola',
        'model_name' => 'Modello',
        'maintenance_code' => 'Codice manutenzione',
        'gestionale_code' => 'Codice gestionale',
        'gestionale_number' => 'Numero su Eureka',
        'gestionale_suggested_code' => 'Codice proposto dal gestionale',
        'gestionale_suggested_label' => 'Nome proposto dal gestionale',
        'gestionale_review_note' => 'Nota di revisione gestionale',
        'gestionale_review_flagged_at' => 'Segnalata al gestionale il',
        'gestionale_document_date' => 'Data documento Eureka',
        'gestionale_scheda_lavoro_id' => 'Scheda lavoro Eureka',
        'gestionale_sync_status' => 'Stato invio a Eureka',
        'gestionale_synced_at' => 'Inviato a Eureka il',
        'approved_for_gestionale_at' => 'Approvato per il gestionale il',
        'sent_to_gestionale_at' => 'Inviato al gestionale il',
        'eureka_note' => 'Note da Eureka',
        'eureka_article_id' => 'Articolo Eureka',
        'eureka_service_report_id' => 'Scheda lavoro Eureka',
        'eureka_destinazione_code' => 'Codice destinazione Eureka',
        'eureka_destinazione_label' => 'Destinazione Eureka',
        'eureka_stato_documento' => 'Stato documento Eureka',
        'eureka_stato_label' => 'Stato Eureka',
        'eureka_billing_customer_code' => 'Codice pagante Eureka',
        'consent_privacy_at' => 'Consenso privacy il',
        'consent_marketing_at' => 'Consenso marketing il',
        'consent_source' => 'Origine del consenso',
        'regime_iva' => 'Regime IVA',
        'esenzione_articolo' => 'Articolo di esenzione',
        'is_active' => 'Attivo',
        'is_super_admin' => 'Staff master',
        'is_master' => 'Partner master',
        'slug' => 'Slug',
        'logo_path' => 'Logo',
        'primary_color' => 'Colore principale',
    ];

    /**
     * Etichette che valgono solo per un modello, dove il nome della colonna
     * da solo sarebbe ambiguo.
     *
     * @var array<class-string, array<string, string>>
     */
    private const ETICHETTE_PER_MODELLO = [
        ServiceReport::class => [
            'source' => 'Da dove arriva',
            'status' => 'Stato del rapportino',
            'machine_product_id' => 'Macchina (prodotto)',
            'machine_material_id' => 'Macchina (materiale)',
            'machine_serial_number' => 'Matricola scritta sul rapportino',
            'intervention_type' => 'Tipo di intervento',
            'intervention_date' => 'Data intervento',
            'arrival_at' => 'Ora di arrivo',
            'departure_at' => 'Ora di partenza',
            'problem_description' => 'Problema riscontrato',
            'work_performed' => 'Lavoro svolto',
            'lavaggio_vie_count' => 'Vie lavate',
            'signed_at' => 'Firmato il',
            'customer_signature_name' => 'Chi ha firmato',
            'visita_id' => 'Visita',
            'duplicato_suggerito_motivo' => 'Perché sembra un doppione',
        ],
        MachineUnit::class => [
            'type' => 'Tipo di macchina',
            'status' => 'Stato della macchina',
            'fusione_suggerita_motivo' => 'Perché sembra la stessa macchina',
            'spostamento_suggerito_customer_id' => 'Spostamento proposto verso',
            'spostamento_suggerito_il' => 'Spostamento proposto dal',
            'spostamento_suggerito_motivo' => 'Perché si propone lo spostamento',
            'spostamento_suggerito_pagante_code' => 'Pagante proposto (codice Eureka)',
            'spostamento_scartato' => 'Spostamento scartato',
        ],
        MaintenanceSchedule::class => [
            'type' => 'Tipo di piano',
            'beverage_type' => 'Bevanda',
            'lines_count' => 'Numero di vie',
            'frequency' => 'Frequenza',
            'frequency_days' => 'Ogni quanti giorni',
            'filter_validity_days' => 'Durata filtro (giorni)',
            'next_due_date' => 'Prossima scadenza',
            'in_pausa' => 'In pausa',
            'in_pausa_fino_al' => 'In pausa fino al',
            'pausa_motivo' => 'Motivo della pausa',
        ],
        Lavaggio::class => [
            'lines_washed' => 'Vie lavate',
            'filtro_sostituito' => 'Filtro sostituito',
        ],
        User::class => [
            'daily_contract_hours' => 'Ore al giorno da contratto',
            'weekly_contract_hours' => 'Ore a settimana da contratto',
            'annual_leave_days' => 'Giorni di ferie all\'anno',
            'default_morning_in' => 'Entrata mattino',
            'default_morning_out' => 'Uscita mattino',
            'default_afternoon_in' => 'Entrata pomeriggio',
            'default_afternoon_out' => 'Uscita pomeriggio',
        ],
        TimeEntry::class => [
            'clock_in' => 'Entrata',
            'clock_out' => 'Uscita',
            'status' => 'Stato della timbratura',
            'destinazione_trasferta' => 'Destinazione trasferta',
        ],
        LeaveRequest::class => [
            'type' => 'Tipo di assenza',
            'date_from' => 'Dal',
            'date_to' => 'Al',
            'time_from' => 'Dalle',
            'time_to' => 'Alle',
            'hours' => 'Ore',
            'requested_at' => 'Richiesta il',
        ],
        InformationRequest::class => [
            'request_details' => 'Testo della richiesta',
            'appointment_at' => 'Appuntamento',
            'appointment_notes' => 'Note appuntamento',
            'origin_url' => 'Pagina di provenienza',
            'external_id' => 'ID esterno',
        ],
        MachineUnitPlacement::class => [
            'placed_at' => 'Collocata il',
            'removed_at' => 'Rimossa il',
        ],
        InformationRequestNote::class => [
            'logged_at' => 'Annotata il',
            'body' => 'Testo',
        ],
        Material::class => [
            'type' => 'Tipo di materiale',
            'variant' => 'Variante',
            'tube_diameter' => 'Diametro tubo',
            'tube_diameter_2' => 'Diametro tubo 2',
            'thread_size' => 'Misura filetto',
            'thread_type' => 'Tipo di filetto',
            'barb_diameter' => 'Diametro portagomma',
            'category' => 'Categoria',
        ],
        Product::class => [
            'type' => 'Tipo di prodotto',
        ],
        Quote::class => [
            'date' => 'Data del preventivo',
            'status' => 'Stato del preventivo',
            'notes' => 'Note del preventivo',
        ],
        \App\Models\Deadline::class => [
            'type' => 'Tipo di scadenza',
            'deadlinable_type' => 'Riferita a (tipo)',
            'deadlinable_id' => 'Riferita a',
        ],
        \App\Models\PriceList::class => [
            'category' => 'Categoria del listino',
            'name' => 'Nome del listino',
        ],
    ];

    /** @var array<string, ?string> cache dei nomi gia' risolti in questa richiesta */
    private static array $nomi = [];

    /**
     * Le modifiche di una riga, pronte da mostrare.
     *
     * @return array<int, array{campo: string, prima: string, dopo: string}>
     */
    public static function righe(AuditLog $log): array
    {
        $cambi = $log->attribute_changes;

        if (! $cambi) {
            return [];
        }

        $dopo = (array) ($cambi->get('attributes') ?? []);
        $prima = (array) ($cambi->get('old') ?? []);

        $modello = $log->subject_type ?? '';
        $righe = [];

        foreach (array_keys($dopo + $prima) as $campo) {
            if (in_array($campo, self::NASCOSTE, true)) {
                continue;
            }

            $valorePrima = $prima[$campo] ?? null;
            $valoreDopo = $dopo[$campo] ?? null;

            // Creazione ed eliminazione hanno un solo valore: le colonne
            // mai valorizzate sarebbero trenta righe di "—" che non dicono
            // niente su cosa e' stato creato o tolto.
            $unico = match ($log->event) {
                'created', 'restored' => $valoreDopo,
                'deleted' => $valorePrima,
                default => '.',
            };

            if ($unico === null || $unico === '' || $unico === []) {
                continue;
            }

            $righe[] = [
                'campo' => self::etichetta($campo, $modello),
                'prima' => self::valore($campo, $valorePrima),
                'dopo' => self::valore($campo, $valoreDopo),
            ];
        }

        usort($righe, fn (array $a, array $b) => strcmp($a['campo'], $b['campo']));

        return $righe;
    }

    /**
     * I soli nomi dei campi toccati: serve in elenco, dove risolvere anche i
     * valori significherebbe una query per riga.
     *
     * @return array<int, string>
     */
    public static function campiCambiati(AuditLog $log): array
    {
        $cambi = $log->attribute_changes;

        if (! $cambi) {
            return [];
        }

        $campi = array_keys(((array) ($cambi->get('attributes') ?? [])) + ((array) ($cambi->get('old') ?? [])));
        $modello = $log->subject_type ?? '';

        $campi = array_values(array_filter($campi, fn (string $c) => ! in_array($c, self::NASCOSTE, true)));

        $etichette = array_map(fn (string $c) => self::etichetta($c, $modello), $campi);
        sort($etichette);

        return $etichette;
    }

    /**
     * Riassunto da elenco: "Chi paga, Note" oppure "Chi paga e altri 4".
     */
    public static function riassunto(AuditLog $log, int $max = 3): string
    {
        // Solo le modifiche hanno campi da elencare: in creazione e in
        // cancellazione l'elenco e' tutta l'anagrafica, e non aggiunge niente
        // a "creato/eliminato questo record".
        if ($log->event !== 'updated') {
            return '';
        }

        $etichette = self::campiCambiati($log);

        if ($etichette === []) {
            return '';
        }

        if (count($etichette) <= $max) {
            return implode(', ', $etichette);
        }

        $resto = count($etichette) - $max;

        return implode(', ', array_slice($etichette, 0, $max))." e altri {$resto}";
    }

    /**
     * Quale record e' stato toccato, in parole ("Bar Romeo", "RT-2026-0861").
     * Se il record e' stato cancellato davvero resta l'ID.
     */
    public static function soggetto(AuditLog $log): string
    {
        if (! $log->subject_type) {
            return '—';
        }

        $subject = $log->subject;

        if ($subject instanceof Model) {
            return self::etichettaRecord($subject);
        }

        // Cancellato: il nome vive ancora nel diff della riga stessa.
        $cambi = $log->attribute_changes;
        $vecchi = (array) ($cambi?->get('old') ?? $cambi?->get('attributes') ?? []);

        foreach (['number', 'company_name', 'name', 'serial_number', 'code', 'sku'] as $campo) {
            if (filled($vecchi[$campo] ?? null)) {
                return (string) $vecchi[$campo];
            }
        }

        // Una riga figlia non ha un nome suo: si chiama come la cosa a cui
        // si riferisce ("LAVAGGIO 2 VIE su RT-2026-0844").
        $parti = [];

        foreach (['material_id', 'product_id', 'machine_unit_id', 'customer_id', 'service_report_id'] as $campo) {
            if (filled($vecchi[$campo] ?? null)) {
                $parti[] = self::nomeCollegato(self::RIFERIMENTI[$campo], (string) $vecchi[$campo]);
            }
        }

        if ($parti !== []) {
            return count($parti) > 1
                ? array_shift($parti).' su '.implode(', ', $parti)
                : $parti[0];
        }

        return $log->subject_id ? Str::limit((string) $log->subject_id, 12) : '—';
    }

    public static function etichetta(string $campo, string $modello): string
    {
        if (isset(self::ETICHETTE_PER_MODELLO[$modello][$campo])) {
            return self::ETICHETTE_PER_MODELLO[$modello][$campo];
        }

        if (isset(self::ETICHETTE[$campo])) {
            return self::ETICHETTE[$campo];
        }

        // Il tenant ha una quindicina di notify_*_emails: elencarle a mano
        // vorrebbe dire aggiornare questo file a ogni nuovo avviso.
        if (str_starts_with($campo, 'notify_') && str_ends_with($campo, '_emails')) {
            return 'Avvisi via email: '.str_replace('_', ' ', substr($campo, 7, -7));
        }

        return Str::of($campo)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Un valore grezzo del diff, reso leggibile.
     */
    public static function valore(string $campo, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }

        if (isset(self::RIFERIMENTI[$campo])) {
            return self::nomeCollegato(self::RIFERIMENTI[$campo], (string) $v);
        }

        if (in_array($campo, self::BOOLEANE, true) || is_bool($v)) {
            return filter_var($v, FILTER_VALIDATE_BOOL) ? 'Sì' : 'No';
        }

        if (is_array($v)) {
            return implode(', ', array_map(
                fn ($riga) => is_array($riga) ? implode(' ', array_filter($riga, 'is_scalar')) : (string) $riga,
                $v
            ));
        }

        if (is_string($v) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $v)) {
            // I valori con la Z finale sono in UTC (li serializza cosi'
            // Eloquent): letti com'erano, una scadenza del 19/09 diventava
            // "18/09 22:00".
            $data = Carbon::parse($v)->setTimezone(config('app.timezone'));

            return $data->format('H:i:s') === '00:00:00'
                ? $data->format('d/m/Y')
                : $data->format('d/m/Y H:i');
        }

        return Str::limit((string) $v, 300);
    }

    /**
     * @param  class-string<Model>  $classe
     */
    private static function nomeCollegato(string $classe, string $id): string
    {
        $chiave = $classe.':'.$id;

        if (! array_key_exists($chiave, self::$nomi)) {
            $query = $classe::query();

            // Il record collegato puo' essere stato cestinato: il nome serve
            // lo stesso, altrimenti nell'audit resta un UUID.
            if (in_array(SoftDeletes::class, class_uses_recursive($classe), true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $record = $query->find($id);

            self::$nomi[$chiave] = $record instanceof Model ? self::etichettaRecord($record) : null;
        }

        return self::$nomi[$chiave] ?? Str::limit($id, 12);
    }

    /**
     * Come si chiama un record, qualunque modello sia.
     */
    public static function etichettaRecord(Model $record): string
    {
        if ($record instanceof Customer) {
            return $record->full_name ?: 'Cliente senza nome';
        }

        if ($record instanceof MachineUnit) {
            return trim($record->display_name.' '.($record->serial_number ? "({$record->serial_number})" : ''));
        }

        if ($record instanceof Material) {
            return $record->display_label;
        }

        if ($record instanceof ServiceReport) {
            return $record->number ?: 'Rapportino';
        }

        if ($record instanceof Lavaggio) {
            return 'Lavaggio del '.$record->data->format('d/m/Y');
        }

        if ($record instanceof MaintenanceSchedule) {
            return trim(ucfirst((string) $record->type).' '.(self::nomeDi($record->customer) ?? ''));
        }

        if ($record instanceof ServiceReportMaterial) {
            return (self::nomeDi($record->material) ?? 'Materiale').' su '.(self::nomeDi($record->serviceReport) ?? '?');
        }

        if ($record instanceof ServiceReportProduct) {
            return (self::nomeDi($record->product) ?? 'Ricambio').' su '.(self::nomeDi($record->serviceReport) ?? '?');
        }

        if ($record instanceof MaterialOrderItem) {
            return (self::nomeDi($record->material) ?? 'Materiale').' su '.(self::nomeDi($record->order) ?? '?');
        }

        if ($record instanceof MachineUnitPlacement) {
            return (self::nomeDi($record->machineUnit) ?? 'Macchina').' presso '.(self::nomeDi($record->customer) ?? '?');
        }

        if ($record instanceof TimeEntry) {
            return trim((self::nomeDi($record->user) ?? 'Utente').' '.$record->clock_in->format('d/m/Y H:i'));
        }

        if ($record instanceof LeaveRequest) {
            return trim(ucfirst((string) $record->type).' '.(self::nomeDi($record->user) ?? '').' '.$record->periodLabel());
        }

        if ($record instanceof ProductPrice) {
            return (self::nomeDi($record->product) ?? 'Prodotto').' '.number_format((float) $record->price, 2, ',', '.').' EUR';
        }

        if ($record instanceof \App\Models\QuoteProduct) {
            return (self::nomeDi($record->product) ?? 'Riga').' su '.(self::nomeDi($record->quote) ?? '?');
        }

        if ($record instanceof \App\Models\Vehicle) {
            return trim($record->plate.' '.$record->brand.' '.$record->model);
        }

        if ($record instanceof \App\Models\CustomerDocument) {
            return $record->titolo.' di '.(self::nomeDi($record->customer) ?? '?');
        }

        if ($record instanceof \App\Models\Deadline) {
            return ucfirst((string) $record->type).' '.(self::nomeDi($record->deadlinable) ?? '');
        }

        if ($record instanceof InformationRequestNote) {
            return 'Nota su '.(self::nomeDi($record->informationRequest) ?? '?');
        }

        foreach (['number', 'name', 'company_name', 'serial_number', 'code'] as $campo) {
            $valore = $record->getAttribute($campo);

            if (filled($valore)) {
                return (string) $valore;
            }
        }

        return (string) $record->getKey();
    }

    /**
     * Il nome di un record collegato, o null se la relazione e' vuota. Passa
     * dalla stessa etichettaRecord, cosi' una riga materiale eredita il nome
     * del materiale senza doverlo riscrivere.
     */
    private static function nomeDi(mixed $record): ?string
    {
        return $record instanceof Model ? self::etichettaRecord($record) : null;
    }
}
