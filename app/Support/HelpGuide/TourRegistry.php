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
 */
class TourRegistry
{
    /**
     * @return array<string, array<int, array{element?: string, title: string, text: string}>>
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
                    'title' => 'Registra una richiesta',
                    'text' => 'Aggiungi una nuova richiesta informazioni da qui.',
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
                    'title' => 'Nuovo ordine',
                    'text' => 'Crea un nuovo ordine materiali da qui.',
                ],
            ],

            'leave-requests' => [
                [
                    'title' => 'Ferie e permessi',
                    'text' => 'Richiedi ferie/permessi da qui; chi approva vede le richieste in attesa e conferma o rifiuta con un motivo.',
                ],
                [
                    'element' => '[data-tour="leave-requests-create"]',
                    'title' => 'Richiedi ferie o permesso',
                    'text' => 'Apri il form per una nuova richiesta da qui.',
                ],
            ],

            'machine-units' => [
                [
                    'title' => 'Macchinari',
                    'text' => 'Ogni macchina/impianto installato presso un cliente: matricola, modello, tipo, cliente attuale e chi la paga se diverso. Usa "Sposta" per un cambio sede, mantiene lo storico.',
                ],
                [
                    'element' => '[data-tour="machine-units-create"]',
                    'title' => 'Registra un macchinario',
                    'text' => 'Aggiungi un nuovo macchinario da qui.',
                ],
            ],

            'clienti-vicini' => [
                [
                    'title' => 'Clienti vicini',
                    'text' => 'Trova i clienti più vicini alla tua posizione, ordinati per distanza, con accesso rapido a rapportino e navigazione.',
                ],
                [
                    'element' => '[data-tour="clienti-vicini-locate"]',
                    'title' => 'Attiva la posizione',
                    'text' => 'Premi qui: il browser chiederà il permesso, poi scegli il raggio in km e la mappa/lista si aggiornano da sole.',
                ],
            ],

            'materials' => [
                [
                    'title' => 'Materiali',
                    'text' => 'Catalogo ricambi/materiali usati negli interventi e negli ordini. Il "Prezzo di listino" (se presente) arriva da Eureka ed è solo di consultazione.',
                ],
                [
                    'element' => '[data-tour="materials-create"]',
                    'title' => 'Aggiungi un materiale',
                    'text' => 'Registra un nuovo materiale a catalogo da qui.',
                ],
            ],

            'time-entries' => [
                [
                    'title' => 'Presenze',
                    'text' => 'Storico timbrature entrata/uscita/pausa pranzo. La timbratura vera si fa dal widget in dashboard, qui trovi lo storico e puoi correggere una voce.',
                ],
                [
                    'element' => '[data-tour="time-entries-create"]',
                    'title' => 'Aggiungi una voce',
                    'text' => 'Registra manualmente una presenza da qui, se serve correggere lo storico.',
                ],
            ],

            'riepilogo-ore' => [
                [
                    'title' => 'Riepilogo ore',
                    'text' => 'Totale ore lavorate per dipendente per periodo, calcolato dalle timbrature. Scegli mese e anno per filtrare.',
                ],
                [
                    'element' => '[data-tour="riepilogo-ore-export"]',
                    'title' => 'Esporta il riepilogo',
                    'text' => 'Scarica il riepilogo in Excel da qui, utile per il controllo mensile.',
                ],
            ],

            'vehicles' => [
                [
                    'title' => 'Veicoli',
                    'text' => 'Parco veicoli aziendale. Le scadenze (bollo, assicurazione, revisione) si gestiscono dallo Scadenzario, collegate al veicolo.',
                ],
                [
                    'element' => '[data-tour="vehicles-create"]',
                    'title' => 'Aggiungi un veicolo',
                    'text' => 'Registra un nuovo veicolo da qui.',
                ],
            ],

            'gestionale-sync-review' => [
                [
                    'title' => 'Sync Eureka',
                    'text' => 'Cosa hanno trovato i controlli automatici notturni con Eureka: differenze da rivedere e nuovi collegamenti proposti — mai scritti su Eureka né assegnati da soli, confermi o scarti tu.',
                ],
                [
                    'element' => '[data-tour="gestionale-sync-review-import"]',
                    'title' => 'Forza un import',
                    'text' => 'Se non vuoi aspettare il giro notturno, forza qui l\'import di rapportini/materiali da Eureka.',
                ],
            ],

            'products' => [
                [
                    'title' => 'Prodotti',
                    'text' => 'Catalogo ufficiale usato nei preventivi: macchine, opzioni, servizi. Un prodotto con SKU "." è quasi sempre un placeholder da un import Eureka senza descrizione, va sistemato quando lo noti.',
                ],
                [
                    'element' => '[data-tour="products-create"]',
                    'title' => 'Aggiungi un prodotto',
                    'text' => 'Registra un nuovo prodotto a catalogo da qui.',
                ],
            ],

            'brands' => [
                [
                    'title' => 'Marchi',
                    'text' => 'Marchi usati per classificare i prodotti nel catalogo (es. Franke). Servono a filtrare/organizzare il catalogo Prodotti.',
                ],
                [
                    'element' => '[data-tour="brands-create"]',
                    'title' => 'Aggiungi un marchio',
                    'text' => 'Registra un nuovo marchio da qui.',
                ],
            ],

            'categories' => [
                [
                    'title' => 'Categorie',
                    'text' => 'Categorie del catalogo prodotti, usate per organizzare/filtrare in Prodotti e nei preventivi.',
                ],
                [
                    'element' => '[data-tour="categories-create"]',
                    'title' => 'Aggiungi una categoria',
                    'text' => 'Registra una nuova categoria da qui.',
                ],
            ],

            'product-families' => [
                [
                    'title' => 'Famiglie prodotto',
                    'text' => 'Raggruppano più prodotti/varianti che condividono caratteristiche comuni — organizzano il catalogo su un livello più ampio delle categorie.',
                ],
                [
                    'element' => '[data-tour="product-families-create"]',
                    'title' => 'Aggiungi una famiglia',
                    'text' => 'Registra una nuova famiglia prodotto da qui.',
                ],
            ],

            'price-lists' => [
                [
                    'title' => 'Listini',
                    'text' => 'Listini prezzo usati per calcolare i prezzi correnti dei prodotti nei preventivi. Il prezzo "corrente" di un prodotto viene sempre dal listino attivo più recente.',
                ],
                [
                    'element' => '[data-tour="price-lists-create"]',
                    'title' => 'Aggiungi un listino',
                    'text' => 'Registra un nuovo listino da qui.',
                ],
            ],

            'suppliers' => [
                [
                    'title' => 'Fornitori',
                    'text' => 'Anagrafica fornitori, collegata a Materiali e Ordini materiali per sapere a chi ordinare un ricambio.',
                ],
                [
                    'element' => '[data-tour="suppliers-create"]',
                    'title' => 'Aggiungi un fornitore',
                    'text' => 'Registra un nuovo fornitore da qui.',
                ],
            ],

            'payment-methods' => [
                [
                    'title' => 'Metodi di pagamento',
                    'text' => 'Elenco dei metodi di pagamento selezionabili sui preventivi (es. bonifico, contanti, RID).',
                ],
                [
                    'element' => '[data-tour="payment-methods-create"]',
                    'title' => 'Aggiungi un metodo',
                    'text' => 'Registra un nuovo metodo di pagamento da qui.',
                ],
            ],

            'quote-groups' => [
                [
                    'title' => 'Offerte globali',
                    'text' => 'Un\'offerta globale raggruppa più preventivi dello stesso cliente in un unico invio/PDF — utile per proporre più righe insieme invece di preventivi separati.',
                ],
                [
                    'element' => '[data-tour="quote-groups-create"]',
                    'title' => 'Crea un\'offerta globale',
                    'text' => 'Raggruppa preventivi esistenti in una nuova offerta da qui.',
                ],
            ],

            'audit-logs' => [
                [
                    'title' => 'Log modifiche',
                    'text' => 'Cronologia di sola lettura delle modifiche fatte nel sistema: chi ha cambiato cosa e quando. Utile per capire "chi ha toccato questo record" — non modificabile da qui.',
                ],
            ],

            'users' => [
                [
                    'title' => 'Utenti',
                    'text' => 'Account del personale che accede al pannello, con il ruolo assegnato (tecnico/amministrazione/amministratore/partner) che determina cosa può vedere e fare.',
                ],
                [
                    'element' => '[data-tour="users-create"]',
                    'title' => 'Aggiungi un utente',
                    'text' => 'Crea un nuovo account e assegna il ruolo da qui.',
                ],
            ],

            'tenants' => [
                [
                    'title' => 'Aziende (tenant)',
                    'text' => 'Le aziende/organizzazioni distinte gestite da questo pannello: ognuna vede solo i propri dati. Qui si configurano anche le credenziali Eureka.',
                ],
                [
                    'element' => '[data-tour="tenants-create"]',
                    'title' => 'Aggiungi un\'azienda',
                    'text' => 'Registra una nuova azienda da qui.',
                ],
            ],

            'notification-settings' => [
                [
                    'title' => 'Notifiche',
                    'text' => 'Per ogni categoria (rapportini, scadenze, richieste, preventivi, ferie, sync Eureka...) scegli quali indirizzi email ricevono l\'avviso.',
                ],
                [
                    'element' => '[data-tour="notification-settings-save"]',
                    'title' => 'Salva',
                    'text' => 'Ricordati di salvare dopo ogni modifica agli indirizzi.',
                ],
            ],
        ];
    }

    public static function forSlug(string $slug): ?array
    {
        return self::entries()[$slug] ?? null;
    }
}
