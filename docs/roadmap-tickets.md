# Backlog Tecnico Derivato Dalla Roadmap

Backlog operativo derivato da docs/roadmap.md, pensato per essere trasformato in ticket di lavoro.

## Epic 1 - Hardening Multi-Tenant Fuori Dal Panel

### Ticket 1.1 - Proteggere download PDF rapportini con autorizzazione esplicita

Obiettivo:

- impedire che un utente autenticato possa scaricare il PDF di un altro tenant.

Attivita':

- verificare il flusso corrente di route e controller per i rapportini;
- introdurre authorize o policy sul download PDF;
- verificare il comportamento quando il tenant Filament non e' risolto.

File iniziali:

- routes/web.php
- app/Http/Controllers/ServiceReportController.php
- app/Models/Concerns/BelongsToTenant.php

Criteri di accettazione:

- utente dello stesso tenant puo' scaricare il PDF;
- utente di altro tenant riceve accesso negato;
- super admin continua a poter accedere dove previsto.

Stima:

- 0.5 giorno.

### Ticket 1.2 - Audit delle route autenticate non Filament

Obiettivo:

- verificare che tutte le route fuori dal panel abbiano un confine di autorizzazione esplicito.

Attivita':

- censire tutte le route web autenticate non Filament;
- classificare per rischio dati o operazioni;
- applicare policy o guard specifici dove mancanti.

Criteri di accettazione:

- ogni route autenticata non panel ha una forma chiara di autorizzazione;
- esiste un elenco sintetico delle route verificate e dell'esito.

Stima:

- 0.5 giorno.

### Ticket 1.3 - Test di regressione per accessi cross-tenant fuori dal panel

Obiettivo:

- bloccare future regressioni sui confini tenant esterni a Filament.

Attivita':

- creare test feature dedicati a download e visualizzazioni;
- coprire almeno casi positivi, negativi e super admin.

File iniziali:

- tests/Feature

Criteri di accettazione:

- i test falliscono senza la protezione e passano dopo la correzione;
- i casi cross-tenant sono esplicitamente coperti.

Stima:

- 0.5 giorno.

## Epic 2 - Quality Gate E Tooling

### Ticket 2.1 - Introdurre analisi statica PHP

Obiettivo:

- avere un controllo automatico su errori di tipo e API misuse non intercettati dai test.

Attivita':

- aggiungere Larastan o PHPStan come dipendenza dev;
- creare configurazione iniziale coerente col progetto Laravel;
- integrare uno script composer dedicato.

File iniziali:

- composer.json
- phpstan.neon o equivalente

Criteri di accettazione:

- il progetto espone un comando ripetibile per static analysis;
- la configurazione e' documentata nel repo.

Stima:

- 0.5 giorno.

### Ticket 2.2 - Ripulire gli errori statici gia' emersi

Obiettivo:

- portare a zero o a baseline controllata gli errori gia' evidenti in editor.

Attivita':

- correggere i warning principali su auth, trait Eloquent e date castate;
- distinguere tra veri bug, limiti del type inference e false positive;
- introdurre annotazioni o refactor minimi dove servono.

File iniziali:

- app/Filament/Concerns/HasDeadlinesTable.php
- app/Filament/Concerns/ScopesToOwnUserUnlessResponsabile.php
- app/Filament/Resources/TenantResource.php
- app/Models/Concerns/BelongsToTenant.php

Criteri di accettazione:

- gli errori statici noti sono risolti o giustificati in baseline;
- nessun workaround opaco o fragile introdotto per far tacere il tool.

Stima:

- 1 giorno.

### Ticket 2.3 - Definire comando unico di verifica locale

Obiettivo:

- standardizzare la verifica pre-merge.

Attivita':

- aggiungere script composer per test e static analysis;
- scegliere il comando da usare come gate locale e futuro CI.

Criteri di accettazione:

- esiste un comando unico documentato;
- il team puo' usarlo senza conoscenza implicita.

Stima:

- 0.25 giorno.

## Epic 3 - Consolidamento Della Logica Di Dominio

### Ticket 3.1 - Estrarre servizio di numerazione tenant-scoped

Obiettivo:

- eliminare la duplicazione della logica di numerazione tra preventivi e rapportini.

Attivita':

- identificare il formato attuale e le varianti per modello;
- creare un servizio comune o action parametrica;
- aggiornare i model che oggi implementano la logica inline.

File iniziali:

- app/Models/Quote.php
- app/Models/ServiceReport.php

Criteri di accettazione:

- la logica di numerazione vive in un solo punto;
- Quote e ServiceReport continuano a generare numeri corretti per tenant.

Stima:

- 1 giorno.

### Ticket 3.2 - Estrarre action per ricalcolo totali preventivo

Obiettivo:

- separare la logica di calcolo dal model per migliorarne testabilita' e riuso.

Attivita':

- mappare input, output e side effect di updateTotal();
- creare una action o service dedicato;
- mantenere invariato il comportamento funzionale.

