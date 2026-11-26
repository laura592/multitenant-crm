<?php

namespace App\Support\Pdf;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Illuminate\Support\Collection;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Tutte le proposte di un'offerta in un PDF solo.
 *
 * Dalla pagina del cliente ogni proposta si scarica per conto suo, ma chi
 * deve girarle al socio o al commercialista si ritrovava a scaricare
 * quattro file e ad allegarli uno per uno (indicazione di Laura,
 * 26/11/2026). Qui escono nell'ordine in cui le vede a schermo, una dopo
 * l'altra.
 *
 * Il contenuto NON e' ribattuto: si prendono i PDF dei singoli preventivi,
 * quelli veri (QuoteResource::buildPdf), e si accodano pagina per pagina.
 * Rigenerarli con un'altra vista avrebbe voluto dire due documenti che
 * dicono la stessa cosa in due modi, e prima o poi due cifre diverse.
 */
final class PreventiviUniti
{
    /**
     * @param  Collection<int, Quote>  $preventivi
     */
    public static function perOfferta(Collection $preventivi): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Alex CRM');

        foreach ($preventivi as $preventivo) {
            self::accoda($pdf, StreamReader::createByString(
                QuoteResource::buildPdf($preventivo)->output()
            ));
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Il nome del file: il numero dell'offerta, non quello del primo
     * preventivo. Chi se lo ritrova nella cartella dei download deve
     * capire a che cosa si riferisce.
     */
    public static function nomeFile(?string $numeroOfferta): string
    {
        return 'proposte-'.($numeroOfferta ?: 'offerta').'.pdf';
    }

    /**
     * Stessa logica di ContrattoAssistenzaPdf::accoda(): le pagine si
     * importano con la loro dimensione, che un A4 e un orizzontale nello
     * stesso fascicolo non si schiacciano a vicenda.
     */
    private static function accoda(Fpdi $pdf, StreamReader $sorgente): void
    {
        $pagine = $pdf->setSourceFile($sorgente);

        for ($n = 1; $n <= $pagine; $n++) {
            $modello = $pdf->importPage($n);
            $dimensioni = $pdf->getTemplateSize($modello);

            $pdf->AddPage($dimensioni['orientation'], [$dimensioni['width'], $dimensioni['height']]);
            $pdf->useTemplate($modello);
        }
    }
}
