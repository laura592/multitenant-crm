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
                    'text' => 'Qui documenti gli interventi tecnici: manutenzione, sanificazione, riparazione, installazione. Ti mostro i passaggi principali.',
                ],
                [
                    'element' => '[data-tour="service-reports-create"]',
                    'title' => 'Crea un rapportino',
                    'text' => 'Da qui apri il form per un nuovo intervento: cliente, macchina, lavoro svolto, ricambi usati ed eventuale firma del cliente.',
                ],
            ],

            'customers' => [
                [
                    'title' => 'Clienti',
                    'text' => 'Anagrafica di tutti i locali/aziende seguite. Da qui apri la scheda cliente per vedere macchinari, piani di manutenzione, rapportini, preventivi e storico lavaggi tutti insieme.',
                ],
                [
                    'element' => '[data-tour="customers-create"]',
                    'title' => 'Aggiungi un cliente',
                    'text' => 'Crea una nuova anagrafica cliente da qui.',
                ],
            ],

            'maintenance-schedules' => [
                [
                    'title' => 'Piani di manutenzione e lavaggio',
                    'text' => 'Un piano rappresenta un impegno ricorrente su un impianto: lavaggio (birra/vino/acqua/selz/bibite) o manutenzione ordinaria. La prossima scadenza si ricalcola da sola dall\'ultimo intervento registrato.',
                ],
                [
                    'element' => '[data-tour="maintenance-schedules-create"]',
                    'title' => 'Crea un piano',
                    'text' => 'Aggiungi un nuovo piano di manutenzione o lavaggio da qui.',
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
                    'text' => 'Crea un preventivo dal catalogo prodotti, invialo al cliente e segui lo stato (inviato/accettato/rifiutato).',
                ],
                [
                    'element' => '[data-tour="quotes-create"]',
                    'title' => 'Crea un preventivo',
                    'text' => 'Apri il form per un nuovo preventivo da qui.',
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
