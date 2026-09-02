<?php

namespace App\Support\Pdf;

use App\Models\Tenant;
use TCPDF;

/**
 * La scheda anagrafica cliente come PDF COMPILABILE (modulo AcroForm), con i
 * campi gia' valorizzati coi dati del CRM: il cliente la apre, controlla,
 * corregge quello che non torna, completa il resto e la rimanda firmata.
 *
 * Il modulo e' modellato su quello che i fornitori mandano a noi, adattato al
 * caso di Alex: un intestatario fattura, un eventuale soggetto pagante
 * diverso (il gestore che si accolla i costi) e N sedi operative, che nel CRM
 * sono anagrafiche distinte collegate al pagante — vedi
 * Customer::billingCustomer() e Customer::paidCustomers().
 *
 * Il layout e' scritto a coordinate assolute, in punti, con l'origine in
 * BASSO a sinistra come nella tipografia classica; TCPDF misura invece dal
 * bordo superiore, e la conversione sta tutta in top(). Tenere l'origine in
 * basso rende leggibile il flusso della pagina: si parte da $y alto e si
 * scende sottraendo.
 */
class SchedaAnagraficaPdf
{
    private const W = 595.276;

    private const H = 841.89;

    private const ML = 34.0;

    private const MR = 34.0;

    private const CW = self::W - self::ML - self::MR;

    /**
     * TCPDF::Text() posiziona il TOP della cella di testo, non la linea di
     * base. Questo fattore (frazione del corpo del carattere) riporta una
     * coordinata di base — quella con cui e' comodo ragionare allineando
     * testo e riquadri — al top che TCPDF si aspetta. Il valore e' calibrato
     * sul rendering reale, non stimato a tavolino.
     */
    private const BASELINE = 1.06;

    /** @var array<int, int> */
    private const PRIMARY = [49, 110, 180];

    /** @var array<int, int> */
    private const DARK = [45, 50, 75];

    /** @var array<int, int> */
    private const LABEL = [74, 80, 98];

    /** @var array<int, int> */
    private const LINE = [138, 148, 170];

    /** @var array<int, int> */
    private const FILL = [237, 242, 249];

    /** @var array<int, int> */
    private const SOFT = [232, 237, 246];

    /** @var array<int, int> */
    private const MUTED = [108, 116, 136];

    /** @var array<int, int> */
    private const BOXFILL = [250, 251, 254];

    private TCPDF $pdf;

    private int $pagina = 0;

    /**
     * @param  array<string, string>  $valori  precompilazione, da SchedaAnagraficaData
     * @param  array{sedi?: int, macchine?: int}  $conteggi  quante sedi e macchine
     *                                                       risultano davvero collegate nel CRM: servono a dichiarare sul
     *                                                       modulo quando non ci stanno tutte, invece di stamparne cinque e
     *                                                       lasciar credere che siano tutte li'.
     */
    public function __construct(
        private array $valori = [],
        private ?Tenant $tenant = null,
        private array $conteggi = [],
    ) {}

    /** Byte del PDF. */
    public function render(): string
    {
        $this->pdf = new TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('CRM Alex');
        $this->pdf->SetAuthor($this->ragioneSociale());
        $this->pdf->SetTitle('Scheda anagrafica cliente');
        $this->pdf->SetSubject('Modulo di apertura anagrafica cliente');
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetCellPadding(0);

        // Nessun bordo ne' sfondo sul widget: TCPDF li scrive in /MK, che i
        // viewer disegnano in modo incoerente (Anteprima e Chrome ignorano del
        // tutto la cornice dei campi di testo, e il modulo sembra una pagina
        // vuota). La cornice la disegniamo nel contenuto della pagina — vedi
        // campo(), spunta() e scelta() — cosi' e' identica ovunque e resta
        // visibile anche stampata; al widget resta solo il valore.
        $this->pdf->setFormDefaultProp([
            'lineWidth' => 0,
            'textColor' => [0, 0, 0],
        ]);

        $this->pagina1();
        $this->pagina2();
        $this->pagina3();
        $this->pagina4();

        return $this->conNeedAppearances($this->pdf->Output('', 'S'));
    }

    /**
     * TCPDF scrive sempre "/NeedAppearances false" nel catalogo del PDF
     * (tcpdf.php, _putcatalog(): e' una costante, non c'e' un setter).
     *
     * Con true il viewer ricostruisce l'aspetto dei campi dal loro valore
     * (/V), che e' il dato autorevole, invece di fidarsi dell'aspetto
     * pre-disegnato (/AP). E' la scelta giusta per un modulo riempito da
     * programma, e in piu' fa disegnare al viewer i segni di spunta e i
     * pallini dei radio, che altrimenti dipendevano da come ciascun
     * programma interpreta /MK.
     *
     * NOTA per chi legge dopo: questo flag NON e' il rimedio al problema
     * "si vede pieno e poi si svuota" segnalato in fase di collaudo. Quello
     * riguardava solo l'estensione Acrobat dentro Chrome, che e' un
     * visualizzatore web a se' e svuota i moduli precompilati comunque siano
     * generati (verificato anche con PDF prodotti da un motore diverso).
     * Anteprima, l'applicazione Acrobat, poppler e PDFKit mostrano tutti i
     * valori correttamente; la soluzione e' stata far scaricare il file
     * invece di aprirlo nel browser — vedi
     * CustomerSchedaAnagraficaController.
     *
     * La sostituzione conserva la lunghezza in byte ("true " con lo spazio in
     * coda, come "false"): cambiarla sposterebbe tutti gli offset della
     * tabella xref e il PDF non si aprirebbe piu'.
     */
    private function conNeedAppearances(string $pdf): string
    {
        return str_replace('/NeedAppearances false', '/NeedAppearances true ', $pdf);
    }

