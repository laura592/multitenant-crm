# Preventivi VG → piattaforma multi-tenant per rivenditori Franke

Documento tecnico di analisi e proposta architetturale. Versione 1.0 — 2026-07-09.

## 1. Contesto commerciale (dal contratto Alex/GIFAR)

Il contratto `Contratto_Distribuzione_Franke_Gifar` definisce il modello che la piattaforma deve
supportare quando si apre ad altri partner:

- **Alex S.r.l.** è il Distributore Primario Franke ("tenant master"). **GIFAR** è il primo di
  potenzialmente N **Partner/Rivenditori** ("tenant").
- Ogni Partner acquista le macchine da Alex con **sconto fisso 30%** sul listino (art. 3.1) ed è
  **libero sul prezzo di rivendita** al cliente finale (art. 3.2).
- Tre scenari operativi con economics diverse (art. 4):
  - **A — Segnalazione**: il Partner segnala un cliente, Alex fa tutto il resto. Provvigione 10%
    sul valore macchina+accessori (non sui servizi), fatturata dal Partner ad Alex entro 15gg,
    pagata entro 30gg.
  - **B — Partner procura il cliente, installazione a cura di Alex**: il Partner fattura la
    macchina al cliente finale e paga ad Alex un fisso di **1.500€** per l'installazione.
  - **C — Partner installa in autonomia**: il Partner fattura la macchina al cliente finale;
    se Alex fa la sola preinstallazione, il Partner paga **500€**; se non la fa, nessun addebito.
    Il Partner risponde in proprio dei danni da installazione (deve avere polizza RCT, art. 17).
- **Approvvigionamento esclusivo** (art. 11): il Partner può comprare ricambi/accessori Franke
  *solo* da Alex, pena penale pari al doppio del valore acquistato altrove — quindi il catalogo
  ricambi/accessori "ufficiale" deve restare centralizzato e comune a tutti i tenant.
- **Esclusiva territoriale opzionale** (art. 12), **durata 3 anni con rinnovo tacito e preavviso
  90gg** (art. 13), **riservatezza** su prezzi/dati (art. 14), **GDPR** sui dati clienti scambiati
  tra le parti (art. 18).

**Lettura architetturale**: questo non è un SaaS anonimo con self-signup. È un'estensione
controllata del canale di vendita di Alex a un numero ristretto di partner con cui esiste un
contratto commerciale specifico. Alex deve poter vedere e amministrare tutto (catalogo, tenant,
provvigioni); ogni Partner deve vedere solo i propri clienti/preventivi, più il catalogo condiviso.
Questo orienta fortemente la scelta tecnica al punto 3.

## 2. Stato attuale dell'applicazione

Laravel 12 + Filament 3, single-tenant, single panel `/admin` (`AdminPanelProvider`).

Modelli principali:

| Modello | Ruolo |
|---|---|
| `Category` → `Product` | catalogo, `Product.type` = `base` (macchina) / `option` (accessorio) |
| `ProductPrice` | storico prezzi con validità temporale (`valid_from`/`valid_to`) |
| `ProductCompatibility` | compatibilità macchina↔opzione, raggruppate per `option_group` |
| `Customer` | anagrafica cliente (con P.IVA/CF/SDI per fatturazione) |
| `Quote` → `QuoteProduct` | preventivo, righe con gerarchia base/opzione (`parent_quote_product_id`), calcolo imponibile/IVA in `Quote::updateTotal()` |
| `QuoteEmail` | log invio email preventivo |
| `InformationRequest` | richieste informazioni/lead, numerazione automatica `RI-YYYY-NNNN` |
| `PaymentMethod` | metodi di pagamento |
| `ComodatoMacchina` | simulatore TCO/comodato d'uso (calcolo costo per erogazione), non collegato ai preventivi |
| `User` | ruolo semplice enum `admin`/`user`, `canAccessPanel()` sempre `true` |

Note rilevanti per il redesign:

- **Autorizzazioni**: `bezhansalleh/filament-shield` e `spatie/laravel-permission` sono nel
  `composer.lock` ma **non configurati** — le Policy sono tutte `AdminOnlyPolicy` (un trait che
  controlla solo `$user->isAdmin()`). Andrà attivato lo Shield vero con ruoli/permessi granulari,
  utile anche per differenziare ruoli *dentro* ogni tenant (es. Titolare vs Tecnico del Partner).
- **Nessuna nozione di tenant** oggi: nessuna tabella, nessuna colonna, nessuno scope.
- Il catalogo (`Category`/`Product`/`ProductPrice`/`ProductCompatibility`) è già ben separato
  dalle entità operative (`Customer`/`Quote`) — è la base ideale su cui innestare
  "condiviso vs specifico per tenant".

## 3. Decisione architetturale: niente stancl/tenancy, sì a Filament multi-tenancy nativa

Hai chiesto di usare "Laravel Tenancy" (il pacchetto più noto con questo nome è
`stancl/tenancy`) e "solo componenti Filament". Le due cose, applicate insieme al tuo caso
d'uso, sono in tensione — ed è un punto su cui vale la pena fermarsi prima di scrivere codice.

