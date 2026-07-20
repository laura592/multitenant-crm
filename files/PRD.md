# PRD — Gestionale Alex S.r.l.

## 1. Contesto

Alex S.r.l. è un Franke Approved Partner for Coffee Systems (Veneto, Emilia-Romagna,
Marche, Friuli Venezia Giulia). Il gestionale sostituisce/estende l'attuale app
Laravel su `app.alexcaffe.com` e unifica in un solo prodotto:

- Catalogo prodotti configurabili e listini
- Portale partner (sub-dealer come GIFAR) per preventivi/ordini
- Gestione HR interna (presenze, ferie, straordinari)
- Scadenzario manutenzioni clienti + mezzi aziendali
- Rapportini tecnici (compilabili offline da tablet)
- Magazzino ricambi

## 2. Utenti e ruoli

| Ruolo | Accesso | Note |
|---|---|---|
| Admin | Panel `/admin`, tutto | |
| Ufficio | Panel `/admin`, no gestione prezzi | RBAC granulare |
| Magazziniere | Panel `/admin`, solo modulo magazzino | |
| Tecnico | Solo app Flutter (mai il panel web) | Offline-first |
| Partner (es. GIFAR) | Panel `/partner` | Scoping per brand + per proprio partner_id |

## 3. Moduli funzionali

### 3.1 Catalogo prodotti configurabili
- Prodotti organizzati in categorie, con brand (Franke, Dalla Corte, Jura, Universale/Accessori)
- Prodotti "configurabili" (es. macchina caffè) hanno slot di componenti opzionali
  (frigo, macinini) con regole min/max e compatibilità
- Un brand speciale "Universale" copre i componenti cross-brand (es. frigo compatibile
  con più marche); ogni partner riceve accesso automatico a "Universale" oltre ai brand assegnati

### 3.2 Listini
- Listino unico globale (no listini per-partner)
- Visibilità prodotti filtrata per brand assegnato al partner (non per prezzo)
- Storico modifiche prezzi tracciato (audit log)

### 3.3 Preventivi e ordini (portale partner)
- Wizard di configurazione per ogni macchina aggiunta al preventivo (step: prodotto
  base → slot componenti filtrati per brand/compatibilità → riepilogo prezzo)
- Preventivo può contenere più macchine, ciascuna configurata indipendentemente
- Alla conferma: congelamento (snapshot) di configurazione e prezzi — mai ricalcolo
  retroattivo se il listino cambia dopo
- Ordine generato da preventivo confermato, con tracking stato

### 3.4 HR — presenze, ferie, straordinari
- Timbrature da web (ufficio) o da tablet (tecnici, anche offline)
- Un solo turno aperto per dipendente per volta
- Richieste ferie con workflow approvazione (Ufficio/Admin)
- Straordinari: calcolo automatico a consuntivo (ore lavorate vs contrattuali da
  `employee_contracts`, con supporto a contratti che cambiano nel tempo), nessun
  flusso di richiesta/approvazione preventiva

### 3.5 Scadenzario (impianti clienti + mezzi aziendali)
- Modello polimorfico unico: `deadlines` collegato a `installations` (impianti
  presso i clienti) o `vehicles` (mezzi aziendali)
- Categorie di scadenza con frequenza di default (es. lavaggio impianto birra
  mensile, manutenzione caffè semestrale, revisione mezzo annuale)
- Chiusura scadenza: automatica per gli impianti (alla creazione del rapportino
  collegato), manuale per i mezzi (azione in Filament)
- Notifica interna (bell + email) alle scadenze in avvicinamento

### 3.6 Rapportini tecnici
- Compilabili da Flutter (offline) o da Filament (fallback quando c'è rete)
- **Immutabili una volta firmati** — nessuna modifica successiva
- Alla sincronizzazione: generazione PDF automatica + invio email ad
  amministrazione interna e cliente (se il cliente ha l'email censita; altrimenti
  solo interna, con flag "email cliente non inviata")
- Registrano: cliente, impianto, data/ora, firma, componenti sostituiti

### 3.7 Magazzino ricambi
- Giacenze componenti con soglia minima di riordino
- Scarico automatico alla registrazione di un rapportino con componente sostituito
- Notifica quando si scende sotto soglia

### 3.8 Notifiche push (tecnici)
- Via Firebase Cloud Messaging
- Eventi: intervento assegnato, ferie approvate/rifiutate
- Deep link al record specifico nell'app

## 4. Fuori scope (per ora)
- Fatturazione elettronica / integrazione contabilità
- Dashboard KPI avanzate (solo report base via Filament)
- Multi-lingua
