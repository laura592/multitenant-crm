<?php

namespace App\Support\Assistenza;

use App\Models\PriceList;
use App\Models\QuoteProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Il contratto di assistenza di una macchina del preventivo, pronto da far
 * firmare.
 *
 * Il testo legale NON e' ribattuto qui: e' il PDF dell'ufficio, importato
 * pagina per pagina cosi' com'e'. Ribatterlo avrebbe voluto dire rischiare
 * una parola diversa in un contratto, e doverlo riallineare a ogni
 * revisione.
 *
 * Il PDF sta in Documenti e l'ufficio lo aggiorna da li'
 * (PriceList::contrattoInVigore()). Nel codice non ce n'e' una copia di
 * riserva (tolta il 21/09/2026): due modelli, uno dei quali invisibile
 * dal pannello, prima o poi avrebbero detto due cose diverse. Senza un
 * modello caricato il contratto non si genera, e il pulsante lo dice.
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
        $modello = self::modello($contratto['tipo'], $rigaMacchina->quote?->tenant_id)
            ?? throw new ModelloContrattoMancante($contratto['nome']);

        self::accoda($pdf, $modello);

        return $pdf->Output('', 'S');
    }

    /** Il percorso del PDF in vigore in Documenti, o null se non ce n'e' uno. */
    public static function modello(string $tipo, ?string $tenantId = null): ?string
    {
        $caricato = PriceList::contrattoInVigore($tipo, $tenantId);

        if ($caricato === null) {
            return null;
        }

        if (! Storage::disk('public')->exists($caricato->file_path)) {
            Log::warning('Contratto di assistenza: il PDF in Documenti non si trova sul disco', [
                'documento' => $caricato->id,
                'file' => $caricato->file_path,
            ]);

            return null;
        }

        return Storage::disk('public')->path($caricato->file_path);
    }

    /**
     * Se un PDF si puo' mettere in coda alla pagina dei dati. Il parser di
     * FPDI non legge tutti i PDF (per esempio certi salvataggi con la
     * tabella dei riferimenti compressa): meglio scoprirlo al caricamento
     * che quando un commerciale scarica il contratto.
     */
    public static function modelloLeggibile(string $percorso): bool
    {
        try {
            return (new Fpdi)->setSourceFile($percorso) > 0;
        } catch (\Throwable) {
            return false;
        }
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