**`stancl/tenancy`** risolve l'isolamento a livello di infrastruttura: il suo modello nativo e
più maturo è **un database (o schema) per tenant**. Ottimo quando i tenant sono entità
indipendenti che non devono mai condividere dati. Ma il tuo requisito esplicito è l'opposto su un
pezzo importante del dominio: *"prodotti comuni a più tenant e alcuni solo del tenant master"* —
cioè hai bisogno di **condivisione controllata di dati fra tenant**, non di isolamento totale.
Con un DB per tenant, il catalogo condiviso (il listino Franke che per contratto Alex controlla
centralmente, art. 11) andrebbe duplicato/sincronizzato in ogni database, oppure letto da una
connessione "centrale" con join cross-database — possibile con stancl (pattern "central
resources"), ma è complessità aggiuntiva per un problema che a schema unico non esiste.

**Filament ha una propria feature di multi-tenancy nativa** (`Panel::tenant()`,
`HasTenants`/`HasTenant`, tenant switcher in sidebar, tenant-aware resource query): è pensata
esattamente per SaaS B2B a **database condiviso, schema condiviso**, con lo scoping fatto via
colonna `tenant_id` + global scope. Copre il 90% del bisogno "un utente Partner vede solo i suoi
dati" senza aggiungere nessun pacchetto oltre a Filament stesso — che è letteralmente quello che
hai chiesto ("solo componenti Filament").

**Raccomandazione**: database unico, schema condiviso, riga-per-tenant (`tenant_id`), tenancy
gestita con le API native di Filament + Eloquent global scope custom. Non introdurre
`stancl/tenancy`. Tienilo in tasca come opzione futura *solo* se un domani un partner grande
chiederà isolamento infrastrutturale forte (residenza dati separata, backup dedicati, dominio
proprio con DB proprio) — a quel punto si valuta una migrazione mirata per quel singolo tenant,
non un redesign globale.

| | Filament tenancy + `tenant_id` (proposta) | `stancl/tenancy` (DB-per-tenant) |
|---|---|---|
| Catalogo condiviso tra tenant | Nativo: query con `WHERE tenant_id = ? OR tenant_id IS NULL` | Richiede sync o connessione centrale cross-DB |
| Reportistica cross-tenant per Alex (provvigioni, dashboard globale) | Una query, niente aggregazione multi-DB | Complessa: query su N database |
| Onboarding nuovo partner | Insert riga `tenants` | Provisioning DB/schema, migration runner |
| Isolamento dati | Logico (scope + policy), adeguato per un numero contenuto di partner fidati e contrattualizzati | Fisico, più forte ma sovradimensionato qui |
| Pacchetti extra | Nessuno oltre a Filament | `stancl/tenancy` + gestione DDL dinamica |
| Coerenza con "solo Filament" richiesto | Sì | No |

Se in questa fase preferisci comunque `stancl/tenancy` per policy aziendale/di sicurezza, dimmelo:
il resto del documento (modello dati, moduli, provvigioni) resta valido, cambia solo il livello di
infrastruttura sotto la colonna `tenant_id` (che diventerebbe l'identificatore per la connessione
invece che un filtro `WHERE`).

> **Decisione presa (2026-07-09)**: la richiesta di "personalizzazione per logo ecc." riguarda solo
> il branding visivo (logo/colori nel pannello e nei PDF/email per tenant), non l'isolamento dati.
> Il branding è già coperto dal modello a schema condiviso (§6, colonne `logo_path`/`primary_color`
> su `tenants`) e non richiede `stancl/tenancy` in nessun caso: anche a DB-per-tenant il branding si
> otterrebbe leggendo le stesse colonne dalla riga del tenant corrente — il pacchetto non aggiunge
> nulla su questo fronte. Confermato di procedere **senza `stancl/tenancy`**, schema condiviso con
> `tenant_id`. Se in futuro servirà un dominio proprio per partner (white-label, es.
> `preventivi.gifar.it`), si aggiunge una colonna `custom_domain` su `tenants` + un middleware di
> risoluzione tenant da host — resta comunque su DB unico, nessun pacchetto aggiuntivo necessario.

## 4. Modello dati proposto

### 4.1 Nuove tabelle

**`tenants`** — anagrafica azienda partner (Alex stessa è una riga con `is_master = true`)

```
id, name, legal_name, vat_number, tax_code, email, phone,
street, postal_code, city, province,
slug (usato nell'URL /admin/{slug}/...),
is_master (bool), is_active (bool),
logo_path, primary_color,               -- branding per PDF/email/panel
-- condizioni contrattuali (da art. 3, 4, 11, 12, 13 del contratto tipo)
machine_discount_percent (default 30.00),
default_commission_scenario (enum: A, B, C),
scenario_a_commission_percent (default 10.00),
scenario_b_installation_fee (default 1500.00),
scenario_c_preinstallation_fee (default 500.00),
exclusive_supply_required (bool, default true),
territory_exclusive (bool), territory_notes (text nullable),
contract_start_date, contract_duration_months (default 36),
notice_period_days (default 90),
-- canone piattaforma (opzionale, attivabile per singolo tenant)
saas_billing_enabled (bool, default false),
saas_plan_fee (decimal nullable),
saas_billing_cycle (enum: monthly, annual, nullable),
created_at, updated_at
```

**`users`** (alter): aggiungere `tenant_id` **nullable** (FK) e `is_super_admin` (bool, default
false). Un utente appartiene sempre a **un solo tenant** (o a nessuno, se `is_super_admin`, cioè
staff Alex con accesso cross-tenant). Niente pivot many-to-many: un dipendente lavora per una sola
azienda, semplificando ruoli e permessi. Lo staff Alex, essendo `is_super_admin`, bypassa lo scope
e vede/gestisce tutti i tenant senza bisogno di essere "membro" di ciascuno.

### 4.4 Canone SaaS (opzionale, per-tenant)

Il canone non è obbligatorio per contratto (GIFAR ad esempio ne è escluso, essendo remunerato
Alex solo dalle provvigioni) ma va previsto come funzionalità attivabile caso per caso: i campi
`saas_billing_enabled`/`saas_plan_fee`/`saas_billing_cycle` su `tenants` (sopra) permettono di
decidere per ogni partner se e quanto fargli pagare l'uso della piattaforma, indipendentemente
dal modulo provvigioni (§4.3), che resta legato al contratto di distribuzione.

**`tenant_subscription_invoices`** — storico fatture canone (generate solo se
`saas_billing_enabled = true`)

```
id, tenant_id, period_start, period_end, amount,
status (enum: da_fatturare, fatturata, pagata),
invoiced_at, due_at, paid_at, created_at, updated_at
```

Una Command/Job schedulato (es. mensile) genera la riga per i tenant con billing attivo; il resto
(fatturazione elettronica reale, gateway di pagamento) è fuori scope di questa fase — si parte con
tracciamento manuale dello stato, integrazione Stripe/altro solo se e quando serve davvero.

### 4.2 Tabelle esistenti da alterare

| Tabella | Modifica | Motivo |
|---|---|---|
| `categories` | + `tenant_id` **nullable** | quasi sempre globali; permette a un partner di creare una categoria propria se serve |
| `products` | + `tenant_id` **nullable** | `NULL` = catalogo condiviso (listino Franke ufficiale); valorizzato a **id del tenant master** = prodotto riservato ad Alex; valorizzato a un partner = prodotto privato di quel partner (es. accessori non-Franke che rivende) |
| `product_prices` | nessuna (segue `product_id`) | il prezzo eredita lo scope dal prodotto |
| `customers` | + `tenant_id` **NOT NULL** | un cliente appartiene a un solo partner (o ad Alex) |
| `quotes` | + `tenant_id` **NOT NULL**, + campi provvigione (sotto) | ogni preventivo è di un tenant |
| `information_requests` | + `tenant_id` **NOT NULL** | i lead vanno instradati al partner competente |
| `comodato_macchine` | + `tenant_id` **NOT NULL** | simulazioni TCO per cliente del partner |
| `payment_methods` | invariata (globale) | tassonomia condivisa, non sensibile |

### 4.3 Modulo provvigioni (nuovo, mappa direttamente gli scenari contrattuali A/B/C)

Aggiunta a `quotes`:

```
commission_scenario (enum A, B, C, nullable)   -- valorizzato solo se il preventivo è di un partner
commission_rate_snapshot (decimal, nullable)   -- % o importo fisso "congelato" al momento della vendita
commission_amount (decimal, nullable)          -- calcolato da Quote::updateTotal()
commission_direction (enum: partner_to_master, master_to_partner)  -- A: partner fattura ad Alex; B/C: Alex fattura al partner
commission_status (enum: da_fatturare, fatturata, pagata)
commission_invoice_number (nullable)
commission_invoiced_at, commission_due_at, commission_paid_at (date, nullable)
```

Perché in `quotes` e non in una tabella a parte: la provvigione è 1:1 col preventivo/vendita
(art. 4 la calcola "nell'ambito della trattativa"), quindi non serve una entità separata — un
`QuoteObserver` calcola questi campi leggendo `tenant.default_commission_scenario` e le relative
percentuali/importi al salvataggio, così come `updateTotal()` già fa per subtotal/tax/total.

Questo abilita, gratis, una vista "Provvigioni da Fatturare/Incassare per Partner" per Alex —
esattamente il tipo di controllo che il contratto richiede in termini di scadenze (15gg
fatturazione, 30gg pagamento).

## 5. Configurazione Filament

### 5.1 Panel provider

Nessun panel aggiuntivo è necessario: un solo `AdminPanelProvider`, con tenancy attivata.

```php
$panel
    ->tenant(Tenant::class, slugAttribute: 'slug')
    ->tenantRegistration(null)       // niente self-signup: i tenant li crea Alex
    ->tenantProfile(TenantProfile::class)   // pagina "impostazioni azienda" per il partner
    ->tenantMenuItems([
        MenuItem::make()->label('Gestisci azienda')->url(fn () => TenantProfile::getUrl()),
    ])
```

`User` implementa `Filament\Models\Contracts\HasTenants`. Ogni utente appartiene a un solo tenant
(`users.tenant_id`), tranne lo staff Alex (`is_super_admin`) che non è legato a un tenant specifico
e vede il selettore con **tutti** i tenant (utile per supporto/reportistica); un utente normale, con
un solo tenant disponibile, non vede alcun selettore — Filament lo nasconde automaticamente quando
c'è una sola opzione:

```php
public function getTenants(Panel $panel): Collection
{
    return $this->is_super_admin ? Tenant::all() : Tenant::whereKey($this->tenant_id)->get();
}

public function canAccessTenant(Model $tenant): bool
{
    return $this->is_super_admin || $this->tenant_id === $tenant->id;
}
```

`Tenant` implementa `Filament\Models\Contracts\HasName` (per il selettore in sidebar) e
`FilamentUser`-side `HasCurrentTenantLabel` se serve un badge col nome azienda.

### 5.2 Scoping automatico: trait `BelongsToTenant`

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (! $model->tenant_id && $tenant = Filament::getTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $query) {
            if (auth()->user()?->is_super_admin) return; // Alex staff vede tutto, se serve
            $tenantId = Filament::getTenant()?->id;
            if (! $tenantId) return;

            $query->where(function ($q) use ($tenantId) {
                $q->where($q->getModel()->getTable().'.tenant_id', $tenantId);
                if (in_array(SharedAcrossTenants::class, class_uses_recursive($q->getModel()))) {
                    $q->orWhereNull('tenant_id'); // solo per Product/Category: catalogo condiviso
                }
            });
        });
    }
}
```

- `Customer`, `Quote`, `InformationRequest`, `ComodatoMacchina` usano `BelongsToTenant` puro
  (niente `OR tenant_id IS NULL`: non hanno un concetto di "condiviso").
- `Product`/`Category` usano `BelongsToTenant` + marker `SharedAcrossTenants` per includere la
  clausola `OR tenant_id IS NULL`.
- Solo lo staff con `is_super_admin` (tenant master) può creare/modificare prodotti con
  `tenant_id = NULL` (catalogo condiviso) o `tenant_id = master` (riservati Alex); un utente
  Partner, quando crea un prodotto, lo vede automaticamente assegnato al proprio `tenant_id` e non
  può cambiarlo (campo nascosto/disabled in `ProductResource::form()`).

### 5.3 Autorizzazioni: Shield + permessi per-tenant

Attivare davvero `filament-shield` (oggi installato ma inutilizzato) con
`spatie/laravel-permission` in modalità **teams**, usando `tenant_id` come team id:

```php
// config/permission.php
'teams' => true,
'team_foreign_key' => 'tenant_id',
```

```php
// middleware Filament, prima del render del pannello
app(PermissionRegistrar::class)->setPermissionsTeamId(Filament::getTenant()?->id);
```

In questo modo ogni tenant ha ruoli/permessi indipendenti (es. il Partner può definire un ruolo
"Tecnico" con permessi solo su `ComodatoMacchina` e `InformationRequest`, senza toccare i preventivi),
e i ruoli di un tenant non compaiono in un altro.

Ruoli di partenza suggeriti: `master_admin` (staff Alex, cross-tenant), `partner_owner`
(titolare azienda partner, full CRUD sul proprio tenant), `partner_staff` (vendite/preventivi,
no gestione utenti/impostazioni azienda).

### 5.4 Nuove Resource Filament

| Resource | Visibilità | Contenuto |
|---|---|---|
| `TenantResource` | solo `master_admin` | anagrafica partner + condizioni contrattuali (§4.1), stato (attivo/sospeso/scaduto), scadenza contratto/preavviso |
| `CommissionResource` (o Widget "Provvigioni") | `master_admin` (tutte); `partner_owner` (solo le proprie) | elenco preventivi con scenario/importo/stato fatturazione, filtri per tenant e scadenza |
| `ProductResource` (esistente, da adattare) | tutti, ma creazione "globale" riservata a `master_admin` | aggiungere colonna/badge "Condiviso" vs "Tuo" vs "Solo Alex" |
| Le altre resource esistenti | invariate nella UI, scoping trasparente via global scope | nessuna modifica visibile all'utente |

## 6. Branding e documenti per-tenant

I PDF preventivo/email (`QuoteController`, `QuoteMail`, viste in `resources/views`) oggi usano un
logo/colori fissi (Franke + Preventivi VG). Vanno resi tenant-aware leggendo
`quote.tenant->logo_path`, `->primary_color`, ragione sociale/P.IVA per l'intestazione fattura
provvigioni. Stesso discorso per `AdminPanelProvider::brandLogo()`/colori, che oggi sono statici:
vanno spostati a lookup su `Filament::getTenant()` quando disponibile (mantenendo il brand Alex
come default per il tenant master).

## 7. GDPR e riservatezza (art. 14 e 18 del contratto)

I dati cliente restano di proprietà logica del tenant che li ha creati; lo scope tenant li
protegge da accessi incrociati fra partner. Da definire **fuori dal codice** ma da tracciare:
un DPA (Data Processing Agreement) tra Alex e ciascun partner, dato che lo staff `master_admin`
avrà accesso tecnico cross-tenant per supporto/reportistica — va documentato chi tratta i dati
come titolare e chi come responsabile, e loggare gli accessi cross-tenant di `master_admin`
(basta un `activity log` — es. `spatie/laravel-activitylog`, già coerente con lo stack Spatie
in uso).

## 8. Piano di rilascio

1. **Fondamenta**: tabella `tenants`, colonne `users.tenant_id`/`is_super_admin`; seed del tenant
   master (Alex) e migrazione dei dati esistenti (tutti i record attuali → `tenant_id` = master).
   Vedi §8.1 per il piano di migrazione dati senza perdite sul DB di produzione reale.
2. **Scoping**: trait `BelongsToTenant`/`SharedAcrossTenants`, alter tabelle (§4.2), attivazione
   `->tenant()` sul panel, tenant switcher.
3. **Autorizzazioni**: Shield in modalità teams, ruoli di base, policy aggiornate (sostituire
   `AdminOnlyPolicy` con permessi granulari).
4. **Catalogo condiviso**: adattare `ProductResource`/`CategoryResource` per gestione
   globale/master/tenant, UI badge di provenienza.
5. **Provvigioni**: colonne su `quotes`, `QuoteObserver` di calcolo, `CommissionResource`/report.
6. **Branding & documenti**: PDF/email/panel tenant-aware.
7. **Onboarding primo partner reale (GIFAR)**: creazione tenant con condizioni da contratto,
   test end-to-end di uno scenario A e uno B.

### 8.1 Migrazione del DB di produzione senza perdita di dati

Analizzato il dump reale (`nbalexca_app_preventivi.sql`, 2026-07-09). Volumi in gioco — piccoli,
il rischio principale non è la scala ma fare le alter table nell'ordine sbagliato:

| Tabella | Righe | Tabella | Righe |
|---|---|---|---|
| `products` | 210 | `information_requests` | 96 |
| `product_prices` | 205 | `information_request_product` | 82 |
| `product_compatibilities` | 1014 | `customers` | 123 |
| `quote_products` | 282 | `quotes` | 36 |
| `categories` | 11 | `quote_emails` | 36 |
| `payment_methods` | 7 | `comodato_macchine` | 6 |
| `users` | 3 | | |

**Nota**: il dump contiene anche `invoices`/`invoice_products` (vuote, 0 righe) — non collegate a
nessun model/Resource nel codice Laravel attuale. Confermato: è una funzionalità "Fattura da
preventivo" abbozzata solo a livello di schema e mai completata lato applicativo. Per questa fase
si lasciano **intoccate** (non si droppano, non si migrano): non hanno dati da perdere e non sono
nello scope della multi-tenancy. Vanno riconsiderate quando si disegnerà un vero modulo fatture —
a quel punto probabilmente confluiranno nel modulo provvigioni/canone SaaS già previsto (§4.3, §4.4)
invece di restare un modulo scollegato.

**Regola generale per ogni migration di alter**: additiva e reversibile, mai distruttiva in un solo
passo. Per ogni tabella esistente:

1. Aggiungere la colonna `tenant_id` **nullable**, senza vincolo `NOT NULL` e senza foreign key
   ancora attiva (`->nullable()`, poi `->constrained()->nullOnDelete()` solo dopo il backfill).
2. Backfill: `UPDATE <tabella> SET tenant_id = <id tenant master>` per tutte le righe esistenti
   (in una migration separata, dentro una transazione).
3. Solo per le tabelle "sempre di un tenant" (`customers`, `quotes`, `information_requests`,
   `comodato_macchine`, `information_request_product` via le sue relazioni, `users`): rendere la
   colonna `NOT NULL` in una **terza** migration successiva, dopo aver verificato che il backfill
   ha coperto il 100% delle righe (`SELECT COUNT(*) FROM x WHERE tenant_id IS NULL` deve dare 0).
4. Per `products`/`categories`: **non** rendere `NOT NULL` — il `NULL` è voluto (catalogo
   condiviso). Il backfill in questo caso può anche essere "lascia NULL" per tutto il catalogo
   attuale, dato che i 210 prodotti/11 categorie esistenti sono esattamente il listino ufficiale
   Franke che deve restare visibile a tutti i futuri tenant partner (§4.2) — non serve assegnarli
   al master.
5. Per `users`: gli utenti `admin` esistenti (oggi solo enum `role`) diventano
   `is_super_admin = true` (sono lo staff Alex, che oggi vede già tutto); gli utenti `user`
   diventano `tenant_id = <master>`, `is_super_admin = false`.

Ogni passo è una migration Laravel separata (mai un'unica migration che fa DDL + backfill +
constraint insieme): se il backfill fallisse a metà, le prime due migration sono già a posto e si
corregge solo l'ultima, senza dover fare rollback su tutto. Prima di eseguire in produzione:
backup fresco (il dump che hai condiviso oggi va bene come baseline, rifallo comunque appena prima
del deploy) e dry-run su una copia locale del DB reale — non sul seed/fixture di sviluppo.

### 8.2 Import dati reali in locale (`import:legacy`, eseguito 2026-07-14)

Ambiente di sviluppo passato da SQLite a Docker/Sail (PHP 8.4 + MySQL 8.0, stesso stack del
vecchio progetto — porte diverse per poter girare in parallelo: `APP_PORT=8092`,
`FORWARD_DB_PORT=3309`, vedi `.env.example`). Il dump di produzione
(`nbalexca_app_preventivi.sql`) è stato importato in un database separato `legacy_preventivi`
sullo stesso container MySQL (connessione Laravel dedicata `legacy` in `config/database.php`,
usata solo in lettura).

Il comando `php artisan import:legacy` (`app/Console/Commands/ImportLegacyData.php`) legge da
quella connessione e scrive nel nuovo schema multi-tenant:

- **Catalogo** (categorie, prodotti, prezzi, compatibilità) → `tenant_id = NULL`, condiviso con
  tutti i partner (§4.2). I gruppi di opzioni, stringa libera nello schema legacy, diventano
  `ProductOptionGroup` distinti (selezione multipla di default — da affinare a "singola" per i
  gruppi realmente esclusivi tramite l'interfaccia).
- **Tutto il resto** (clienti, preventivi, righe preventivo, email, richieste informazioni,
  comodato macchine) → tenant master (Alex), creato automaticamente se non esiste.
- **Utenti**: `role = admin` → `is_super_admin = true`; `role = user` → assegnato al tenant
  master. Le password (bcrypt) sono importate così come sono, restano valide.
- Ogni riga collegata a un ID legacy mancante nella mappa (es. un `product_id` NULL) viene
  **saltata**, non sostituita con un valore fittizio — es. 77 righe su 82 in
  `information_request_product` avevano già `product_id NULL` in produzione, dato incompleto
  preesistente e non un problema di import.

Risultato reale importato: 210 prodotti, 205 prezzi, 1014 compatibilità, 11 categorie, 125
clienti, 40 preventivi, 300 righe preventivo, 39 email, 96 richieste informazioni, 6 comodato
macchine, 3 utenti (2 diventati `is_super_admin`), 7 metodi di pagamento.

**Nota di sicurezza**: il database MySQL locale contiene ora dati reali di produzione (clienti,
email, password hashate) — resta solo nel volume Docker locale, non va mai committato o condiviso;
`.env` (con le credenziali) è già escluso da git.

### 8.3 Correzioni post-import sui prodotti (2026-07-14)

Un primo giro di verifica sui prodotti importati ha rivelato tre problemi, tutti corretti in
`ImportLegacyData.php` e riprodotti con un secondo import pulito:

- **Encoding**: l'import iniziale del dump usava il charset di default del client MySQL
  (`latin1`) invece di `utf8mb4`, corrompendo ogni carattere accentato ("2° Macinacaffè" diventava
  "2� Macinacaff�") — sia nel DB `legacy_preventivi` sia, di conseguenza, nel nuovo schema.
  Corretto ricreando `legacy_preventivi` con `CHARACTER SET utf8mb4` e reimportando il dump con
  `mysql --default-character-set=utf8mb4`.
- **`source` (Franke/terzo) sbagliato**: il catalogo legacy non è solo Franke — include anche
  Dalla Corte, Jura e prodotti "Alex" propri, distinguibili dal nome categoria
  (`Macchine Dalla Corte`, `Macchine Jura`, `Opzioni Alex`, ...). Il primo import marcava *tutti*
  i 210 prodotti come `franke_ufficiale`, sbagliato per gli altri marchi e rilevante per la
  garanzia (art. 11.3). Corretto con una mappa nome-categoria → source
  (`CATEGORY_SOURCE_MAP` in `ImportLegacyData`); le categorie realmente ambigue (es. "Trattamento
  acqua", ricambi generici che potrebbero essere sia OEM Franke che compatibili) restano `NULL`
  invece di un valore indovinato — da confermare manualmente.
- **Nessuna famiglia macchina**: uno SKU/nome legacy come `A300-FM-EC-1G-H1-W3` corrisponde
  esattamente alla nomenclatura ufficiale Franke vista nei listini (§11.2) — `resolveFamily()`
  estrae il codice famiglia (`A300`, `A400`, `A600`, `A800`, `A1000`, `S700`, `SB1200`) dal
  prefisso dello SKU e crea/riusa il `ProductFamily` corrispondente. Le macchine di altri marchi
  (Dalla Corte, Jura) non hanno questo pattern e restano correttamente senza famiglia — 33 macchine
  Franke raggruppate in 7 famiglie, 25 macchine di altri marchi lasciate senza famiglia forzata.

Le **immagini prodotto** risultano invece genuinamente assenti nel dato di produzione stesso
(colonna `image` sempre `NULL` già in `legacy_preventivi`), non un problema di import — non c'è
nulla da recuperare lato codice, andranno caricate manualmente o tramite un futuro import da
gestionale (§10.4) se disponibili altrove.

### 8.4 Verifica contro i listini ufficiali Franke 2026 (2026-07-15)

Confrontando il catalogo importato con i listini reali (`Alex/Listini/37 Italy RRP EUR Classic A
Line ITA_2026_V1.0.pdf` e `Alex/Listini/Listino Nuova A Line.pdf`, entrambi validi dal
01.01.2026): il DB di produzione legacy conteneva **già** l'intero listino Franke digitalizzato,
prezzi 2026 inclusi — non solo la struttura, i valori numerici stessi (es. A300 NM 1G H1 W3 →
4815€, SU06 CM Pro New A Line → 3535€, FrankeCloud Manage → 1040€) coincidono esattamente con
quanto scritto nei PDF, `valid_from` già `2026-01-01`. Nessun aggiornamento prezzi necessario.

L'unico gap reale: i lettori di carte/gettoniere specifici per marca (Coges, Nayax, Ingenico,
Dallmayr, Contidata, ecc. — sezione "Sistemi di conteggio" del listino, pag. 24-26) non erano
presenti come SKU individuali, solo le 4 versioni generiche di alloggiamento (AC125/AC200
standard e con VIP-1). Aggiunti con `php artisan import:franke-card-readers`
(`app/Console/Commands/ImportFrankeCardReaders.php`, idempotente via `updateOrCreate` sullo SKU =
numero ordine Franke reale): 47 prodotti, categoria "Accessori Franke", catalogo condiviso.
Catalogo totale: 257 prodotti.

## 9. Decisioni prese (2026-07-09)

Tutti i punti aperti della versione precedente sono stati chiusi:

- **Utenti**: un utente appartiene a **un solo tenant** (`users.tenant_id`), niente pivot
  many-to-many. Lo staff Alex (`is_super_admin`) non è legato a un tenant specifico e ha accesso
  cross-tenant a tutti (vedi §4.1, §5.1).
- **Canone SaaS**: previsto ma **opzionale per singolo tenant** — campi `saas_billing_enabled` /
  `saas_plan_fee` / `saas_billing_cycle` su `tenants` + tabella `tenant_subscription_invoices`
  (§4.4). Resta indipendente dal modulo provvigioni contrattuali (§4.3): un partner può pagare
  solo provvigioni, solo canone, entrambi, o nessuno dei due (es. il tenant master Alex).
- **Dominio/URL**: si parte con **percorso comune** (`preventivi-vg.it/admin/{tenant}`) — nessuna
  gestione DNS/certificati per partner, isolamento sessione/cookie più semplice e meno superficie
  d'attacco rispetto a sottodomini per tenant. Un dominio custom per partner resta un'estensione
  futura possibile (colonna `custom_domain` + middleware di risoluzione host), da valutare solo se
  un partner lo richiederà esplicitamente.
- **`stancl/tenancy`**: confermato di **non** utilizzarlo — si procede a schema condiviso con
  `tenant_id` (vedi nota decisionale al §3).

Nessun punto aperto residuo per la parte multi-tenant: il documento era pronto per la fase
implementativa (§8) — si aggiunge ora un nuovo requisito, il modulo Rapportini Tecnici (§10).

## 10. Modulo Rapportini Tecnici (nuovo requisito)

L'ufficio tecnico (di Alex e di ogni partner) deve poter compilare, far firmare al cliente e
lasciargli/inviargli un **rapportino di intervento** — installazione, manutenzione ordinaria o
straordinaria, riparazione, intervento in garanzia. Si incastra bene nel dominio esistente:
collega direttamente il "kit base di ricambi" del Partner (art. 5 del contratto) e la clausola di
garanzia su ricambi non originali (art. 11.3) — il rapportino diventa la prova documentale di
quali ricambi sono stati usati in ogni intervento.

### 10.1 Modello dati

**`service_reports`** — un rapportino per intervento

```
id, tenant_id (NOT NULL),
number,                          -- RT-{tenant}-YYYY-NNNN, vedi nota numerazione in §10.5
customer_id (FK Customer, NOT NULL),
comodato_macchina_id (FK nullable),   -- se l'intervento riguarda una macchina a comodato censita
quote_id (FK nullable),               -- se collegato a una vendita/preventivo esistente
machine_product_id (FK Product, nullable),  -- modello macchina, se a catalogo
machine_serial_number (string, nullable),   -- matricola fisica, per storico interventi sulla stessa unità
technician_id (FK User, NOT NULL),
intervention_type (enum: installazione, manutenzione_ordinaria, manutenzione_straordinaria, riparazione, garanzia),
intervention_date, arrival_at (datetime nullable), departure_at (datetime nullable),
problem_description (text nullable), work_performed (text),
status (enum: bozza, completato, firmato, inviato),
customer_signature_path (string, nullable),
technician_signature_path (string, nullable),
signed_at (datetime, nullable),
notes (text, nullable),
created_at, updated_at
```

**`service_report_products`** — ricambi/materiali usati (pivot con quantità)

```
id, service_report_id, product_id, quantity, unit_cost_snapshot (decimal, nullable), notes
```

**`service_report_emails`** — log invio PDF al cliente (stesso pattern già usato da `QuoteEmail`)

```
id, service_report_id, user_id, recipient_email, cc_email, subject, message, status, error_message
```

Tenancy: `ServiceReport` usa il trait `BelongsToTenant` puro (come `Customer`/`Quote`, non
`SharedAcrossTenants` — un rapportino appartiene sempre a un tenant specifico, mai condiviso).

### 10.2 Flusso di compilazione

1. Il tecnico apre "Nuovo Rapportino" (da mobile/tablet sul campo, o da desktop in ufficio).
2. Seleziona un `Customer` esistente **oppure ne crea uno al volo** tramite
   `Select::make('customer_id')->relationship(...)->createOptionForm([...])` — feature nativa di
   Filament, nessun pacchetto aggiuntivo. Confermato: i tecnici intervengono spesso su clienti non
   ancora censiti a sistema.
3. Se l'intervento riguarda una macchina già a catalogo/comodato, la seleziona; altrimenti inserisce
   modello e matricola liberamente.
4. Compila tipo intervento, orari, descrizione lavoro svolto.
5. Aggiunge i ricambi usati dal catalogo `Product` con relative quantità (repeater Filament).
6. Raccoglie la firma del cliente su schermo (§10.3) → stato `firmato`.
7. Genera il PDF (stesso pattern di `QuoteController::pdf()`/`QuoteMail` già in uso per i
   preventivi) e lo invia via email al cliente e/o lo lascia in loco.

### 10.3 Firma digitale su schermo

Filament non include un campo firma nativo. Va costruito un **Custom Form Field** (meccanismo di
estensione di prima classe di Filament, quindi coerente con "solo componenti Filament": non è un
sistema esterno, è un field custom con vista Blade + libreria JS leggera di canvas-firma tipo
`signature_pad`, già gestibile dalla pipeline Vite esistente del progetto) che:
- cattura il tratto touch/mouse su un `<canvas>`,
- esporta il risultato come PNG (data URL),
- lo salva su `storage/app/public/signatures/...` al submit, popolando `customer_signature_path`.

Va verificata la UX responsive del pannello per i tecnici sul campo (oggi `topNavigation(false)` +
sidebar, pensato per desktop): prevedere eventualmente un layout/pagina dedicata e più essenziale
per la compilazione da mobile, riducendo i campi visibili al minimo necessario.

### 10.4 Catalogo ricambi alimentato dal gestionale esterno

Hai segnalato un punto importante: i 210 prodotti oggi a sistema sono solo quelli **acquistati da
Franke**; il catalogo ricambi/materiali si amplierà con altri articoli popolati da un gestionale
esterno. Il progetto ha già l'infrastruttura per questo (`app/Imports`, `app/Filament/Exports`,
tabelle `imports`/`exports`/`failed_import_rows`, `pxlrbt/filament-excel`) — va solo esteso per il
nuovo import a alimentare il catalogo condiviso: gli articoli importati dal gestionale vanno creati
con `tenant_id = NULL` (catalogo condiviso, §4.2), così ogni futuro partner li eredita
automaticamente senza bisogno di sincronizzazioni manuali per tenant.

Suggerimento per la garanzia (art. 11.3): aggiungere a `products` un campo `source` (enum:
`franke_ufficiale`, `terzo`) per poter evidenziare nel rapportino se sono stati usati ricambi non
originali — utile per la decadenza garanzia, a costo di una sola colonna in più.

### 10.5 Correzione necessaria: numerazione documenti per tenant

Verificato nel codice attuale: sia `InformationRequest::boot()`
(`app/Models/InformationRequest.php`) sia il calcolo del campo `number` in
`QuoteResource.php:171-186` (prefisso `PRV-{year}-`) generano il prossimo numero interrogando
**tutta la tabella**, senza filtro. Con la multi-tenancy questo va corretto aggiungendo
`->where('tenant_id', Filament::getTenant()->id)` alla query di calcolo del prossimo numero,
altrimenti un partner vedrebbe sequenze con "buchi" enormi (es. il suo primo preventivo numerato
`PRV-2026-0148` invece di `PRV-2026-0001`, perché conta anche i preventivi di Alex e degli altri
partner). Lo stesso schema di numerazione va applicato al nuovo `service_reports.number`
(`RT-{year}-NNNN`, scoped per tenant) fin dall'inizio.

## 11. Nuova repo, UUID e configuratore macchina a wizard (2026-07-09, seconda sessione)

Il progetto multi-tenant nasce come **nuovo repo separato** (`multitenant-crm`,
`github.com/laura592/multitenant-crm`), Laravel 12 pulito + Filament 3, non un fork/branch di
`app_preventivi_vg`: la produzione attuale resta intoccata finché la nuova piattaforma non è
pronta. Naming: non "CMS" (nessun contenuto pubblicabile da gestire) ma un ibrido
CRM + CPQ (configuratore preventivi) + PRM (partner/provvigioni) + FSM (rapportini), vedi
discussione §9-§10.

Pacchetti installati finora, deliberatamente minimi: `filament/filament` (core, necessario per i
pannelli), `bezhansalleh/filament-shield` + `spatie/laravel-permission` (ruoli, §5.3),
`barryvdh/laravel-dompdf` (PDF). Il resto (`filament-excel`, `browsershot`, `laravel-pdf`,
`intervention/image`) si aggiunge solo quando una feature concreta lo richiede, non preventivamente.

### 11.1 Chiavi primarie: UUID invece di bigint auto-increment

Deciso di passare a **UUID** su tutte le tabelle del nuovo progetto (`Illuminate\Database\Eloquent\Concerns\HasUuids`
sui model, `$table->uuid('id')->primary()` nelle migration, foreign key con
`$table->foreignUuid('tenant_id')->constrained()`). Cambia rispetto alla raccomandazione iniziale
(§"UUID?", risposta precedente) perché nel frattempo il perimetro è cresciuto: multi-tenant reale
con partner esterni, futuro collegamento a un gestionale esterno per l'import catalogo (§10.4), e
un configuratore/wizard che compone più record insieme (macchina + unità ausiliarie + opzioni,
§11.2) — in questi scenari gli ID non prevedibili e senza informazione di sequenza/volume sono
la scelta più sicura di default, ed evitano collisioni quando si importano/sincronizzano dati da
sistemi esterni. Filament e le relazioni Eloquent funzionano in modo trasparente con UUID, nessun
impatto sul resto dell'architettura descritta nei paragrafi precedenti.

### 11.2 Configuratore macchina a wizard (dai listini Franke reali)

Analizzati i listini in `Alex/Listini` (`37 Italy RRP EUR Classic A Line ITA_2026_V1.0.pdf`).
La struttura reale di configurazione di una macchina Franke è più ricca del semplice
`Product.type = base/option` attuale:

- **Famiglie di macchina**: A300, A400, A600, A600 FM CM, A800, A1000 FM CM, S700, SB1200 (con
  varianti FM CM SU12 / UT40 / UT40 Twin).
- Ogni famiglia ha più **varianti di apparecchio base vendibili**, ciascuna con SKU e prezzo
  pieno propri (non "base + delta"): es. A300 esiste come NM (nessun sistema latte), MS EC
  (sistema latte MS), FM EC (FoamMaster) — incrociato con la connessione acqua W3/W4. Il prezzo
  cambia per intero tra varianti (A300 NM 1G H1 W3 = 4815€, A300 FM EC 1G H1 W3 = 6400€).
- **Unità ausiliarie** (SU03, SU05, SU12, SU12 Twin, KE200, MU, UC05, UC09, FS/FSU, CW) — spesso
  **obbligatorie** in base alla variante scelta (es. A600 FM CM richiede un'unità di
  raffreddamento Franke: "unità di raffreddamento Franke necessaria"), altre volte a scelta tra
  alternative con prezzi diversi.
- **Opzioni** con vincoli reali, non solo raggruppamento: alcune sono a scelta singola all'interno
  di un gruppo che **si sostituiscono** (Autosteam S2/S3 "al posto della lancia vapore S1"), altre
  **richiedono** un'altra opzione (DualMilk richiede il "Self-Serve Package"), altre sono
  **incompatibili** con una configurazione data ("non disponibile con UC", "non possibile in
  combinazione con le lance vapore S1-S3").
- **Accessori** perlopiù indipendenti (Flavor Station, Scaldatazze A-Line).
- **Servizi/licenze** con natura ricorrente distinta dal prezzo macchina (kit di connessione IoT
  4G una tantum, "FrankeCloud: licenza Manage" — 2 anni di durata minima, per rivenditori/partner):
  concettualmente vicino al modulo canone SaaS già previsto per i tenant (§4.4), ma qui è Franke
  stessa a vendere un servizio ricorrente ai partner attraverso Alex — da tenere distinto dal
  canone piattaforma di Alex verso i propri partner, sono due cose diverse anche se simili nella
  forma.

**Modello dati esteso** (sostituisce/estende §2 e la parte prodotti di §4):

```
product_families           -- id, name (es. "A300"), description, image, sort_order
products                   -- + product_family_id (FK nullable, solo per type=machine)
                            -- type esteso: machine | auxiliary_unit | option | accessory | service
