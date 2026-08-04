# Gestionale Eureka (ALEX srl) — cosa abbiamo trovato

Note di esplorazione dell'API REST del gestionale Eureka, usate per costruire
l'integrazione "Invia a gestionale" sui rapportini tecnici (vedi
`app/Support/Gestionale/EurekaClient.php` e `ServiceReport::toGestionalePayload()`).

Tutte le chiamate qui descritte sono state fatte in lettura (`GET`) contro
l'ambiente di **produzione** — non esiste un ambiente di test. Nessuna
`DELETE` è stata usata (esiste ma non è documentata dal fornitore apposta).

## Come si accede

- Server: `https://alex.api.gestionale-eureka.it/`
- Autenticazione: HTTP Basic Auth su ogni richiesta.
- Le credenziali **non sono in questi file**: sono salvate cifrate sul
  record del tenant Alex (`Tenant.gestionale_eureka_username/password`,
  gestibili da Amministrazione → Aziende partner → "Integrazione Eureka").

## La scoperta più importante: la chiave di collegamento tra i due sistemi

`Customer.gestionale_code` nel CRM **coincide esattamente** con l'`id`
restituito da Eureka su `/anagrafica/cerca`. Verificato incrociando
"Gdp Italia SRL": `gestionale_code = 1` nel CRM ↔ Eureka `id: 1`, stessa
partita IVA. Questo è il perno su cui si basa tutta l'integrazione: sapendo
il `gestionale_code` di un cliente/prodotto, sappiamo già come riferirlo a
Eureka senza doverlo cercare ogni volta.

## Endpoint esplorati

| File | Cosa contiene |
|---|---|
| [clienti.md](clienti.md) | Ricerca anagrafica cliente |
| [articoli-e-tariffe.md](articoli-e-tariffe.md) | Catalogo articoli e tariffe manodopera (con una discrepanza da chiarire) |
| [macchinari.md](macchinari.md) | Matricole e macchinari installati presso i clienti |
| [schede-lavoro.md](schede-lavoro.md) | Il documento che il CRM crea via il pulsante "Invia a gestionale" |

## Cosa sappiamo ora che non sapevamo all'inizio

- **L'endpoint schede lavoro è già popolato e attivo** (centinaia di
  documenti reali visti in lettura, fino al 2026-07-23) — vedi
  [schede-lavoro.md](schede-lavoro.md). Quello che manca ancora è un vero
  test di **scrittura** (`POST`) dal nostro CRM: non fatto finora di
  proposito, dati di produzione senza `DELETE` disponibile per rimediare.
- La tariffa "FISSA" (id=2) citata nella documentazione del fornitore non
  esiste con quel nome tra le tariffe attive (è "MAN"), ma lo storico reale
  conferma che id=2 è comunque quello sempre usato in pratica — vedi
  [articoli-e-tariffe.md](articoli-e-tariffe.md). Basso rischio, resta solo
  una svista nel nome dentro la doc del fornitore.
- **Eureka a volte usa l'id interno come placeholder per P.IVA/Codice
  Fiscale mancanti** — vedi [clienti.md](clienti.md). Il sync automatico
  (`GestionaleSyncRunner`) ora lo riconosce e lo scarta prima di scriverlo
  nel CRM.
- Cercare articoli per **parola chiave del modello funziona bene**, cercare
  per **SKU esatto del nostro catalogo quasi mai** — vedi
  [articoli-e-tariffe.md](articoli-e-tariffe.md).

## Cosa è stato costruito da queste scoperte

Oltre al pulsante manuale "Invia a gestionale" sui rapportini, esiste ora un
**sync automatico giornaliero** (`sail artisan gestionale:sync`, comando
`app/Console/Commands/SyncGestionaleData.php`) che, per clienti/prodotti/
macchinari già collegati o da collegare:
- compila da solo i campi vuoti nel CRM con dati reali da Eureka (mai quelli
  già pieni);
- segnala le differenze senza mai sovrascrivere;
- propone nuovi collegamenti (mai automatici, sempre da confermare a mano)
  per clienti, prodotti-macchina e singole matricole (`MachineUnit`), usando
  rispettivamente `/anagrafica/cerca`, `/articoli/lista/` e
  `/crm_api/m14/search`.

Vedi `app/Support/Gestionale/GestionaleSyncRunner.php` per la logica
completa e `app/Mail/GestionaleSyncDigestMail.php` per l'email di riepilogo
mandata all'amministrazione dopo ogni esecuzione.
