<?php

namespace App\Support\Assistenza;

use App\Models\QuoteProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Il contratto di assistenza di una macchina del preventivo, pronto da far
 * firmare.
 *
 * Il testo legale NON e' ribattuto qui: e' il PDF dell'ufficio, in
 * resources/contratti, importato pagina per pagina cosi' com'e'. Ribatterlo
 * avrebbe voluto dire rischiare una parola diversa in un contratto, e
 * doverlo riallineare a ogni revisione. Per aggiornare un contratto basta
 * sostituire il file.
 *
 * Davanti al contratto va una pagina generata, "Dati del contratto": chi e'
 * il cliente, quale macchina, com'e' composto il valore di listino e quanto
 * fa il canone. Sono i dati che il testo dell'ufficio lascia fuori — gli
 * articoli 8 e 9 dicono la percentuale, non la cifra.
 */
final class ContrattoAssistenzaPdf
{
    public static function crea(QuoteProduct $rigaMacchina): string
    {
        $contratto = ContrattoAssistenza::dellaRiga($rigaMacchina);

        if ($contratto === null) {
            throw new \InvalidArgumentException('Su questa riga non c\'è un contratto di assistenza.');
        }

        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Contratto '.$contratto['nome']);
        $pdf->SetCreator('Alex CRM');

        self::accoda($pdf, StreamReader::createByString(self::paginaDati($rigaMacchina, $contratto)));
        self::accoda($pdf, self::modello($contratto['tipo']));

        return $pdf->Output('', 'S');
    }

    public static function modello(string $tipo): string
    {
        return resource_path('contratti/'.ContrattoAssistenza::TIPI[$tipo]['file']);
    }

    /** @param  array<string, mixed>  $contratto */
    public static function paginaDati(QuoteProduct $rigaMacchina, array $contratto): string
    {
        return Pdf::loadView('pdf.contratto-assistenza-dati', self::datiVista($rigaMacchina, $contratto))
            ->setPaper('a4')
            ->output();
    }

    /**
     * @param  array<string, mixed>  $contratto
     * @return array<string, mixed>
     */
    public static function datiVista(QuoteProduct $rigaMacchina, array $contratto): array
    {
        $preventivo = $rigaMacchina->quote()->with(['customer', 'tenant'])->first();

        return [
            'contratto' => $contratto,
            'preventivo' => $preventivo,
            'cliente' => $preventivo?->customer,
            'tenant' => $preventivo?->tenant,
            'macchina' => $rigaMacchina->product,
            'dalSecondoAnno' => ContrattoAssistenza::attivabileDalSecondoAnno($contratto['tipo']),
            'serveAcqua' => ContrattoAssistenza::serveTrattamentoAcqua($contratto['tipo'], $rigaMacchina->product),
            'data' => now(),
        ];
    }

    /** Tutte le pagine di un PDF, in coda a quello che si sta componendo. */
    private static function accoda(Fpdi $pdf, string|StreamReader $sorgente): void
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