product_option_groups      -- id, name, label, selection_type (enum: single, multiple),
                            --   is_required (bool), sort_order
                            --   sostituisce la stringa libera product_compatibilities.option_group
product_compatibilities    -- + option_group_id (FK, invece della stringa libera)
                            -- + constraint_type (enum: compatible, required) tra base e opzione/unità
product_requirements       -- product_id, requires_product_id — vincoli "richiede" cross-opzione
                            --   (es. DualMilk → Self-Serve Package)
product_exclusions         -- product_id, excludes_product_id — vincoli "incompatibile con"
                            --   (es. opzione X non disponibile con unità ausiliaria UC)
```

`product_option_groups.selection_type = single` copre nativamente i casi "si sostituiscono"
(es. gruppo "Lancia vapore/Autosteam": S1, S2, S3 nello stesso gruppo a scelta singola — radio,
non checkbox); `product_requirements`/`product_exclusions` coprono i vincoli cross-gruppo che un
semplice raggruppamento non basta a esprimere.

**Wizard di configurazione** (sostituisce l'attuale repeater piatto in `QuoteResource`), un
`Filament\Forms\Components\Wizard` — componente Filament nativo, nessun pacchetto esterno:

1. **Famiglia** — scelta tra `product_families` (o "aggiungi solo opzione/accessorio" senza
   passare da una macchina, per righe preventivo indipendenti tipo ricambi).
2. **Variante apparecchio base** — `products` filtrati per famiglia, con prezzo mostrato in lista.
3. **Unità ausiliarie** — quelle con `constraint_type = required` per la variante scelta appaiono
   preselezionate e non deselezionabili; le altre compatibili sono opzionali.
4. **Opzioni** — raggruppate per `product_option_groups` (radio se `single`, checkbox se
   `multiple`); ad ogni selezione, le opzioni in `product_exclusions` rispetto alle scelte fatte
   vengono disabilitate reattivamente (Livewire), quelle in `product_requirements` non ancora
   soddisfatte mostrano un avviso prima di poter proseguire.
5. **Accessori** — selezione libera multipla.
6. **Riepilogo** — prezzo totale configurazione, conferma → crea un `QuoteProduct` per la macchina
   base e uno per ogni unità/opzione/accessorio selezionato, collegati con
   `parent_quote_product_id` (schema già esistente in `QuoteProduct`, nessuna modifica lì
   necessaria).

Questo stesso configuratore (non solo il wizard, l'intero grafo di vincoli) è riusabile identico
nel modulo Rapportini Tecnici (§10) per registrare quale configurazione esatta di macchina è stata
installata/servita, e nell'import catalogo da gestionale esterno (§10.4) per validare che le nuove
righe importate rispettino la stessa struttura famiglia/variante/opzione.

## 12. Modulo Presenze e Ore Dipendenti (nuovo requisito, 2026-07-09)

Ogni tenant (Alex e ogni partner) deve poter gestire le timbrature dei propri dipendenti,
straordinari, ferie/permessi, e generare un riepilogo ore per il commercialista. È un dominio
distinto da CRM/CPQ/PRM/FSM (§9-§11): riguarda gli **utenti come dipendenti**, non i clienti.
Decisioni raccolte:

- Timbratura **doppia modalità**: in tempo reale (bottone Entrata/Uscita nel pannello) e a
  consuntivo/manuale (per dimenticanze o trasferte), con possibilità di correzione da un
  responsabile.
- Straordinari **calcolati automaticamente** rispetto a un monte ore contrattuale
  giornaliero/settimanale.
- Ferie/permessi con **flusso di richiesta e approvazione** (dipendente richiede, responsabile
  approva/rifiuta).
- Riepilogo per il commercialista: **Excel e PDF**, mensile, per dipendente. L'Excel richiede
  `pxlrbt/filament-excel` — finora escluso per restare minimali (§11), qui è la prima feature che
  lo giustifica davvero, va aggiunto in questa fase.

### 12.1 Modello dati

Tutto `BelongsToTenant` (un dipendente e le sue timbrature/ferie appartengono al tenant per cui
lavora, mai condiviso).

**`users`** (alter aggiuntivo rispetto a §4.1): `daily_contract_hours` (decimal, default 8.00),
`weekly_contract_hours` (decimal, default 40.00), `annual_leave_days` (integer, default 26 —
standard CCNL, personalizzabile per dipendente).

**`time_entries`** — timbrature

```
id, tenant_id, user_id,
clock_in (datetime), clock_out (datetime, nullable),   -- nullable finché il turno è "aperto"
source (enum: app, manuale),
entered_by_user_id (FK users, nullable),  -- valorizzato se inserita/corretta da un responsabile
   -- anziché dal dipendente stesso, per audit