    // ------------------------------------------------------------ primitive

    /** Converte una coordinata dal basso (origine tipografica) al top TCPDF. */
    private function top(float $dalBasso, float $altezza = 0): float
    {
        return self::H - $dalBasso - $altezza;
    }

    /**
     * @param  array<int, int>  $colore
     */
    private function testo(string $s, float $x, float $base, float $corpo = 7.0, string $stile = '', array $colore = self::DARK, ?float $destraA = null): void
    {
        $this->pdf->SetFont('helvetica', $stile, $corpo);
        $this->pdf->SetTextColor(...$colore);

        $y = $this->top($base) - $corpo * self::BASELINE;

        if ($destraA !== null) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($destraA - $x, $corpo * self::BASELINE, $s, 0, 0, 'R');

            return;
        }

        $this->pdf->Text($x, $y, $s);
    }

    /**
     * Scrive un testo lungo mandandolo a capo da solo dentro $larghezza, e
     * restituisce la $base dell'ultima riga scritta.
     *
     * L'informativa privacy era spezzata a mano riga per riga: bastava
     * cambiare una parola — o un tenant con la ragione sociale piu' lunga —
     * per ritrovarsi righe cortissime accanto a righe che sbordavano.
     *
     * @param  array<int, int>  $colore
     */
    private function paragrafo(string $testo, float $x, float $base, float $larghezza, float $corpo = 6.9, float $interlinea = 9.3, array $colore = self::DARK, float $rientro = 0): float
    {
        $this->pdf->SetFont('helvetica', '', $corpo);

        $riga = '';
        $primaRiga = true;

        foreach (explode(' ', $testo) as $parola) {
            $prova = $riga === '' ? $parola : $riga.' '.$parola;
            $disponibile = $larghezza - ($primaRiga ? 0 : $rientro);

            if ($riga !== '' && $this->pdf->GetStringWidth($prova) > $disponibile) {
                $this->testo($riga, $x + ($primaRiga ? 0 : $rientro), $base, $corpo, '', $colore);
                $base -= $interlinea;
                $riga = $parola;
                $primaRiga = false;

                continue;
            }

            $riga = $prova;
        }

        if ($riga !== '') {
            $this->testo($riga, $x + ($primaRiga ? 0 : $rientro), $base, $corpo, '', $colore);
        }

        return $base;
    }

    private function etichetta(string $s, float $x, float $base, float $corpo = 6.5): void
    {
        $this->testo($s, $x, $base, $corpo, 'B', self::LABEL);
    }

    private function nota(string $s, float $x, float $base, float $corpo = 6.8): void
    {
        $this->testo($s, $x, $base, $corpo, 'I', self::MUTED);
    }

    /**
     * @param  array<int, int>|null  $riempimento
     * @param  array<int, int>  $bordo
     */
    private function riquadro(float $x, float $base, float $w, float $h, ?array $riempimento = null, array $bordo = self::LINE, float $spessore = 0.6): void
    {
        $stile = ['width' => $spessore, 'color' => $bordo];

        $this->pdf->Rect(
            $x, $this->top($base, $h), $w, $h,
            $riempimento ? 'DF' : 'D',
            ['all' => $stile],
            $riempimento ?? [],
        );
    }

    /**
     * @param  array<int, int>  $colore
     */
    private function barra(float $x, float $base, float $w, float $h, array $colore): void
    {
        $this->pdf->Rect($x, $this->top($base, $h), $w, $h, 'F', [], $colore);
    }

    private function campo(string $nome, float $x, float $base, float $w, ?string $etichetta = null, float $h = 15, bool $multiriga = false): void
    {
        if ($etichetta !== null) {
            $this->etichetta($etichetta, $x, $base + $h + 3);
        }

        $this->pdf->Rect($x, $this->top($base, $h), $w, $h, 'F', [], self::FILL);
        $this->pdf->Line($x, $this->top($base), $x + $w, $this->top($base), ['width' => 0.7, 'color' => self::LINE]);

        // TCPDF eredita il colore di testo corrente nel /DA del campo: senza
        // questo, i valori della tabella macchine uscivano bianchi (l'ultimo
        // testo disegnato e' l'intestazione bianca su fondo scuro).
        $this->pdf->SetTextColor(0, 0, 0);

        $prop = $multiriga ? ['multiline' => 'true'] : [];
        $opt = ['f' => 4];

        if (($valore = $this->valori[$nome] ?? '') !== '') {
            $opt['v'] = $valore;
            $opt['dv'] = $valore;
        }

        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->TextField($nome, $w, $h, $prop, $opt, $x, $this->top($base, $h));
    }

    private function spunta(string $nome, float $x, float $base, ?string $etichetta = null, float $lato = 10, float $corpoEtichetta = 7.4): void
    {
        $this->riquadro($x, $base, $lato, $lato, [255, 255, 255], self::LINE, 0.8);

        // Il widget copre TUTTO il quadretto disegnato: rimpicciolirlo per
        // ragioni estetiche lasciava un bersaglio da 6pt, che col trackpad si
        // manca. Il segno di spunta lo ridisegna il viewer
        // (/NeedAppearances, vedi conNeedAppearances()), quindi non serve piu'
        // tenerlo rientrato per non coprire il bordo.
        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->CheckBox(
            $nome, $lato, ($this->valori[$nome] ?? '') !== '',
            [], ['f' => 4],
            'Yes', $x, $this->top($base, $lato),
        );

        if ($etichetta !== null) {
            $this->testo($etichetta, $x + $lato + 3.5, $base + 2.4, $corpoEtichetta);
        }
    }

    private function scelta(string $gruppo, string $valore, float $x, float $base, ?string $etichetta = null, float $lato = 10, float $corpoEtichetta = 7.4): void
    {
        $this->pdf->Circle(
            $x + $lato / 2, $this->top($base + $lato / 2), $lato / 2,
            0, 360, 'DF', ['width' => 0.8, 'color' => self::LINE], [255, 255, 255],
        );

        // Come in spunta(): il bersaglio cliccabile e' l'intero pallino.
        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->RadioButton(
            $gruppo, $lato,
            [], ['f' => 4],
            $valore, ($this->valori[$gruppo] ?? null) === $valore,
            $x, $this->top($base, $lato),
        );

        if ($etichetta !== null) {
            $this->testo($etichetta, $x + $lato + 3.5, $base + 2.4, $corpoEtichetta);
        }
    }

    /**
     * @param  array<int, int>  $colore
     */
    private function sezione(string $titolo, float $base, ?string $suggerimento = null, array $colore = self::PRIMARY): float
    {
        $this->barra(self::ML, $base, self::CW, 15, $colore);
        $this->testo(mb_strtoupper($titolo), self::ML + 6, $base + 4.4, 8.4, 'B', [255, 255, 255]);

        if ($suggerimento !== null) {
            $this->testo($suggerimento, self::ML, $base + 4.8, 6.8, 'I', [255, 255, 255], self::ML + self::CW - 6);
        }

        return $base - 8;
    }

    /**
     * Larghezze e ascisse di una riga di campi, da una lista di frazioni.
     *
     * @param  array<int, float>  $frazioni
     * @return array<int, array{0: float, 1: float}>
     */
    private function colonne(array $frazioni, float $gap = 8, ?float $x0 = null, ?float $larghezza = null): array
    {
        $x = $x0 ?? self::ML;
        $totale = ($larghezza ?? self::CW) - $gap * (count($frazioni) - 1);

        $out = [];
        foreach ($frazioni as $f) {
            $w = $totale * $f;
            $out[] = [$x, $w];
            $x += $w + $gap;
        }

        return $out;
    }

    private function intestazione(string $titolo, ?string $sottotitolo = null): float
    {
        $this->pagina++;
        $this->pdf->AddPage();

        // 46 e non 32: il logo partiva incollato al bordo superiore del
        // foglio. Spostando $base scende tutto il testatino — marchi, titolo,
        // riga di chiusura e inizio del contenuto — di un blocco solo.
        $base = self::H - 46;
        $logo = public_path('img/logo.png');

        if (is_file($logo)) {
            $this->pdf->Image($logo, self::ML, $this->top($base + 6, 26), 0, 26, '', '', '', false, 300, '', false, false, 0);
        }

        // Il marchio "Franke Approved Partner" sotto il logo Alex e allineato
        // a sinistra con lui, come nella carta intestata di preventivi e
        // rapportini (components/pdf-letterhead.blade.php): su un modulo che
        // va al cliente e' una credenziale, non un ornamento. Affiancarlo
        // sembrava fuori asse, perche' i due marchi hanno proporzioni molto
        // diverse e nessuno dei due faceva da centro.
        $franke = public_path('img/franke_partner_logo.png');

        if (is_file($franke)) {
            $w = 84.0;
            $h = $w * 58 / 480;
            $this->pdf->Image($franke, self::ML, $this->top($base + 2 - $h, $h), $w, $h, '', '', '', false, 300, '', false, false, 0);
        }

        $this->testo($titolo, self::ML, $base, 13, 'B', self::DARK, self::ML + self::CW);

        if ($sottotitolo !== null) {
            $this->testo($sottotitolo, self::ML, $base - 11, 7.6, '', self::MUTED, self::ML + self::CW);
        }

        $this->barra(self::ML, $base - 22, self::CW, 1.2, self::PRIMARY);

        return $base - 41;
    }

    private function piede(): void
    {
        $base = 30;
        $this->barra(self::ML, $base + 14, self::CW, 0.5, self::SOFT);

        $this->testo($this->ragioneSociale().' - '.$this->indirizzo(), self::ML, $base + 6, 6, '', self::MUTED);
        $this->testo($this->contatti(), self::ML, $base - 1, 6, '', self::MUTED);
        $this->testo(
            sprintf('Mod. ANAG-CLI rev. 1 - pag. %d di 4', $this->pagina),
            self::ML, $base + 6, 6.4, 'B', self::MUTED, self::ML + self::CW,
        );
    }

    // ---------------------------------------------------------- le 4 pagine

    private function pagina1(): void
    {
        $y = $this->intestazione('SCHEDA ANAGRAFICA CLIENTE', 'Modulo di apertura / aggiornamento anagrafica - da restituire firmato');

        $this->testo('I campi contrassegnati da * sono obbligatori', self::ML, $y + 12, 6.6, 'I', self::MUTED, self::ML + self::CW);

        $this->testo('Tipo di richiesta:', self::ML, $y, 7.4);
        $this->scelta('tipo_richiesta', 'nuovo', self::ML + 74, $y - 2.4, 'Nuovo cliente');
        $this->scelta('tipo_richiesta', 'aggiornamento', self::ML + 168, $y - 2.4, 'Aggiornamento dati');
        $this->scelta('tipo_richiesta', 'nuova_sede', self::ML + 288, $y - 2.4, 'Apertura nuova sede operativa');
        $y -= 26;

        $y = $this->sezione("A - Dati per l'intestazione della fattura", $y, 'chi riceve e intesta il documento fiscale');

        $y -= 22;
        $this->campo('fatt_ragione_sociale', self::ML, $y, self::CW, 'RAGIONE SOCIALE * (o nome e cognome se ditta individuale / privato)');

        $y -= 32;
        $r = $this->colonne([0.44, 0.09, 0.11, 0.26, 0.10]);
        $this->campo('fatt_via', $r[0][0], $y, $r[0][1], 'INDIRIZZO SEDE LEGALE *');
        $this->campo('fatt_civico', $r[1][0], $y, $r[1][1], 'N. *');
        $this->campo('fatt_cap', $r[2][0], $y, $r[2][1], 'CAP *');
        $this->campo('fatt_citta', $r[3][0], $y, $r[3][1], 'CITTÀ *');
        $this->campo('fatt_prov', $r[4][0], $y, $r[4][1], 'PROV. *');

        $y -= 32;
        $r = $this->colonne([0.24, 0.24, 0.16, 0.36]);
        $this->campo('fatt_piva', $r[0][0], $y, $r[0][1], 'PARTITA IVA *');
        $this->campo('fatt_cf', $r[1][0], $y, $r[1][1], 'CODICE FISCALE *');
        $this->campo('fatt_sdi', $r[2][0], $y, $r[2][1], 'CODICE SDI *');
        $this->campo('fatt_pec', $r[3][0], $y, $r[3][1], 'PEC *');

        $y -= 32;
        $r = $this->colonne([0.22, 0.22, 0.56]);
        $this->campo('fatt_tel', $r[0][0], $y, $r[0][1], 'TELEFONO *');
        $this->campo('fatt_cell', $r[1][0], $y, $r[1][1], 'CELLULARE');
        $this->campo('fatt_email', $r[2][0], $y, $r[2][1], 'E-MAIL AMMINISTRAZIONE * (invio fatture e solleciti)');

        $y -= 32;
        $r = $this->colonne([0.34, 0.22, 0.44]);
        $this->campo('fatt_referente', $r[0][0], $y, $r[0][1], 'REFERENTE AMMINISTRATIVO');
        $this->campo('fatt_referente_tel', $r[1][0], $y, $r[1][1], 'TEL. REFERENTE');
        $this->campo('fatt_referente_email', $r[2][0], $y, $r[2][1], 'E-MAIL REFERENTE');

        $y -= 26;
        $this->etichetta('CONDIZIONE IVA *', self::ML, $y, 7);
        $this->scelta('iva_condizione', 'soggetto', self::ML + 82, $y - 2.6, 'Soggetto IVA');
        $this->scelta('iva_condizione', 'esente', self::ML + 175, $y - 2.6, 'Esente / non imponibile art.');
        $this->campo('iva_esente_art', self::ML + 330, $y - 4, 120, null, 13);
        $this->scelta('iva_condizione', 'privato', self::ML + 462, $y - 2.6, 'Privato');

        $y -= 24;
        $this->etichetta('CONDIZIONI DI PAGAMENTO CONCORDATE *', self::ML, $y, 7);
        $y -= 15;

        $pagamenti = [
            ['pag_bonifico', 'Bonifico bancario'],
            ['pag_bonifico_30', 'Bonifico 30 gg f.m.'],
            ['pag_riba_30', 'Ri.Ba. 30 gg f.m.'],
            ['pag_riba_60', 'Ri.Ba. 60 gg f.m.'],
            ['pag_riba_60_90', 'Ri.Ba. 60/90 gg f.m.'],
            ['pag_consegna', 'Pagamento alla consegna'],
            ['pag_contanti', 'Contanti'],
            ['pag_assegno', 'Assegno'],
            ['pag_carta', 'Carta di credito'],
            ['pag_leasing', 'Leasing'],
            ['pag_noleggio', 'Noleggio operativo (canone mensile)'],
            ['pag_scaglionato', 'Bonifico 50% ordine / 30% consegna / 20% 30 gg'],
        ];

        $colonna = self::CW / 3;
        foreach ($pagamenti as $i => [$nome, $etichetta]) {
            $this->spunta($nome, self::ML + ($i % 3) * $colonna, $y - intdiv($i, 3) * 15, $etichetta, 9);
        }

        $y -= 15 * 4 + 2;
        $this->spunta('pag_altro_flag', self::ML, $y, 'Altro:', 9);
        $this->campo('pag_altro', self::ML + 52, $y - 2, self::CW - 52, null, 13);

        $y -= 30;
        $r = $this->colonne([0.58, 0.42]);
        $this->campo('banca_iban', $r[0][0], $y, $r[0][1], 'IBAN (obbligatorio per Ri.Ba. / SDD / bonifico)');
        $this->campo('banca_nome', $r[1][0], $y, $r[1][1], 'ISTITUTO BANCARIO E AGENZIA');

        $y -= 20;
        $this->nota('Per Ri.Ba. e addebito diretto SDD è necessario allegare il mandato firmato e copia del documento del legale rappresentante.', self::ML, $y);

        $y -= 26;
        $y = $this->sezione('B - Chi paga', $y, 'gestore, capogruppo, società di servizi: solo se diverso dalla sezione A');

        $y -= 8;
        $this->scelta('pagante_tipo', 'stesso', self::ML, $y - 2.6, 'Paga il soggetto indicato nella sezione A');
        $y -= 15;
        $this->scelta('pagante_tipo', 'terzo', self::ML, $y - 2.6, 'Paga un altro soggetto: compilare il riquadro qui sotto');
        $y -= 22;

        $top = $y;
        $this->riquadro(self::ML, $top - 140, self::CW, 140, self::BOXFILL);
        $ix = self::ML + 8;
        $iw = self::CW - 16;

        $by = $top - 26;
        $r = $this->colonne([0.52, 0.24, 0.24], 8, $ix, $iw);
        $this->campo('pag_ragione_sociale', $r[0][0], $by, $r[0][1], 'RAGIONE SOCIALE DEL SOGGETTO PAGANTE');
        $this->campo('pag_piva', $r[1][0], $by, $r[1][1], 'PARTITA IVA');
        $this->campo('pag_cf', $r[2][0], $by, $r[2][1], 'CODICE FISCALE');

        $by -= 30;
        $r = $this->colonne([0.40, 0.08, 0.10, 0.24, 0.18], 8, $ix, $iw);
        $this->campo('pag_via', $r[0][0], $by, $r[0][1], 'INDIRIZZO');
        $this->campo('pag_civico', $r[1][0], $by, $r[1][1], 'N.');
        $this->campo('pag_cap', $r[2][0], $by, $r[2][1], 'CAP');
        $this->campo('pag_citta', $r[3][0], $by, $r[3][1], 'CITTÀ');
        $this->campo('pag_prov', $r[4][0], $by, $r[4][1], 'PROV.');

        $by -= 30;
        $r = $this->colonne([0.18, 0.32, 0.28, 0.22], 8, $ix, $iw);
        $this->campo('pag_sdi', $r[0][0], $by, $r[0][1], 'CODICE SDI');
        $this->campo('pag_pec', $r[1][0], $by, $r[1][1], 'PEC');
        $this->campo('pag_email', $r[2][0], $by, $r[2][1], 'E-MAIL AMMINISTRAZIONE');
        $this->campo('pag_tel', $r[3][0], $by, $r[3][1], 'TELEFONO');

        $this->etichetta('COSA PAGA QUESTO SOGGETTO', $ix, $top - 106);
        $bx = $ix;
        foreach ([
            ['pag_cosa_tutto', 'Tutto', 70],
            ['pag_cosa_noleggio', 'Solo noleggio / comodato macchine', 160],
            ['pag_cosa_prodotto', 'Solo forniture prodotto', 125],
            ['pag_cosa_interventi', 'Solo interventi tecnici', 0],
        ] as [$nome, $etichetta, $passo]) {
            $this->spunta($nome, $bx, $top - 128, $etichetta, 9, 7);
            $bx += $passo;
        }

        $y = $top - 140 - 14;
        $this->nota('Preventivi, rapportini e interventi restano registrati sulla sede dove si lavora; la fattura viene intestata al soggetto pagante qui indicato.', self::ML, $y);

        $this->piede();
    }

    private function pagina2(): void
    {
        $y = $this->intestazione('SCHEDA ANAGRAFICA CLIENTE', 'C - Referenti operativi  |  D - Sedi operative');

        $y = $this->sezione('C - Referenti e recapiti operativi', $y - 4, 'a chi mandiamo cosa');

        $y -= 22;
        $r = $this->colonne([0.33, 0.33, 0.34]);
        $this->campo('ref_ordini_nome', $r[0][0], $y, $r[0][1], 'REFERENTE ORDINI / ACQUISTI');
        $this->campo('ref_ordini_tel', $r[1][0], $y, $r[1][1], 'TELEFONO');
        $this->campo('ref_ordini_email', $r[2][0], $y, $r[2][1], 'E-MAIL');

        $y -= 32;
        $r = $this->colonne([0.33, 0.33, 0.34]);
        $this->campo('ref_tecnico_nome', $r[0][0], $y, $r[0][1], 'REFERENTE TECNICO / MANUTENZIONI');
        $this->campo('ref_tecnico_tel', $r[1][0], $y, $r[1][1], 'TELEFONO');
        $this->campo('ref_tecnico_email', $r[2][0], $y, $r[2][1], 'E-MAIL');

        $y -= 32;
        $r = $this->colonne([0.5, 0.5]);
        $this->campo('email_rapportini', $r[0][0], $y, $r[0][1], 'E-MAIL PER RAPPORTINI DI INTERVENTO *');
        $this->campo('email_ddt', $r[1][0], $y, $r[1][1], "E-MAIL PER DDT / CONFERME D'ORDINE");

        $y -= 13;
        $this->nota('I rapportini di intervento vengono inviati alla sede dove si è lavorato, non al soggetto pagante.', self::ML, $y);

        $y -= 34;
        $r = $this->colonne([0.5, 0.5]);
        $this->campo('consegne_orari', $r[0][0], $y, $r[0][1], 'GIORNI E ORARI DI CONSEGNA MERCE PREFERITI');
        $this->campo('consegne_vincoli', $r[1][0], $y, $r[1][1], 'VINCOLI DI ACCESSO (ZTL, varchi, scarico, piano)');

        $y -= 26;
        $y = $this->sezione('D - Sedi operative (chioschi, bar, punti vendita)', $y, $this->suggerimentoSedi());
        $y -= 11;
        $this->nota($this->notaSedi(), self::ML, $y);
        $y -= 12;

        foreach ([1, 2, 3] as $n) {
            $y = $this->bloccoSede($n, $y);
        }

        $this->nota('Ogni sede viene registrata come anagrafica collegata al soggetto pagante: così interventi, lavaggi e consumi restano attribuiti al punto giusto.', self::ML, $y);

        $this->piede();
    }

    private function pagina3(): void
    {
        $y = $this->intestazione('SCHEDA ANAGRAFICA CLIENTE', 'D - Sedi operative (segue)  |  E - Macchine e impianti');

        $y = $this->sezione('D - Sedi operative (segue)', $y - 4);
        $y -= 12;

        foreach ([4, 5] as $n) {
            $y = $this->bloccoSede($n, $y);
        }

        $y -= 18;
        $y = $this->sezione('E - Macchine e impianti presenti', $y, 'facoltativa: compilare se già noto');
        $y -= 11;
        $this->nota('Serve a collegare ogni matricola alla sede giusta e a distinguere le macchine del cliente da quelle in comodato o a noleggio da Alex.', self::ML, $y);
        $y -= 10;

        // "Proprieta'" e "chi paga" erano la stessa informazione detta due
        // volte: se la macchina e' in comodato o a noleggio il canone lo paga
        // il soggetto della sezione A, se e' del cliente non c'e' canone. Una
        // colonna sola, e lo spazio recuperato va al modello, che si troncava.
        $intestazioni = [
            ['SEDE N.', 0.07], ['TIPO / MODELLO', 0.30], ['MATRICOLA', 0.18],
            ['PROPRIETÀ', 0.21], ['NOTE', 0.24],
        ];
        $col = $this->colonne(array_column($intestazioni, 1), 4);

        $this->barra(self::ML, $y - 14, self::CW, 14, self::DARK);
        foreach ($intestazioni as $i => [$titolo, $_]) {
            $this->testo($titolo, $col[$i][0] + 3, $y - 9.6, 6.4, 'B', [255, 255, 255]);
        }
        $y -= 14;

        $chiavi = ['sede', 'modello', 'matricola', 'proprieta', 'note'];

        for ($i = 1; $i <= SchedaAnagraficaData::MAX_MACCHINE; $i++) {
            $ry = $y - 19 * $i;

            if ($i % 2 === 0) {
                $this->barra(self::ML, $ry, self::CW, 19, [247, 249, 253]);
            }

            foreach ($chiavi as $c => $chiave) {
                $this->campo("mac{$i}_{$chiave}", $col[$c][0] + 2, $ry + 3, $col[$c][1] - 4, null, 13);
            }
        }

        $y -= 19 * SchedaAnagraficaData::MAX_MACCHINE + 8;
        $this->nota($this->notaMacchine(), self::ML, $y);

        $this->piede();
    }

    private function pagina4(): void
    {
        $y = $this->intestazione('SCHEDA ANAGRAFICA CLIENTE', 'F - Impianti e assistenza  |  G - Privacy, consensi e firma');

        $y = $this->sezione('F - Impianti a spina, lavaggi e assistenza', $y - 4, 'solo per chi ha impianti alla spina');
        $y -= 22;
        $r = $this->colonne([0.28, 0.24, 0.22, 0.26]);
        $this->campo('imp_numero_vie', $r[0][0], $y, $r[0][1], 'N. VIE / COLONNE INSTALLATE');
        $this->campo('imp_freq_lavaggio', $r[1][0], $y, $r[1][1], 'FREQUENZA LAVAGGI CONCORDATA');
        $this->campo('imp_ultimo_lavaggio', $r[2][0], $y, $r[2][1], 'DATA ULTIMO LAVAGGIO');
        $this->campo('imp_giorno_pref', $r[3][0], $y, $r[3][1], 'GIORNO / FASCIA PREFERITA');

        $y -= 28;
        $this->etichetta('CONTRATTO DI ASSISTENZA', self::ML, $y + 2.6);
        $this->spunta('ass_ordinaria', self::ML + 118, $y, 'Manutenzione ordinaria programmata', 9, 7);
        $this->spunta('ass_chiamata', self::ML + 296, $y, 'Solo intervento a chiamata', 9, 7);
        $this->spunta('ass_lavaggi', self::ML + 436, $y, 'Lavaggi periodici', 9, 7);

        $y -= 34;
        $y = $this->sezione('G - Informativa privacy (artt. 13-14 Reg. UE 2016/679)', $y);
        $y -= 8;

        foreach ($this->informativa() as [$blocco, $rientro]) {
            $y -= 9.3;

            if ($blocco === '') {
                continue;
            }

            $y = $this->paragrafo($blocco, self::ML + 2, $y, self::CW - 8, 6.9, 9.3, [51, 58, 74], $rientro);
        }

        $y -= 16;
        $this->riquadro(self::ML, $y - 46, self::CW, 46, self::BOXFILL);
        $this->testo("Presa visione dell'informativa (finalità a, b, c - obbligatorio)", self::ML + 8, $y - 15, 7.2, 'B');
        $this->spunta('consenso_presa_visione', self::ML + 330, $y - 18, "Dichiaro di aver letto l'informativa", 10, 7.2);
        $this->testo('Consenso a comunicazioni commerciali e marketing (finalità d - facoltativo)', self::ML + 8, $y - 35, 7.2, 'B');
        $this->scelta('consenso_marketing', 'acconsento', self::ML + 330, $y - 38, 'ACCONSENTO', 10, 7.2);
        $this->scelta('consenso_marketing', 'non_acconsento', self::ML + 425, $y - 38, 'NON ACCONSENTO', 10, 7.2);
        $y -= 46;

        $y -= 36;
        $r = $this->colonne([0.22, 0.38, 0.40]);
        $this->campo('firma_data', $r[0][0], $y, $r[0][1], 'DATA *');
        $this->campo('firma_nome', $r[1][0], $y, $r[1][1], 'NOME E COGNOME DEL FIRMATARIO *');
        $this->campo('firma_ruolo', $r[2][0], $y, $r[2][1], 'IN QUALITÀ DI (legale rappresentante, titolare, procuratore) *');

        // Il riquadro del timbro va tenuto largo: un timbro tondo d'azienda
        // sta su 3-4 cm, e sotto ci deve stare anche la firma.
        $y -= 92;
        $this->riquadro(self::ML + self::CW * 0.52, $y, self::CW * 0.48, 82);
        $this->etichetta('TIMBRO E FIRMA DEL CLIENTE *', self::ML + self::CW * 0.52 + 6, $y + 72);
        $this->etichetta('ALLEGATI DA RESTITUIRE INSIEME AL MODULO', self::ML, $y + 54);

        foreach ([
            ['all_visura', 'Visura camerale o certificato di attribuzione P. IVA'],
            ['all_documento', "Documento d'identità del firmatario"],
            ['all_mandato', 'Mandato Ri.Ba. / SDD firmato (se previsto)'],
            ['all_esenzione', 'Dichiarazione di esenzione IVA (se applicabile)'],
        ] as $i => [$nome, $etichetta]) {
            $this->spunta($nome, self::ML, $y + 40 - $i * 12.5, $etichetta, 8, 6.6);
        }

        $y -= 20;
        $this->campo('note_cliente', self::ML, $y - 68, self::CW, 'NOTE E RICHIESTE DEL CLIENTE', 68, true);
        $y -= 68;

        $y -= 22;
        $y = $this->sezione('Riquadro riservato ad ALEX S.r.l.', $y, 'non compilare', self::DARK);
        $y -= 22;
        $r = $this->colonne([0.2, 0.2, 0.2, 0.4]);
        $this->campo('int_codice_cliente', $r[0][0], $y, $r[0][1], 'CODICE CLIENTE GESTIONALE');
        $this->campo('int_data_inserimento', $r[1][0], $y, $r[1][1], 'DATA INSERIMENTO');
        $this->campo('int_agente', $r[2][0], $y, $r[2][1], 'AGENTE / OPERATORE');
        $this->campo('int_note', $r[3][0], $y, $r[3][1], 'NOTE INTERNE');

        $this->piede();
    }

    private function suggerimentoSedi(): string
    {
        $totali = $this->conteggi['sedi'] ?? 0;

        return $totali > SchedaAnagraficaData::MAX_SEDI
            ? sprintf('%d sedi collegate nel CRM, qui ne stanno %d', $totali, SchedaAnagraficaData::MAX_SEDI)
            : 'una per ogni punto in cui interveniamo';
    }

    private function notaSedi(): string
    {
        $totali = $this->conteggi['sedi'] ?? 0;

        if ($totali > SchedaAnagraficaData::MAX_SEDI) {
            return sprintf(
                'Attenzione: nel CRM risultano %d sedi collegate a questo cliente e su questo modulo ne stanno %d. Per le altre allegare un elenco con gli stessi dati.',
                $totali,
                SchedaAnagraficaData::MAX_SEDI,
            );
        }

        return 'Un blocco per ogni sede. Se le sedi sono più di cinque, duplicare questa pagina o allegare un elenco con gli stessi dati.';
    }

    private function notaMacchine(): string
    {
        $totali = $this->conteggi['macchine'] ?? 0;

        if ($totali > SchedaAnagraficaData::MAX_MACCHINE) {
            return sprintf(
                'Attenzione: presso queste sedi risultano %d matricole e in tabella ne stanno %d; per le altre allegare un elenco. Proprietà: indicare CLIENTE, COMODATO oppure NOLEGGIO Alex.',
                $totali,
                SchedaAnagraficaData::MAX_MACCHINE,
            );
        }

        return 'Proprietà: indicare CLIENTE (macchina del cliente), COMODATO oppure NOLEGGIO Alex — chi paga il canone discende da qui.';
    }

    private function bloccoSede(int $n, float $y): float
    {
        // 138 e non 152: con l'altezza precedente fra la riga dei recapiti e
        // quella della fatturazione restava una banda vuota di 25pt che
        // spezzava il blocco in due.
        $h = 138;
        $this->riquadro(self::ML, $y - $h, self::CW, $h, self::BOXFILL);
        $this->barra(self::ML, $y - 16, self::CW, 16, self::PRIMARY);
        $this->testo("SEDE OPERATIVA N. {$n}", self::ML + 6, $y - 11.4, 7.6, 'B', [255, 255, 255]);

        $p = "sede{$n}_";
        $ix = self::ML + 8;
        $iw = self::CW - 16;

        $by = $y - 42;
        $r = $this->colonne([0.58, 0.42], 8, $ix, $iw);
        $this->campo($p.'insegna', $r[0][0], $by, $r[0][1], 'INSEGNA / NOME DEL PUNTO (es. Chiosco Piazza Duomo)');
        $this->campo($p.'tipologia', $r[1][0], $by, $r[1][1], 'TIPOLOGIA (chiosco, bar, ufficio, mensa, distributore...)');

        $by -= 30;
        $r = $this->colonne([0.40, 0.08, 0.10, 0.24, 0.18], 8, $ix, $iw);
        $this->campo($p.'via', $r[0][0], $by, $r[0][1], 'INDIRIZZO');
        $this->campo($p.'civico', $r[1][0], $by, $r[1][1], 'N.');
        $this->campo($p.'cap', $r[2][0], $by, $r[2][1], 'CAP');
        $this->campo($p.'citta', $r[3][0], $by, $r[3][1], 'CITTÀ');
        $this->campo($p.'prov', $r[4][0], $by, $r[4][1], 'PROV.');

        $by -= 30;
        $r = $this->colonne([0.28, 0.20, 0.30, 0.22], 8, $ix, $iw);
        $this->campo($p.'referente', $r[0][0], $by, $r[0][1], 'REFERENTE IN LOCO');
        $this->campo($p.'tel', $r[1][0], $by, $r[1][1], 'TELEFONO');
        $this->campo($p.'email', $r[2][0], $by, $r[2][1], 'E-MAIL DELLA SEDE');
        $this->campo($p.'orari', $r[3][0], $by, $r[3][1], 'GIORNI/ORARI DI APERTURA');

        $cy = $y - $h + 12;
        $this->etichetta('FATTURAZIONE DI QUESTA SEDE', $ix, $cy + 2.6, 6.4);
        $this->spunta($p.'fatt_come_a', $ix + 118, $cy, 'Come sezione A', 9, 7);
        $this->spunta($p.'fatt_pagante_b', $ix + 216, $cy, 'Soggetto pagante sez. B', 9, 7);
        $this->spunta($p.'fatt_altro_flag', $ix + 352, $cy, 'Altro:', 9, 7);
        $this->campo($p.'fatt_altro', $ix + 394, $cy - 2, $iw - 394, null, 13);

        return $y - $h - 12;
    }

    /**
     * L'informativa, come blocchi di testo: [paragrafo, rientro]. Le lettere
     * a)-d) elencano PRIMA tutte le finalita' e solo dopo si dice quali sono
     * obbligatorie — prima la d) capitava dopo quella precisazione, che cosi'
     * sembrava riferirsi anche a lei.
     *
     * @return array<int, array{0: string, 1: float}>
     */
    private function informativa(): array
    {
        $email = $this->tenant?->email ?: 'alexcaffe@pec.it';

        return [
            ['Titolare del trattamento: '.$this->ragioneSociale().', '.$this->indirizzo().'. '.$this->contatti().'.', 0],
            ['', 0],
            ['I dati anagrafici, fiscali e di contatto raccolti con il presente modulo sono trattati per le seguenti finalità:', 0],
            ['a) esecuzione del contratto e gestione del rapporto commerciale (preventivi, ordini, consegne, interventi tecnici, lavaggi e manutenzioni programmate, invio dei rapportini di intervento);', 12],
            ['b) adempimenti amministrativi, contabili e fiscali previsti dalla legge (fatturazione elettronica, incassi, scadenzario);', 12],
            ["c) gestione del parco macchine installato e della relativa assistenza, anche presso sedi operative diverse dall'intestatario della fattura;", 12],
            ['d) invio di comunicazioni commerciali, listini, promozioni e novità di prodotto.', 12],
            ['', 0],
            ['Per le finalità a), b) e c) il conferimento dei dati è necessario: il rifiuto rende impossibile instaurare o proseguire il rapporto. La finalità d) è facoltativa ed è soggetta a consenso, che può essere revocato in qualsiasi momento.', 0],
            ['', 0],
            ["Base giuridica: esecuzione del contratto e obblighi di legge per a), b), c); consenso dell'interessato per d). I dati sono conservati per la durata del rapporto e poi per i termini di legge (10 anni per la documentazione contabile e fiscale). Possono essere comunicati a consulenti fiscali, istituti di credito, corrieri, tecnici incaricati e fornitori di servizi informatici che operano come responsabili del trattamento; non sono diffusi né trasferiti fuori dall'Unione Europea. Il trattamento avviene con strumenti cartacei ed elettronici, con misure di sicurezza adeguate. L'interessato può in ogni momento esercitare i diritti di cui agli artt. 15-22 del Regolamento (accesso, rettifica, cancellazione, limitazione, portabilità, opposizione) e revocare il consenso alla finalità d) scrivendo a ".$email.'.', 0],
        ];
    }

    // ------------------------------------------------------ dati intestazione

    private function ragioneSociale(): string
    {
        return $this->tenant?->legal_name ?: 'ALEX S.r.l.';
    }

    private function indirizzo(): string
    {
        return collect([
            $this->tenant?->street,
            trim(($this->tenant?->postal_code ?? '').' '.($this->tenant?->city ?? '')),
            $this->tenant?->province ? "({$this->tenant->province})" : null,
        ])->filter()->implode(' - ');
    }

    private function contatti(): string
    {
        return collect([
            $this->tenant?->vat_number ? "P. IVA {$this->tenant->vat_number}" : null,
            $this->tenant?->phone ? "Tel. {$this->tenant->phone}" : null,
            $this->tenant?->email,
            $this->tenant?->sdi ? "SDI {$this->tenant->sdi}" : null,
        ])->filter()->implode(' - ');
    }
}
