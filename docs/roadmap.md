# Roadmap Tecnica

Documento operativo per consolidare il CRM multi-tenant nelle prossime 2 settimane.

## Obiettivo

Portare l'applicazione a uno stato in cui:

- i confini tenant non possono essere aggirati fuori dal panel Filament;
- test e analisi statica bloccano le regressioni prima del merge;
- la logica di dominio core e' centralizzata e meno duplicata;
- setup e operativita' quotidiana non dipendono da conoscenza implicita.

## Settimana 1

### Giorno 1 - Hardening accessi fuori da Filament

Focus:

- proteggere le route autenticate non panel;
- impedire accessi cross-tenant a PDF e risorse simili.

Interventi:

- revisionare l'accesso ai rapportini PDF;
- aggiungere authorize o policy esplicite ai controller interessati;
- verificare altre route web che oggi si affidano solo ad auth.

Deliverable:

- accesso ai PDF consentito solo a utenti autorizzati del tenant corretto;
- test dedicati su accesso negato cross-tenant.

## Giorno 2 - Quality gate minimo

Focus:

- introdurre analisi statica eseguibile localmente e poi in CI.

Interventi:

- aggiungere Larastan o PHPStan con configurazione base;
- trasformare gli errori gia' visibili in issue risolvibili;
- aggiungere script composer per test e static analysis.

Deliverable:

- comando unico per eseguire test e analisi statica;
- baseline iniziale pulita o controllata.

## Giorni 3-4 - Centralizzazione logica di dominio

Focus:

- ridurre duplicazioni nei modelli;
- spostare regole di business ripetute in service o action dedicate.

Interventi:

- estrarre la numerazione tenant-scoped in un servizio comune;
- valutare una action per ricalcolo totali preventivo;
- valutare una action per invio rapportini o generazione PDF.

Deliverable:

- meno logica distribuita tra model, resource e controller;
- test aggiornati sui servizi estratti.

## Giorno 5 - Copertura test sui confini tenant

Focus:

- coprire esplicitamente isolamento dati e autorizzazioni.

Interventi:

- aggiungere test su route web non Filament;
- rafforzare i test per ruoli diversi nello stesso tenant;
- aggiungere casi negativi su download, visualizzazioni e operazioni vietate.

Deliverable:

- suite focalizzata su tenancy e access control;
- regressioni piu' difficili da introdurre senza accorgersene.

## Settimana 2

## Giorno 6 - Documentazione operativa reale

Focus:

- sostituire il README standard con documentazione utile al team.

Interventi:

- descrivere setup locale, seed, queue, test e import legacy;
- documentare tenant master e ruoli;
- chiarire i comandi di sviluppo e verifica.

Deliverable:

- onboarding ripetibile senza passaggi tramandati a voce.

## Giorno 7 - Audit e tracciabilita'

Focus:

- tracciare modifiche su record sensibili.

Interventi:

- introdurre audit log su tenant, catalogo, prezzi e preventivi;
- definire eventi o modelli prioritari da tracciare;
- rendere consultabile il log almeno lato back-office.

Deliverable:

- maggiore trasparenza su chi ha cambiato cosa e quando.

## Giorno 8 - Stabilizzazione e rilascio interno

Focus:

- consolidare il lavoro e lasciare una base pronta per il ciclo successivo.

Interventi:

- eseguire test completi e analisi statica;
- verifica manuale dei flussi piu' critici;
- preparare backlog residuo e note di rilascio interne.

Deliverable:

- rilascio tecnico interno con checklist chiara;
- backlog ordinato per la fase successiva.

## Priorita' residue dopo le 2 settimane

1. Dashboard provvigioni e canoni tenant.
2. CI completa con workflow automatici.
3. Audit mirato delle query che bypassano Eloquent nei flussi sensibili.

## Criteri di uscita

La roadmap puo' considerarsi completata quando:

1. un utente autenticato non puo' accedere a dati di un altro tenant fuori dal panel;
2. test e analisi statica girano con un comando ripetibile;
3. numerazione e logica di calcolo non sono duplicate nei modelli principali;
4. un nuovo sviluppatore puo' avviare il progetto seguendo la documentazione del repo.
