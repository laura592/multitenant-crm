{{-- Linguaggio visivo comune a tutti i PDF del sistema (preventivo,
rapportino, ordine materiali, ...): stessa palette (navy #020F30), stessa
tabella righe con header pieno e righe alternate, stesso riquadro
dati/sezione con barra blu + corpo grigio chiaro, nessun angolo arrotondato.
Va inclusa dentro il tag <style> del template chiamante (@include, non un
tag <style> proprio: eviterebbe <style> annidati), DOPO letterhead-styles.
Ogni documento aggiunge solo le classi che gli servono in piu' (larghezze di
colonna, riquadri specifici) - non duplicare qui sotto queste regole di base. --}}

.row-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.row-table td { border: none; padding: 0; vertical-align: top; }

.doc-meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; background: #f0f4fa; }
.doc-meta td { border: none; padding: 5px 14px; font-size: 10px; color: #374151; }
.doc-meta .label { text-transform: uppercase; letter-spacing: .04em; font-size: 8px; color: #6b7280; margin-right: 5px; }
.doc-meta .value { font-size: 12px; font-weight: bold; color: #020F30; }
.doc-meta .to-right { text-align: right; }

.section-title { background: #020F30; color: #fff; padding: 5px 10px; font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
.info-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; }
.info-box .customer-name { font-size: 12px; font-weight: bold; color: #020F30; margin-bottom: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
.info-box table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
.info-box td { padding: 2px 0; }
{{-- font-weight: bold (non 600): con font-weight:600 dompdf non risolve il
     grassetto DejaVu Sans e ripiega sul default_font di config/dompdf.php
     (serif) — vale per ogni testo in grassetto di tutti i PDF. --}}
.info-box td.label { font-weight: bold; color: #4b5563; padding-right: 6px; white-space: nowrap; }

table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
table.items th { text-align: left; background: #020F30; border: 1px solid #020F30; padding: 6px 5px; font-size: 8px; font-weight: bold; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
table.items td { border: none; border-bottom: 1px solid #e5e7eb; padding: 6px 5px; vertical-align: top; }
table.items tbody tr:nth-child(even) { background: #f9fafb; }
table.items td.numeric, table.items th.numeric { text-align: right; }
table.items td.center, table.items th.center { text-align: center; }
table.items .sku-text { color: #6b7280; font-size: 8.5px; }

.notes-box { margin-top: 18px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; }
.notes-box h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #92400e; margin: 0 0 4px; }
.notes-box p { margin: 0; font-size: 11px; }

.footer-note { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: center; }

.clearfix::after { content: ""; display: table; clear: both; }
