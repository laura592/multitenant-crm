{{-- Gli stili del rapportino, staccati dal documento perche' li usa anche
     pdf/rapportini-per-fattura.blade.php, che impagina piu' rapportini di
     fila dentro un unico PDF. Vengono inclusi dentro un <style>. --}}
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }

        @include('pdf.partials.letterhead-styles')
        @include('pdf.partials.document-styles')

        {{-- Stessi identici stili base di pdf/quote.blade.php, condivisi via
             pdf/partials/document-styles.blade.php (.section-title,
             .info-box, table.items, .footer-note, ...): i PDF del sistema
             condividono un unico linguaggio visivo, il rapportino non deve
             avere il suo. Vedi anche pdf/ordine-materiali.blade.php per lo
             stesso principio applicato a un documento piu' semplice. --}}
        {{-- Tre colonne sulla stessa riga (luogo intervento, fatturazione,
             dati intervento): quando una cella ha piu' righe delle altre
             (es. fatturazione con tutti i dati fiscali contro luogo
             intervento con solo indirizzo) il bordo/sfondo che segna il box
             deve restare sulla <td> stessa, non su un div dentro — le celle
             di una riga si "stirano" sempre alla piu' alta, e cosi' anche
             bordo e sfondo la seguono. Un div interno invece si fermerebbe
             alla propria altezza naturale, lasciando i tre box sfalsati in
             fondo. .col-gap e' una cella vuota di distacco tra i tre
             (niente padding sulla stessa <td> che porta gia' il bordo: la
             spingerebbe dentro invece di separare i box). --}}
        .row-table { table-layout: fixed; }
        .col-half { width: 49%; }
        .col-gap { width: 2%; }
        {{-- Stessa riga larghezza asimmetrica del riquadro firma: la firma
             ha bisogno di piu' spazio del cartellino tecnico/tipo a fianco. --}}
        .col-sig { width: 65%; }
        .col-tech { width: 33%; }
        {{-- Piu' respiro dentro le schede (sede, nome, ecc. non piu'
             attaccati ai bordi): padding aumentato sia sulla cella che
             fa da cornice (.header-cell) sia sulle righe label/valore
             dentro (.info-box td). --}}
        {{-- .row-table td.header-cell (non solo .header-cell): serve piu'
             specificita' di ".row-table td", altrimenti il suo
             padding:0 vince ugualmente ed e' proprio quello che incollava
             nome/sede al bordo della scheda. --}}
        {{-- Il padding "respiro" vive sull'.info-box interno, non sulla
             td.header-cell: se lo si mette sulla cella si tira dentro anche
             la barra blu del .section-title, che risulta rientrata rispetto
             a tutte le altre barre blu del documento (Macchina, Note, Firma
             cliente, ...) che invece toccano il margine pagina — le barre
             blu devono restare tutte a filo tra loro. --}}
        .row-table td.header-cell { border: 1px solid #e5e7eb; background: #f9fafb; padding: 0; }
        .header-cell .section-title { margin: 0 0 8px; }
        .header-cell .info-box { border: none; background: transparent; padding: 14px 16px; }

        .info-box { padding: 10px 12px; }
        .info-box .customer-name { font-size: 11px; margin-bottom: 8px; padding-bottom: 6px; }
        .info-box td { padding: 3px 0; }
        .info-box td.label { padding-right: 8px; }

        .text-box { margin-top: 0; }
        .text-box p { margin: 0; padding: 8px 10px; background: #f9fafb; border: 1px solid #e5e7eb; white-space: pre-line; }

        {{-- Macchina/Problema riscontrato/Lavoro svolto unificati in un solo
             riquadro "Intervento" (un solo bandone blu invece di tre) —
             testo interno piu' grande (11px vs i 10px del body) perche' e'
             il contenuto che il cliente legge per capire cos'e' stato fatto,
             non deve essere il piu' piccolo del documento. --}}
        .intervento-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px 14px; }
        .intervento-block + .intervento-block { margin-top: 12px; }
        .intervento-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #020F30; margin-bottom: 4px; }
        .intervento-text { font-size: 11px; line-height: 1.5; white-space: pre-line; }
        .intervento-box table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .intervento-box table td { padding: 3px 0; }
        .intervento-box table td.label { font-weight: bold; color: #4b5563; padding-right: 8px; white-space: nowrap; }

        {{-- margin-top:0 (non 4px come nel partial condiviso): la tabella
             deve stare incollata alla barra blu del titolo, come ogni altro
             box del documento (.info-box, .intervento-box), non con uno
             spazio in piu' solo qui. --}}
        table.items { margin-top: 0; }

        .signature-box { margin-top: 0; }
        .signature-box .frame { border: 1px solid #e5e7eb; background: #f9fafb; padding: 8px 10px; min-height: 90px; }
        .signature-box img { max-width: 260px; max-height: 90px; }
        .signature-box .placeholder { color: #9ca3af; font-style: italic; }
        .signature-box .caption { margin-top: 4px; font-size: 8.5px; color: #6b7280; }
        .signature-box .signer-name { margin-top: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; }

        .section-gap { margin-top: 14px; }

        .footer-note { margin-top: 24px; }