status (enum: aperta, chiusa, corretta),
notes (nullable),
created_at, updated_at
```

**`leave_requests`** — ferie/permessi/malattia

```
id, tenant_id, user_id,
type (enum: ferie, permesso, malattia),
date_from, date_to,
hours (decimal, nullable),   -- per permessi orari invece di giorno intero
status (enum: richiesto, approvato, rifiutato),
requested_at, approved_by_user_id (FK users, nullable), approved_at (nullable),
notes (nullable),
created_at, updated_at
```

Niente tabella fisica per il saldo ferie annuo: si calcola al volo
(`annual_leave_days - somma giorni approvati tipo ferie nell'anno`), evitando di duplicare uno
stato che altrimenti andrebbe tenuto sincronizzato manualmente.

Niente tabella fisica per il riepilogo mensile: è una **query aggregata** su `time_entries` +
`leave_requests` per utente/mese, esposta come pagina Filament con azione di export
(Excel via `filament-excel`, PDF via `dompdf`, entrambi già nello stack). Le ore di straordinario
non si salvano per riga: si derivano confrontando, per ciascun giorno, le ore lavorate totali con
`daily_contract_hours` — così una correzione a una timbratura non lascia disallineamenti da
ricalcolare altrove.

### 12.2 Filament

- **Widget "Timbra"** in dashboard: due pulsanti Entrata/Uscita per l'utente loggato, crea/chiude
  il `TimeEntry` corrente.
