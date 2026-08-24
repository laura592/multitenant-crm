<?php

namespace App\Support\HelpGuide;

/**
 * Passi del tour guidato (icona accanto alla campana notifiche, vedi
 * App\Livewire\TourGuide): un overlay stile "onboarding banca" che
 * evidenzia gli elementi reali della pagina uno alla volta.
 *
 * Ogni passo con 'element' punta a un selettore CSS reale, marcato a mano
 * con extraAttributes(['data-tour' => '...']) sul componente Filament
 * corrispondente (mai le classi auto-generate da Filament: cambiano tra
 * versioni, un tour rotto e' peggio di nessun tour). Un passo senza
 * 'element' e' un'introduzione centrata, non punta a nulla — utile come
 * primo passo per spiegare a cosa serve la pagina prima di indicare i
 * controlli veri. Il tour scarta da solo (lato JS, vedi resources/js/app.js)
 * i passi il cui selettore non esiste nel DOM in quel momento, cosi' un
 * elemento nascosto per il ruolo dell'utente o non ancora renderizzato non
 * blocca l'intero tour.
 *
 * 'waitFor' => true su un passo serve SOLO per un campo dentro la modale di
 * una risorsa Filament "ManageRecords" (es. Materiali, Marchi: il form si
 * apre in una modale sulla stessa pagina, non su una /create dedicata) —
 * dice al JS di aspettare che l'elemento compaia dopo che l'utente clicca il
 * vero pulsante "+ Crea" del passo precedente, invece di scartarlo subito.
 * Non aggiungerlo altrove: rallenta inutilmente ogni passo condizionale
 * (es. i campi lavaggio-only quando il tipo e' manutenzione) che invece deve
 * restare scartato all'istante.
 */