File iniziali:

- app/Models/Quote.php

Criteri di accettazione:

- il calcolo puo' essere testato isolatamente;
- il model delega la logica invece di contenerla interamente.

Stima:

- 1 giorno.

### Ticket 3.3 - Estrarre action per invio rapportino e PDF

Obiettivo:

- centralizzare il flusso di invio e preparare una base per futuri canali o retry.

Attivita':

- mappare il flusso attuale tra controller, mail e resource;
- introdurre una action con responsabilita' esplicite;
- riusarla nei punti di ingresso esistenti.

Criteri di accettazione:

- il flusso di invio e' centralizzato;
- non c'e' logica duplicata tra UI e controller.

Stima:

- 1 giorno.

## Epic 4 - Copertura Test E Sicurezza Applicativa

### Ticket 4.1 - Rafforzare test per ruoli diversi nello stesso tenant

Obiettivo:

- verificare che ogni ruolo veda e faccia solo quanto previsto.

Attivita':

- aggiungere casi per partner, dipendente, amministrazione e admin;
- coprire sia accessi consentiti sia operazioni vietate.

Criteri di accettazione:

- i permessi chiave sono coperti da test feature;
- le differenze di ruolo non dipendono solo da verifica manuale.

Stima:

- 0.75 giorno.

### Ticket 4.2 - Coprire esplicitamente i casi negativi su export e download

Obiettivo:

- evitare bypass su asset sensibili generati dal server.

Attivita':

- censire PDF, export e allegati scaricabili;
- aggiungere test per accessi negati e accessi corretti.

Criteri di accettazione:

- ogni asset sensibile prioritario ha almeno un test negativo;
- gli endpoint piu' rischiosi sono elencati e coperti.

Stima:

- 0.5 giorno.

## Epic 5 - Documentazione E Onboarding

### Ticket 5.1 - Riscrivere il README operativo del progetto

Obiettivo:

- sostituire il README standard Laravel con una guida reale per il team.

Attivita':

- descrivere setup, dipendenze, test, queue e comandi sviluppo;
- aggiungere sezione su multi-tenancy e tenant master;
- documentare import legacy.

Criteri di accettazione:

- uno sviluppatore nuovo riesce ad avviare il progetto seguendo il README;
- le dipendenze implicite sono esplicitate.

Stima:

- 0.75 giorno.

### Ticket 5.2 - Aggiungere checklist di rilascio interno

Obiettivo:

- standardizzare l'ultima verifica prima di rilasci interni.

Attivita':

- definire test minimi, code path critici e verifiche manuali;
- aggiungere una checklist breve ma ripetibile.

Criteri di accettazione:

- il team ha una checklist chiara prima di chiudere uno sprint o fare deploy;
- la checklist vive nel repo.

Stima:

- 0.25 giorno.

## Epic 6 - Audit Log E Tracciabilita'

### Ticket 6.1 - Definire i modelli sensibili da tracciare

Obiettivo:

- delimitare il perimetro dell'audit senza partire troppo largo.

Attivita':

- identificare priorita' tra tenant, product, product price, quote e altri record chiave;
- definire eventi minimi da registrare.

Criteri di accettazione:

- esiste un elenco prioritario dei modelli da tracciare;
- il perimetro iniziale e' concordato.

Stima:

- 0.25 giorno.

### Ticket 6.2 - Implementare audit log sui record prioritari

Obiettivo:

- registrare chi ha modificato dati critici e quando.

Attivita':

- integrare il package scelto sui modelli prioritari;
- verificare creazione, modifica e cancellazione dove applicabile.

Criteri di accettazione:

- le modifiche ai record prioritari generano audit consultabile;
- il rumore resta sotto controllo.

Stima:

- 0.75 giorno.

### Ticket 6.3 - Rendere consultabile l'audit lato back-office

Obiettivo:

- permettere supporto e amministrazione di usare davvero il log.

Attivita':

- decidere se usare resource dedicata, relation manager o pannello di dettaglio;
- esporre il log con filtri minimi utili.

Criteri di accettazione:

- il team di back-office puo' consultare la storia delle modifiche senza accesso DB diretto.

Stima:

- 0.5 giorno.

## Dipendenze Tra Ticket

1. I ticket 1.1, 1.2 e 1.3 vengono prima del resto per ridurre il rischio di sicurezza.
2. I ticket 2.1 e 2.2 vengono prima delle estrazioni di logica, cosi i refactor sono coperti da un gate migliore.
3. I ticket 3.1, 3.2 e 3.3 sono piu' sicuri dopo il completamento dei ticket 2.x.
4. I ticket 4.x, 5.x e 6.x possono procedere in parallelo tra loro.

## Definizione Di Done

Un ticket e' chiuso quando:

1. il comportamento richiesto e' implementato;
2. esiste almeno una validazione eseguibile adeguata al rischio;
3. non introduce regressioni note su tenancy o autorizzazioni;
4. se modifica workflow o setup, la documentazione del repo e' aggiornata.