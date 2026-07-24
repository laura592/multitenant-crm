# Checklist di rilascio interno

Checklist pratica da seguire prima di chiudere uno sprint o fare un deploy
interno. Non sostituisce il buon senso: se qualcosa non torna, non spuntarlo
e chiedi. Vive nel repo apposta per essere aggiornata quando cambia il
progetto — se un passo non serve più o ne manca uno, modifica questo file.

## 1. Comandi da lanciare sempre

```bash
composer verify
```

Esegue `composer test` (PHPUnit, DB sqlite `:memory:`) + `composer analyse`
(PHPStan/Larastan). È il gate minimo: se non è verde, non si rilascia (con
l'unica eccezione dei fallimenti noti già tracciati, vedi sotto).

**Stato noto al momento in cui è stata scritta questa checklist**:
`composer analyse` pulito; `composer test` ha 4 test falliti preesistenti
(`RolePermissionsTest` x3, `AppointmentCalendarTest` x1), non introdotti
dalla documentazione (Epic 5) e non risolti in questo lavoro perché fuori
perimetro. Se stai leggendo questa checklist in futuro e i 4 falliscono
ancora identici, non è una regressione tua — ma se il numero è cambiato o i
nomi sono diversi, **fermati e indaga**: potrebbe essere una rottura reale.
Aggiorna questa nota quando vengono sistemati, per non lasciare un
riferimento morto.

```bash
git status
git diff
```

Nessun file modificato non intenzionale, niente `.env`/credenziali in
staging, niente file di debug (`dd()`, `dump()`, log temporanei) dimenticati
nel diff.

## 2. Se hai toccato Resource Filament o permessi

Questo è il punto più facile da dimenticare, perché non fallisce in modo
rumoroso: i permessi/policy Shield **non si aggiornano da soli** quando
aggiungi, rinomini o togli una Resource/Widget/Page.

- [ ] Se hai aggiunto/tolto/rinominato una Resource, Widget o Page Filament:
      rigenera i permessi e le policy di Shield.

  ```bash
  php artisan shield:generate --all
  ```

  Verifica dopo con `git diff` cosa è cambiato in `app/Policies/` e nella
  tabella `permissions` — un permesso "orfano" rimasto da una Resource
  rimossa va tolto anche da `App\Support\RolePermissions` se referenziato lì.

- [ ] Se hai modificato `App\Support\RolePermissions` (aggiunto/tolto un
      permesso a un ruolo, aggiunto un ruolo nuovo): risincronizza i ruoli
      per ogni tenant, non solo in locale — è idempotente, sicuro da
      rilanciare.

  ```bash
  php artisan db:seed --class=RolesAndPermissionsSeeder
  ```

  (`Role::syncPermissions()` allinea esattamente ai permessi definiti nel
  codice: aggiunge i nuovi, toglie quelli non più presenti.)

- [ ] Widget e Page **non sono gated di default** da Shield: se un
      widget/pagina nuovo deve essere visibile solo a certi ruoli, applica
      esplicitamente `HasWidgetShield`/`HasPageShield` (non basta
      rigenerare). Vedi `docs/architecture.md` §5.3.1 per i precedenti
      (`TimbraWidget`, `RiepilogoOre`).

- [ ] Prova il login con almeno due ruoli diversi (es. `admin@test.it` e
      `partner@test.it`, password `password`, vedi README) e verifica che la
      nuova Resource/permesso si comporti come previsto per entrambi, non
      solo per l'admin.

## 3. Verifiche manuali sui code path critici

Non tutto è coperto da test automatici. Prima di un rilascio, ripassa a
mano almeno questi flussi (in locale o in staging, mai per la prima volta in
produzione):

- [ ] **Scoping multi-tenant**: login come utente di un tenant partner,
      verificare che non veda clienti/preventivi/dati di altri tenant, né
      tramite le liste né digitando un ID/URL diretto di un record non suo.
- [ ] **Catalogo condiviso**: il listino con `tenant_id = NULL` resta
      visibile (in sola lettura, dove previsto) a tutti i tenant di prova.
- [ ] **Generazione PDF ed email** (preventivi, rapportini, ordini
      materiali): con un worker di coda attivo (`php artisan queue:listen`
      o `composer dev`), il job parte, il PDF si genera, l'email risulta
      inviata (in locale finisce nei log/`storage/logs` con `MAIL_MAILER`
      di sviluppo — controlla che non vada silenziosamente in errore nella
      tabella `failed_jobs`).
- [ ] **Rapportini**: un rapportino con `signature_path` valorizzato non è
      più modificabile da nessun percorso (solo insert, mai update) —
      controllo manuale se hai toccato quel dominio.
- [ ] **Ruolo con visibilità ridotta** (`partner`): non vede risorse fuori dal
      suo perimetro (niente scadenzario/presenze/rapportini/metodi di
      pagamento — vedi README e `docs/architecture.md` §5.3.1).
- [ ] Se il rilascio tocca il dominio preventivi/PDF/email: **non fidarti
      solo di questa checklist generica**, coordina con chi sta lavorando
      su quel dominio in parallelo prima di assumere che il comportamento
      attuale sia definitivo.

## 4. Cose facili da dimenticare

- [ ] `.env` di produzione ha `APP_DEBUG=false` e `APP_ENV=production` (mai
      deployare con `APP_DEBUG=true`: espone stack trace agli utenti).
- [ ] Se sono state aggiunte migration: `php artisan migrate --pretend`
      (o `migrate:status`) per capire cosa girerà prima di lanciarlo per
      davvero in produzione; backup del DB prima di migration che alterano
      colonne esistenti (non solo additive).
- [ ] Se sono stati aggiunti/modificati asset serviti da `storage/app/public`
      (es. loghi tenant): `php artisan storage:link` è già stato eseguito
      sull'ambiente target (non rieseguibile "a sorpresa" da una migration).
- [ ] Cache di configurazione/route non è rimasta "sporca" da un test
      precedente: dopo modifiche a `.env`/config, `php artisan config:clear`
      prima di verificare il comportamento.
- [ ] Se il rilascio introduce un nuovo comando/job schedulato: verificato
      che sia effettivamente registrato e che un worker/cron lo esegua
      nell'ambiente target, non solo testato con `php artisan` a mano.

## 5. Non verificato / da tenere d'occhio

Punti che questa checklist assume ma che non sono stati validati end-to-end
al momento della stesura (nessun ambiente Docker/staging disponibile in
questa sessione):

- L'avvio reale di `docker-compose.yml`/Sail non è stato testato qui — solo
  letto. La prima persona che lo usa su questo repo dovrebbe confermare che
  funziona e correggere README/checklist se qualcosa non torna.
- Il comportamento di `failed_jobs`/retry per i job PDF/email in un
  ambiente con coda realmente asincrona (qui è stato verificato solo che i
  comandi esistono e girano, non un intero ciclo di invio email reale).