class TourRegistry
{
    /**
     * @return array<string, array<int, array{element?: string, waitFor?: bool, title: string, text: string}>>
     */
    public static function entries(): array
    {
        return [
            'service-reports' => [
                [
                    'title' => 'Rapportini',
                    'text' => 'Qui documenti gli interventi tecnici: manutenzione, sanificazione, riparazione, installazione. Ti guido passo passo nella compilazione di uno nuovo.',
                ],
                [
                    'element' => '[data-tour="service-reports-create"]',
                    'title' => 'Passo 1 — Crea il rapportino',
                    'text' => 'Clicca qui per aprire il form di un nuovo intervento.',
                ],
                [
                    'element' => '[data-tour="service-reports-field-customer"]',
                    'title' => 'Passo 2 — Cliente (obbligatorio)',
                    'text' => 'Cerca e seleziona il cliente presso cui è avvenuto l\'intervento. Se non esiste ancora, puoi crearlo al volo con il "+" accanto al campo, senza uscire dal rapportino.',
                ],
                [
                    'element' => '[data-tour="service-reports-field-type"]',
                    'title' => 'Passo 3 — Tipo intervento (obbligatorio)',
                    'text' => 'Manutenzione ordinaria/straordinaria, sanificazione, riparazione, installazione o garanzia. La scelta cambia anche quali campi/materiali compaiono più sotto.',
                ],
                [
                    'element' => '[data-tour="service-reports-field-work"]',
                    'title' => 'Passo 4 — Lavoro svolto (obbligatorio)',
                    'text' => 'Descrivi cosa hai fatto: è il testo che finisce nel PDF e nell\'email al cliente, scrivilo pensando che lo leggerà lui.',
                ],
                [
                    'element' => '[data-tour="service-reports-field-materials"]',
                    'title' => 'Passo 5 — Ricambi/materiali usati',
                    'text' => 'Facoltativo, ma se hai usato ricambi aggiungili qui: restano tracciati per lo storico del cliente e per gli ordini materiali futuri.',
                ],
                [
                    'element' => '[data-tour="service-reports-field-signature"]',
                    'title' => 'Passo 6 — Firma del cliente',
                    'text' => 'Fai firmare il cliente direttamente sullo schermo. Senza firma il rapportino resta comunque salvabile come bozza, ma non risulta chiuso.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Dopo il salvataggio puoi generare il PDF, controllare l\'anteprima email prima di inviarla, e (se il cliente è collegato a Eureka) inviare il rapportino anche al gestionale — tutto dai pulsanti sulla scheda del rapportino.',
                ],
            ],

            'customers' => [
                [
                    'title' => 'Clienti',
                    'text' => 'Anagrafica di tutti i locali/aziende seguite. Da qui apri la scheda cliente per vedere macchinari, piani di manutenzione, rapportini, preventivi e storico lavaggi tutti insieme. Ti guido nella creazione di un cliente nuovo.',
                ],
                [
                    'element' => '[data-tour="customers-create"]',
                    'title' => 'Passo 1 — Crea il cliente',
                    'text' => 'Clicca qui per aprire il form di una nuova anagrafica.',
                ],
                [
                    'element' => '[data-tour="customers-field-anagrafica"]',
                    'title' => 'Passo 2 — Anagrafica (obbligatoria)',
                    'text' => 'Serve almeno uno tra Ragione sociale (per un\'azienda) e Nome (per un referente privato) — non entrambi vuoti. Aggiungi qui anche email e telefoni.',
                ],
                [
                    'element' => '[data-tour="customers-field-address"]',
                    'title' => 'Passo 3 — Indirizzo',
                    'text' => 'Non obbligatorio per salvare, ma senza coordinate geografiche il cliente non comparirà nella pagina "Clienti vicini". Digitando l\'indirizzo compare un suggerimento con geocodifica automatica: usalo per compilare anche latitudine/longitudine.',
                ],
                [
                    'element' => '[data-tour="customers-field-billing"]',
                    'title' => 'Passo 4 — Fatturare a',
                    'text' => 'Lascialo vuoto se il cliente paga da sé. Impostalo solo se un altro cliente paga al posto suo (es. un gestore con macchine in comodato): preventivi e rapportini restano su questo cliente ma vengono intestati a quello scelto qui.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Dopo il salvataggio, dalla scheda del cliente puoi collegare macchinari, aprire un piano di manutenzione, un preventivo o un rapportino — tutto resta a colpo d\'occhio nella sua "Panoramica rapida".',
                ],
            ],

            'maintenance-schedules' => [
                [
                    'title' => 'Piani di manutenzione e lavaggio',
                    'text' => 'Un piano rappresenta un impegno ricorrente su un impianto: lavaggio (birra/vino/acqua/selz/bibite) o manutenzione ordinaria. La prossima scadenza si ricalcola da sola dall\'ultimo intervento registrato. Ti guido passo passo nella creazione di un piano nuovo.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-create"]',
                    'title' => 'Passo 1 — Crea il piano',
                    'text' => 'Clicca qui per aprire il form di un nuovo piano.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-customer"]',
                    'title' => 'Passo 2 — Cliente (obbligatorio)',
                    'text' => 'Il locale/azienda su cui è installato l\'impianto da seguire.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-machine"]',
                    'title' => 'Passo 3 — Macchina',
                    'text' => 'Compare solo dopo aver scelto il cliente, e mostra solo i macchinari attualmente installati presso di lui. Collegare la macchina specifica evita ambiguità quando il cliente ha più impianti (es. birra + vino).',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-type"]',
                    'title' => 'Passo 4 — Tipo (obbligatorio)',
                    'text' => 'Manutenzione (interventi ordinari programmati) oppure Lavaggio (sanificazione birra/vino/acqua/selz/bibite). La scelta cambia i campi successivi.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-beverage"]',
                    'title' => 'Passo 5 — Tipo impianto',
                    'text' => 'Solo per i piani Lavaggio: birra, vino, acqua... Impostando questo campo la Cadenza sotto si precompila da sola con il valore standard (es. birra 30 giorni, vino 90), ma resta modificabile.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-frequency"]',
                    'title' => 'Passo 6 — Cadenza (giorni)',
                    'text' => 'Ogni quanti giorni va ripetuto il lavaggio. Lasciala vuota per un piano "a chiamata", senza scadenza fissa: da qui in poi ogni nuovo lavaggio registrato sposterà da solo la prossima scadenza.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-field-due-date"]',
                    'title' => 'Passo 7 — Prossima scadenza',
                    'text' => 'Obbligatoria per la Manutenzione, oppure se hai impostato una Cadenza sopra. Da qui parte il conto alla rovescia mostrato nel widget "Piani in scadenza" della dashboard.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Dopo il salvataggio, registra ogni lavaggio/intervento eseguito dalla scheda del piano (sezioni "Lavaggi" e "Interventi" sotto): è da lì che la prossima scadenza si ricalcola in automatico.',
                ],
            ],

            'information-requests' => [
                [
                    'title' => 'Richieste informazioni',
                    'text' => 'Contatti/richieste da chi non è ancora cliente. Segui lo stato da "nuova" a "in lavorazione" fino a chiusa, e usa le note per tracciare chiamate/email fatte.',
                ],
                [
                    'element' => '[data-tour="information-requests-create"]',
                    'title' => 'Passo 1 — Registra la richiesta',
                    'text' => 'Clicca qui per aprire il form di una nuova richiesta.',
                ],
                [
                    'element' => '[data-tour="information-requests-field-customer"]',
                    'title' => 'Passo 2 — Cliente (obbligatorio)',
                    'text' => 'Anche se non è ancora un cliente vero e proprio, va comunque collegato a un\'anagrafica: se non esiste, creala al volo con il "+" accanto al campo. Sotto compaiono subito i suoi contatti salvati.',
                ],
                [
                    'element' => '[data-tour="information-requests-field-details"]',
                    'title' => 'Passo 3 — Dettagli richiesta',
                    'text' => 'Cosa vuole sapere/vedere: aiuta chiunque riprenda la richiesta in seguito a capire subito il contesto senza dover richiamare.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Imposta un appuntamento nella sezione sotto se ne fissi uno, e aggiorna lo Stato man mano che la richiesta avanza fino a chiusa.',
                ],
            ],

            'deadlines' => [
                [
                    'title' => 'Scadenzario',
                    'text' => 'Assicurazioni, bolli, revisioni, polizze, licenze, contratti. Il digest email del lunedì mattina ripete le scadenze urgenti finché non le rinnovi o le segni pagate.',
                ],
                [
                    'element' => '[data-tour="deadlines-create"]',
                    'title' => 'Aggiungi una scadenza',
                    'text' => 'Registra una nuova scadenza da qui. Quando rinnovi una scadenza esistente, il pulsante "Rinnova" propone già la nuova data.',
                ],
            ],

            'quotes' => [
                [
                    'title' => 'Preventivi',
                    'text' => 'Crea un preventivo dal catalogo prodotti, invialo al cliente e segui lo stato (inviato/accettato/rifiutato). Ti guido nella compilazione di uno nuovo.',
                ],
                [
                    'element' => '[data-tour="quotes-create"]',
                    'title' => 'Passo 1 — Crea il preventivo',
                    'text' => 'Clicca qui per aprire il form di un nuovo preventivo.',
                ],
                [
                    'element' => '[data-tour="quotes-field-customer"]',
                    'title' => 'Passo 2 — Cliente (obbligatorio)',
                    'text' => 'Il cliente a cui è intestato il preventivo. Se stai creando un preventivo alternativo dentro un\'Offerta globale, questo campo è già bloccato sullo stesso cliente dell\'offerta.',
                ],
                [
                    'element' => '[data-tour="quotes-field-payment"]',
                    'title' => 'Passo 3 — Metodo di pagamento',
                    'text' => 'Se scegli "Noleggio operativo" compaiono anche canone mensile e durata in mesi, usati per calcolare il totale del noleggio.',
                ],
                [
                    'title' => 'Passo 4 — Prodotti del preventivo',
                    'text' => 'Dopo il primo salvataggio, apri la tab con le righe del preventivo sulla stessa pagina: da lì aggiungi i prodotti dal catalogo con relativa quantità e sconto — i totali sotto si aggiornano da soli.',
                ],
                [
                    'element' => '[data-tour="quotes-recalculate"]',
                    'title' => 'Passo 5 — Ricalcola totali',
                    'text' => 'Se modifichi righe o sconti e i Totali mostrati non sembrano aggiornati, premi qui per ricalcolarli senza uscire dalla pagina.',
                ],
                [
                    'element' => '[data-tour="quotes-pdf"]',
                    'title' => 'Passo 6 — PDF',
                    'text' => 'Genera il PDF del preventivo pronto da inviare al cliente.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Aggiorna lo Stato (bozza/inviato/accettato/rifiutato) man mano che il preventivo avanza. Più preventivi per lo stesso cliente possono essere raggruppati in un\'Offerta globale per inviarli insieme.',
                ],
            ],

            'material-orders' => [
                [
                    'title' => 'Ordini materiali',
                    'text' => 'Ordini di ricambi/materiali ai fornitori. Aggiungi le righe dal catalogo Materiali e segui lo stato dell\'ordine.',
                ],
                [
                    'element' => '[data-tour="material-orders-create"]',
                    'title' => 'Passo 1 — Crea l\'ordine',
                    'text' => 'Clicca qui per aprire il form di un nuovo ordine.',
                ],
                [
                    'element' => '[data-tour="material-orders-field-supplier"]',
                    'title' => 'Passo 2 — Fornitore',
                    'text' => 'Facoltativo, ma compare nel PDF come destinatario dell\'ordine: senza, il PDF resta generico.',
                ],
                [
                    'element' => '[data-tour="material-orders-add-materials"]',
                    'title' => 'Passo 3 — Aggiungi materiali',
                    'text' => 'Dopo il primo salvataggio, usa questo pulsante per aggiungere le righe dal catalogo Materiali (o crearne uno nuovo al volo se non è a catalogo).',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Genera PDF o Excel dell\'ordine dai pulsanti in alto, pronti da inviare al fornitore.',
                ],
            ],

            'leave-requests' => [
                [
                    'title' => 'Ferie e permessi',
                    'text' => 'Richiedi ferie/permessi da qui; chi approva vede le richieste in attesa e conferma o rifiuta con un motivo.',
                ],
                [
                    'element' => '[data-tour="leave-requests-create"]',
                    'title' => 'Passo 1 — Richiedi',
                    'text' => 'Clicca qui per aprire il form di una nuova richiesta.',
                ],
                [
                    'element' => '[data-tour="leave-requests-field-type"]',
                    'title' => 'Passo 2 — Tipo (obbligatorio)',
                    'text' => 'Ferie, permesso (un solo giorno, con orari) o malattia. La malattia si può registrare anche a posteriori; ferie e permesso no, solo per giorni futuri.',
                ],
                [
                    'element' => '[data-tour="leave-requests-field-date"]',
                    'title' => 'Passo 3 — Data (obbligatoria)',
                    'text' => 'Il residuo ferie dell\'anno corrente compare come promemoria appena scegli il dipendente, sopra questo campo.',
                ],
                [
                    'element' => '[data-tour="leave-requests-approve"]',
                    'title' => 'Approvazione',
                    'text' => 'Chi ha i permessi per approvare vede questo pulsante sulla richiesta: approvando o rifiutando, il dipendente riceve una notifica.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'La richiesta resta "in attesa" finché non viene approvata o rifiutata da chi ne ha i permessi.',
                ],
            ],

            'machine-units' => [
                [
                    'title' => 'Macchinari',
                    'text' => 'Ogni macchina/impianto installato presso un cliente: matricola, modello, tipo, cliente attuale e chi la paga se diverso. Usa "Sposta" per un cambio sede, mantiene lo storico.',
                ],
                [
                    'element' => '[data-tour="machine-units-create"]',
                    'title' => 'Passo 1 — Registra il macchinario',
                    'text' => 'Clicca qui per aprire il form di un nuovo macchinario.',
                ],
                [
                    'element' => '[data-tour="machine-units-field-serial"]',
                    'title' => 'Passo 2 — Matricola (obbligatoria)',
                    'text' => 'Deve essere univoca all\'interno di questa azienda.',
                ],
                [
                    'element' => '[data-tour="machine-units-field-product"]',
                    'title' => 'Passo 3 — Modello (da catalogo)',
                    'text' => 'Se il modello è a catalogo Prodotti, collegalo qui. Se non lo è (macchina non a listino Alex), usa invece il campo "Modello (testo libero)" subito sotto.',
                ],
                [
                    'element' => '[data-tour="machine-units-field-billing"]',
                    'title' => 'Passo 4 — Fatturare a',
                    'text' => 'Lascia vuoto se paga il cliente presso cui è installata: impostalo solo se a pagare è un altro cliente (es. comodato).',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il macchinario nasce "In magazzino": usa l\'azione "Sposta" sulla sua scheda per installarlo presso un cliente, mantenendo lo storico degli spostamenti.',
                ],
            ],

            'clienti-vicini' => [
                [
                    'title' => 'Clienti vicini',
                    'text' => 'Trova i clienti più vicini alla tua posizione, ordinati per distanza, con accesso rapido a rapportino e navigazione.',
                ],
                [
                    'element' => '[data-tour="clienti-vicini-locate"]',
                    'title' => 'Passo 1 — Attiva la posizione',
                    'text' => 'Premi qui: il browser chiederà il permesso di geolocalizzazione.',
                ],
                [
                    'element' => '[data-tour="clienti-vicini-radius"]',
                    'title' => 'Passo 2 — Raggio',
                    'text' => 'Regola il raggio in km (o usa i pulsanti rapidi 1/2/3 km accanto): mappa e lista sotto si aggiornano da sole, senza bisogno di un pulsante "Cerca".',
                ],
                [
                    'element' => '[data-tour="clienti-vicini-open-report"]',
                    'title' => 'Passo 3 — Apri rapportino',
                    'text' => 'Da ogni cliente in lista, apri direttamente un nuovo rapportino già intestato a lui, o naviga verso il suo indirizzo con "Apri in Maps".',
                ],
            ],

            'materials' => [
                [
                    'title' => 'Materiali',
                    'text' => 'Catalogo ricambi/materiali usati negli interventi e negli ordini. Il "Prezzo di listino" (se presente) arriva da Eureka ed è solo di consultazione.',
                ],
                [
                    'element' => '[data-tour="materials-create"]',
                    'title' => 'Passo 1 — Aggiungi il materiale',
                    'text' => 'Clicca qui per aprire il form di un nuovo materiale. Nella pagina "Sync Eureka" trovi anche l\'import/scansione automatica dal gestionale, prima di crearne uno a mano.',
                ],
                [
                    'element' => '[data-tour="materials-field-code"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Codice (obbligatorio)',
                    'text' => 'Deve essere univoco: se corrisponde a un codice Eureka, il prezzo di listino potrà essere aggiornato automaticamente dal sync notturno.',
                ],
                [
                    'element' => '[data-tour="materials-field-category"]',
                    'waitFor' => true,
                    'title' => 'Passo 3 — Categoria (obbligatoria)',
                    'text' => 'Puoi scegliere una categoria esistente o crearne una nuova al volo.',
                ],
                [
                    'element' => '[data-tour="materials-field-type"]',
                    'waitFor' => true,
                    'title' => 'Passo 4 — Tipo (obbligatorio)',
                    'text' => 'Testo libero per specificare meglio il materiale (es. diametro, colore).',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il materiale è subito selezionabile nei rapportini e negli ordini materiali. Il prezzo di listino non viene mai usato per calcolare automaticamente i prezzi sui rapportini: è solo di consultazione.',
                ],
            ],

            'time-entries' => [
                [
                    'title' => 'Presenze',
                    'text' => 'Storico timbrature entrata/uscita/pausa pranzo. La timbratura vera si fa dal widget in dashboard, qui trovi lo storico e puoi correggere una voce.',
                ],
                [
                    'element' => '[data-tour="time-entries-create"]',
                    'title' => 'Passo 1 — Aggiungi una voce',
                    'text' => 'Registra manualmente una presenza da qui, se serve correggere lo storico.',
                ],
                [
                    'element' => '[data-tour="time-entries-field-user"]',
                    'title' => 'Passo 2 — Dipendente (obbligatorio)',
                    'text' => 'Scegliendo "Mattina" o "Pomeriggio" sopra e poi il dipendente, Entrata/Uscita si precompilano da sole con il suo orario standard, se ne ha uno impostato.',
                ],
                [
                    'element' => '[data-tour="time-entries-field-clock-in"]',
                    'title' => 'Passo 3 — Entrata (obbligatoria)',
                    'text' => 'Resta modificabile anche dopo la precompilazione automatica.',
                ],
                [
                    'element' => '[data-tour="time-entries-field-clock-out"]',
                    'title' => 'Passo 4 — Uscita',
                    'text' => 'Facoltativa: lasciala vuota per un turno ancora in corso.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il Riepilogo ore (nel menu Personale) somma queste voci per dipendente e periodo.',
                ],
            ],

            'riepilogo-ore' => [
                [
                    'title' => 'Riepilogo ore',
                    'text' => 'Totale ore lavorate per dipendente per periodo, calcolato dalle timbrature in Presenze.',
                ],
                [
                    'element' => '[data-tour="riepilogo-ore-field-month"]',
                    'title' => 'Passo 1 — Mese',
                    'text' => 'Il riepilogo si aggiorna da solo appena cambi mese, senza bisogno di un pulsante "Applica".',
                ],
                [
                    'element' => '[data-tour="riepilogo-ore-field-year"]',
                    'title' => 'Passo 2 — Anno',
                    'text' => 'Puoi risalire fino a due anni indietro.',
                ],
                [
                    'element' => '[data-tour="riepilogo-ore-export"]',
                    'title' => 'Passo 3 — Esporta',
                    'text' => 'Scarica il riepilogo del periodo selezionato in Excel da qui, utile per il controllo mensile.',
                ],
            ],

            'vehicles' => [
                [
                    'title' => 'Veicoli',
                    'text' => 'Parco veicoli aziendale. Le scadenze (bollo, assicurazione, revisione) si gestiscono dallo Scadenzario, collegate al veicolo.',
                ],
                [
                    'element' => '[data-tour="vehicles-create"]',
                    'title' => 'Passo 1 — Aggiungi il veicolo',
                    'text' => 'Clicca qui per aprire il form di un nuovo veicolo.',
                ],
                [
                    'element' => '[data-tour="vehicles-field-plate"]',
                    'title' => 'Passo 2 — Targa (obbligatoria)',
                    'text' => 'L\'unico campo obbligatorio. Marca, modello e anno sotto sono facoltativi.',
                ],
                [
                    'element' => '[data-tour="vehicles-field-assigned"]',
                    'title' => 'Passo 3 — Assegnato a',
                    'text' => 'Facoltativo: il dipendente che usa abitualmente questo veicolo.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Per bollo/assicurazione/revisione, registrale nello Scadenzario collegandole a questo veicolo: compariranno anche nelle colonne Assicurazione/Revisione qui in tabella.',
                ],
            ],

            'gestionale-sync-review' => [
                [
                    'title' => 'Sync Eureka',
                    'text' => 'Cosa hanno trovato i controlli automatici notturni con Eureka: differenze da rivedere e nuovi collegamenti proposti — mai scritti su Eureka né assegnati da soli, confermi o scarti tu.',
                ],
                [
                    'element' => '[data-tour="gestionale-sync-review-import"]',
                    'title' => 'Importa rapportini da Eureka',
                    'text' => 'Gira già ogni notte da solo sugli ultimi 7 giorni: usa questo pulsante solo se non vuoi aspettare fino a domani per un rapportino/materiale appena inserito su Eureka.',
                ],
                [
                    'element' => '[data-tour="gestionale-sync-review-prices"]',
                    'title' => 'Aggiorna prezzi materiali',
                    'text' => 'Anche questo gira già ogni notte: forza qui un ricontrollo immediato dei prezzi di listino di tutti i materiali già a catalogo.',
                ],
                [
                    'element' => '[data-tour="gestionale-sync-review-sweep"]',
                    'title' => 'Scansiona catalogo materiali',
                    'text' => 'Gira già ogni lunedì mattina: cerca su Eureka materiali mai referenziati in un rapportino e li crea a catalogo. Può richiedere qualche minuto.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Le tabelle sopra elencano collegamenti clienti/prodotti/macchinari proposti dal sync e i clienti segnalati da controllare: conferma, scarta o segna come controllato riga per riga — nulla viene scritto su Eureka o assegnato da solo.',
                ],
            ],

            'products' => [
                [
                    'title' => 'Prodotti',
                    'text' => 'Catalogo ufficiale usato nei preventivi: macchine, opzioni, servizi. Un prodotto con SKU "." è quasi sempre un placeholder da un import Eureka senza descrizione, va sistemato quando lo noti.',
                ],
                [
                    'element' => '[data-tour="products-create"]',
                    'title' => 'Passo 1 — Aggiungi il prodotto',
                    'text' => 'Clicca qui per aprire il form di un nuovo prodotto.',
                ],
                [
                    'element' => '[data-tour="products-field-type"]',
                    'title' => 'Passo 2 — Tipo (obbligatorio)',
                    'text' => 'Macchina, unità ausiliaria, opzione, accessorio o servizio/licenza. Scegliendo "Macchina" compare anche il campo Famiglia macchina sotto.',
                ],
                [
                    'element' => '[data-tour="products-field-sku"]',
                    'title' => 'Passo 3 — SKU (obbligatorio)',
                    'text' => 'Codice univoco del prodotto: non può ripetersi nel catalogo.',
                ],
                [
                    'element' => '[data-tour="products-field-name"]',
                    'title' => 'Passo 4 — Nome (obbligatorio)',
                    'text' => 'Come comparirà nei preventivi e nel catalogo.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Categoria e Brand sono facoltativi ma aiutano a filtrare il catalogo. Il prezzo corrente arriva dal Listino attivo più recente, non si imposta qui sul prodotto.',
                ],
            ],

            'brands' => [
                [
                    'title' => 'Marchi',
                    'text' => 'Marchi usati per classificare i prodotti nel catalogo (es. Franke). Servono a filtrare/organizzare il catalogo Prodotti.',
                ],
                [
                    'element' => '[data-tour="brands-create"]',
                    'title' => 'Passo 1 — Crea il marchio',
                    'text' => 'Clicca qui per aprire il form di un nuovo marchio.',
                ],
                [
                    'element' => '[data-tour="brands-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Deve essere univoco: non puoi registrare due marchi con lo stesso nome.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il marchio è subito selezionabile nel form dei Prodotti.',
                ],
            ],

            'categories' => [
                [
                    'title' => 'Categorie',
                    'text' => 'Categorie del catalogo prodotti, usate per organizzare/filtrare in Prodotti e nei preventivi.',
                ],
                [
                    'element' => '[data-tour="categories-create"]',
                    'title' => 'Passo 1 — Crea la categoria',
                    'text' => 'Clicca qui per aprire il form di una nuova categoria.',
                ],
                [
                    'element' => '[data-tour="categories-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Il nome della categoria come comparirà nei filtri.',
                ],
                [
                    'element' => '[data-tour="categories-field-parent"]',
                    'waitFor' => true,
                    'title' => 'Passo 3 — Categoria padre',
                    'text' => 'Facoltativa: impostala solo se questa è una sottocategoria (es. "Macchine da caffè" sotto "Macchine"). L\'elenco si raggruppa da solo per gerarchia.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'La categoria è subito selezionabile nel form dei Prodotti.',
                ],
            ],

            'product-families' => [
                [
                    'title' => 'Famiglie prodotto',
                    'text' => 'Raggruppano più prodotti/varianti che condividono caratteristiche comuni — organizzano il catalogo su un livello più ampio delle categorie.',
                ],
                [
                    'element' => '[data-tour="product-families-create"]',
                    'title' => 'Passo 1 — Crea la famiglia',
                    'text' => 'Clicca qui per aprire il form di una nuova famiglia.',
                ],
                [
                    'element' => '[data-tour="product-families-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Es. "A300": il nome che raggrupperà tutte le varianti di questa famiglia. Ordine, descrizione e immagine sotto sono facoltativi, usati per l\'aspetto nel catalogo.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'La famiglia è subito selezionabile nel form dei Prodotti.',
                ],
            ],

            'price-lists' => [
                [
                    'title' => 'Listini',
                    'text' => 'Listini prezzo usati per calcolare i prezzi correnti dei prodotti nei preventivi. Il prezzo "corrente" di un prodotto viene sempre dal listino attivo più recente.',
                ],
                [
                    'element' => '[data-tour="price-lists-create"]',
                    'title' => 'Passo 1 — Crea il listino',
                    'text' => 'Clicca qui per aprire il form di un nuovo listino.',
                ],
                [
                    'element' => '[data-tour="price-lists-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Un nome che ti aiuti a riconoscerlo (es. fornitore + anno).',
                ],
                [
                    'element' => '[data-tour="price-lists-field-supplier"]',
                    'waitFor' => true,
                    'title' => 'Passo 3 — Fornitore',
                    'text' => 'Facoltativo, ma collegarlo aiuta a trovare rapidamente tutti i listini di un fornitore.',
                ],
                [
                    'element' => '[data-tour="price-lists-field-file"]',
                    'waitFor' => true,
                    'title' => 'Passo 4 — File PDF',
                    'text' => 'Carica qui il PDF del listino. Per sostituirlo in futuro carica un nuovo file: non è possibile rimuoverlo senza sostituirlo, e viene ottimizzato automaticamente se troppo pesante.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Imposta "Valido dal"/"Valido fino al" per tracciare il periodo di validità: lascia vuoto "fino al" se non ha una scadenza nota.',
                ],
            ],

            'suppliers' => [
                [
                    'title' => 'Fornitori',
                    'text' => 'Anagrafica fornitori, collegata a Materiali e Ordini materiali per sapere a chi ordinare un ricambio.',
                ],
                [
                    'element' => '[data-tour="suppliers-create"]',
                    'title' => 'Passo 1 — Crea il fornitore',
                    'text' => 'Clicca qui per aprire il form di un nuovo fornitore.',
                ],
                [
                    'element' => '[data-tour="suppliers-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Ragione sociale (obbligatoria)',
                    'text' => 'L\'unico campo obbligatorio. Sotto puoi aggiungere anche indirizzo, telefono, email e note: tutti facoltativi.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il fornitore è subito selezionabile nel form di Materiali, Listini e Ordini materiali.',
                ],
            ],

            'payment-methods' => [
                [
                    'title' => 'Metodi di pagamento',
                    'text' => 'Elenco dei metodi di pagamento selezionabili sui preventivi (es. bonifico, contanti, RID).',
                ],
                [
                    'element' => '[data-tour="payment-methods-create"]',
                    'title' => 'Passo 1 — Crea il metodo',
                    'text' => 'Clicca qui per aprire il form di un nuovo metodo.',
                ],
                [
                    'element' => '[data-tour="payment-methods-field-name"]',
                    'waitFor' => true,
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Lo slug sotto si genera da solo dal nome digitato, ma resta modificabile.',
                ],
                [
                    'element' => '[data-tour="payment-methods-field-active"]',
                    'waitFor' => true,
                    'title' => 'Passo 3 — Attivo',
                    'text' => 'Solo i metodi attivi compaiono come opzione selezionabile nei preventivi: disattiva qui quelli che non usi più senza doverli eliminare.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Il campo "Ordine" nel form (numero più basso prima) decide la sequenza con cui compaiono nel menu a tendina dei preventivi.',
                ],
            ],

            'quote-groups' => [
                [
                    'title' => 'Offerte globali',
                    'text' => 'Un\'offerta globale raggruppa più preventivi dello stesso cliente in un unico invio/PDF — utile per proporre più soluzioni alternative insieme invece di preventivi separati.',
                ],
                [
                    'element' => '[data-tour="quote-groups-create"]',
                    'title' => 'Passo 1 — Crea l\'offerta',
                    'text' => 'Clicca qui per aprire il form di una nuova offerta globale.',
                ],
                [
                    'element' => '[data-tour="quote-groups-new-quote"]',
                    'title' => 'Passo 2 — Aggiungi preventivo',
                    'text' => 'Dopo il primo salvataggio, usa questo pulsante per creare un nuovo preventivo già agganciato a questa offerta: puoi aggiungerne più di uno come soluzioni alternative per lo stesso cliente.',
                ],
                [
                    'element' => '[data-tour="quote-groups-send"]',
                    'title' => 'Passo 3 — Invia gruppo',
                    'text' => 'Invia tutti i preventivi dell\'offerta insieme in un\'unica email al cliente.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Quando il cliente sceglie una soluzione, aggiorna lo Stato di quel preventivo su "Accettato" dalla sua pagina: comparirà da solo come "Soluzione scelta" nel riepilogo qui in alto.',
                ],
            ],

            'audit-logs' => [
                [
                    'title' => 'Log modifiche',
                    'text' => 'Cronologia di sola lettura delle modifiche fatte nel sistema: chi ha cambiato cosa e quando. Utile per capire "chi ha toccato questo record" — non modificabile da qui. Usa i filtri in alto alla tabella (Modello, Utente, Intervallo date) per restringere la ricerca a un periodo o una persona specifica.',
                ],
            ],

            'users' => [
                [
                    'title' => 'Utenti',
                    'text' => 'Account del personale che accede al pannello, con il ruolo assegnato (tecnico/amministrazione/amministratore/partner) che determina cosa può vedere e fare.',
                ],
                [
                    'element' => '[data-tour="users-create"]',
                    'title' => 'Passo 1 — Crea l\'utente',
                    'text' => 'Clicca qui per aprire il form di un nuovo account.',
                ],
                [
                    'element' => '[data-tour="users-field-email"]',
                    'title' => 'Passo 2 — Email (obbligatoria)',
                    'text' => 'Deve essere univoca: è anche la credenziale di accesso al pannello.',
                ],
                [
                    'element' => '[data-tour="users-field-password"]',
                    'title' => 'Passo 3 — Password (obbligatoria alla creazione)',
                    'text' => 'In modifica lasciala vuota per non cambiarla.',
                ],
                [
                    'element' => '[data-tour="users-field-role"]',
                    'title' => 'Passo 4 — Ruolo (obbligatorio)',
                    'text' => 'Ogni utente ha un solo ruolo, ed è quello a determinare cosa può vedere e fare nel pannello: assegnalo con attenzione.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Ore giornaliere/settimanali e giorni ferie annui più sotto servono per calcolare correttamente Presenze e ferie residue di questo dipendente.',
                ],
            ],

            'tenants' => [
                [
                    'title' => 'Aziende (tenant)',
                    'text' => 'Le aziende/organizzazioni distinte gestite da questo pannello: ognuna vede solo i propri dati. Qui si configurano anche le credenziali Eureka.',
                ],
                [
                    'element' => '[data-tour="tenants-create"]',
                    'title' => 'Passo 1 — Aggiungi l\'azienda',
                    'text' => 'Clicca qui per aprire il form di una nuova azienda.',
                ],
                [
                    'element' => '[data-tour="tenants-field-name"]',
                    'title' => 'Passo 2 — Nome (obbligatorio)',
                    'text' => 'Lo slug sotto (l\'indirizzo del pannello, es. /admin/nome-azienda) si genera da solo dal nome, ma resta modificabile.',
                ],
                [
                    'element' => '[data-tour="tenants-field-active"]',
                    'title' => 'Passo 3 — Attivo',
                    'text' => 'Un\'azienda disattivata non è più accessibile ai suoi utenti.',
                ],
                [
                    'title' => 'Fatto',
                    'text' => 'Ragione sociale, P.IVA e IBAN sotto compaiono nei documenti generati (PDF preventivi/rapportini) di questa azienda; logo e colore nella sezione "Branding" personalizzano l\'aspetto dei suoi documenti.',
                ],
            ],

            'notification-settings' => [
                [
                    'title' => 'Notifiche',
                    'text' => 'Per ogni categoria (rapportini, scadenze, richieste, preventivi, ferie, sync Eureka...) scegli quali indirizzi email ricevono l\'avviso, per questa azienda.',
                ],
                [
                    'element' => '[data-tour="notification-settings-field-first"]',
                    'title' => 'Passo 1 — Aggiungi un indirizzo',
                    'text' => 'Digita l\'email e premi virgola o Tab per aggiungerla come tag: puoi inserirne più di una per categoria. Stesso meccanismo per ogni categoria sotto — una per ogni tipo di evento.',
                ],
                [
                    'element' => '[data-tour="notification-settings-save"]',
                    'title' => 'Passo 2 — Salva',
                    'text' => 'Ricordati di salvare dopo ogni modifica agli indirizzi: senza salvare, le modifiche si perdono.',
                ],
            ],
        ];
    }

    public static function forSlug(string $slug): ?array
    {
        return self::entries()[$slug] ?? null;
    }
}