- **`TimeEntryResource`**: il dipendente vede/corregge le proprie timbrature; un responsabile
  (titolare tenant o master admin) vede/corregge quelle di tutto il tenant — via Policy, stesso
  meccanismo Shield di §5.3.
- **`LeaveRequestResource`**: form di richiesta per il dipendente, azioni Approva/Rifiuta per il
  responsabile (`Tables\Actions\Action` con conferma, aggiorna `status`/`approved_by_user_id`).
- **Pagina "Riepilogo Ore"**: tabella per dipendente/mese (ore ordinarie, straordinario, ferie e
  permessi usati), filtro per periodo, pulsanti Esporta Excel / Esporta PDF.

Pacchetto da aggiungere ora: `pxlrbt/filament-excel` (già usato nel vecchio progetto per altri
export, coerente con lo stack).

## 13. Scadenzario e pianificazione assistenze (nuovo requisito, 2026-07-09)

Tracciare scadenze assicurazioni, automezzi, manutenzioni ordinarie clienti, con un tableau delle
assistenze programmate. Invece di una tabella diversa per ogni tipo di scadenza, un'unica entità
**generica e polimorfica** riusa lo stesso meccanismo per casi che altrimenti sarebbero
duplicati: la polizza RCT che il Partner deve mantenere per lo Scenario C (art. 17 del contratto),
il rinnovo del contratto di distribuzione stesso (art. 13, `notice_period_days` già in `tenants`,
§4.1), la licenza FrankeCloud (§11.2), l'assicurazione/revisione di un automezzo, la manutenzione
ordinaria di una macchina cliente — sono tutti "una scadenza con una data e uno stato", non
domini diversi.

### 13.1 Modello dati

**`vehicles`** — automezzi (aziendali o del Partner)

```
id, tenant_id, plate, brand, model, year (nullable),
assigned_user_id (FK users, nullable — tecnico a cui è assegnato),
notes, created_at, updated_at
```

**`deadlines`** — scadenze generiche, polimorfiche

```
id, tenant_id,
deadlinable_type, deadlinable_id (morph — Vehicle, Tenant, Customer, MaintenanceSchedule, ...),
type (enum: assicurazione, revisione, polizza_rct, manutenzione_ordinaria, licenza, contratto, altro),
due_date, reminder_days_before (default 30),
status (enum: attiva, scaduta, rinnovata),
notes, created_at, updated_at
```

**`maintenance_schedules`** — piani di manutenzione ordinaria per cliente/macchina

```
id, tenant_id, customer_id, comodato_macchina_id (nullable),
frequency (enum: mensile, trimestrale, semestrale, annuale),
last_service_report_id (FK service_reports, nullable — ultima visita effettuata),
next_due_date,
notes, created_at, updated_at
```

Alla chiusura di un `ServiceReport` di tipo `manutenzione_ordinaria` collegato a un
`maintenance_schedule`, un Observer aggiorna `last_service_report_id` e ricalcola `next_due_date`
in base a `frequency`; una `deadline` polimorfica puntata al `maintenance_schedule` tiene traccia
del prossimo appuntamento per il tableau.

### 13.2 Tableau assistenze programmate

Una pagina Filament (tabella nativa, nessun plugin esterno necessario per la v1) che unisce
`deadlines` (tutti i tipi) e `maintenance_schedules.next_due_date`, raggruppata per urgenza
(scadute / questa settimana / questo mese / più avanti), filtrabile per tenant, tipo e tecnico
assegnato. Se in futuro serve una vista calendario/kanban più visuale, si valuta un plugin
community dedicato in quel momento — non bloccante per la prima versione.

## 14. Preventivi multipli raggruppati (nuovo requisito, 2026-07-09)

A volte si inviano allo stesso cliente più preventivi-opzione alternativi (es. 3 configurazioni
diverse di macchina) perché scelga: oggi ognuno andrebbe inviato come email separata. Si introduce
un raggruppamento per inviarli **in un'unica email**.

### 14.1 Modello dati

**`quote_groups`**

```
id, tenant_id, customer_id, number (es. OFF-2026-0001),
status (enum: bozza, inviato, scelto, scaduto),
sent_at (nullable), notes, created_at, updated_at
```

**`quotes`** (alter): + `quote_group_id` (FK nullable — un preventivo può restare autonomo come
oggi, o far parte di un gruppo di opzioni alternative per lo stesso cliente).

Non serve un campo "scelto" duplicato: si riusa lo `status` già esistente su `Quote` (bozza,
inviato, accettato, rifiutato, ...) — il preventivo scelto dal cliente è semplicemente quello con
`status = accettato` all'interno del gruppo.

**`quote_group_emails`** — log invio (stesso pattern di `QuoteEmail`, ma per l'intero gruppo)

```
id, quote_group_id, user_id, recipient_email, cc_email, subject, message, status, error_message
```

### 14.2 Invio

Un'azione "Invia gruppo preventivi" sul `QuoteGroup` genera **una sola email** con un PDF allegato
per ciascun preventivo del gruppo (riuso del PDF già generato per singolo `Quote`, solo allegati
multipli sullo stesso messaggio — non serve un template PDF combinato per la prima versione, è
un'ottimizzazione rimandabile se servisse un unico documento con più sezioni/opzioni a confronto).
