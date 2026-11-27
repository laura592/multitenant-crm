<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Support\Scaduto\FatturaDaAllegare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Il promemoria delle fatture scadute, con le fatture in allegato.
 *
 * ALLEGA LA COPIA DI CORTESIA, quella con stampato sopra "Copia non valida
 * ai fini fiscali": e' l'unica che l'API di Eureka espone e va benissimo,
 * perche' questa mail non riemette niente — l'originale elettronico il
 * cliente ce l'ha gia' nel suo cassetto fiscale. Scelta consapevole, non
 * un ripiego: vedi FatturaDaAllegare.
 *
 * LA TABELLA PORTA IL RESIDUO quando e' diverso dal totale. Il PDF stampa
 * sempre l'importo pieno: su Ristoalma la fattura 199 dice 10.323,68 ma ne
 * restano da pagare 3.447,50. Mandare l'allegato senza quel numero accanto
 * vuol dire farsi rispondere "l'ho gia' pagata", e avrebbe ragione lui.
 *
 * NESSUNA FRETTA NEL TESTO. Niente messa in mora, niente interessi, niente
 * minacce di sospendere le forniture: quelle sono decisioni che prende chi
 * firma, non una mail automatica. Il corpo predefinito e' sobrio e si
 * modifica nel modulo prima di mandare — chi manda ci mette la faccia, e il
 * testo deve poter essere il suo.
 */
class SollecitoPagamentoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, FatturaDaAllegare>  $fatture
     * @param  array<string, string>  $pdf  nome del file => contenuto
     */
    public function __construct(
        public array $fatture,
        public array $pdf,
        public ?Tenant $tenant = null,
        public ?string $customMessage = null,
        public ?string $subjectText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText ?: self::oggettoPredefinito($this->fatture));
    }

    /**
     * "Promemoria", come sul sollecito dei preventivi, e non "Sollecito" o
     * "Fatture insolute": l'oggetto e' l'unica riga che si legge sempre, e
     * su una mail con nove allegati una parola dura nell'oggetto cambia il
     * tono di tutta la conversazione prima ancora che venga aperta.
     *
     * @param  array<int|string, FatturaDaAllegare>  $fatture
     */
    public static function oggettoPredefinito(array $fatture): string
    {
        $fatture = array_values($fatture);

        if (count($fatture) === 1) {
            $sola = $fatture[0];

            return 'Promemoria pagamento fattura n. '.$sola->numero
                .($sola->data ? ' del '.$sola->data->format('d/m/Y') : '');
        }

        return 'Promemoria pagamento — '.count($fatture).' fatture';
    }

    /**
     * Il testo gia' pronto nella finestra d'invio, da rileggere e
     * modificare prima di mandare.
     *
     * Due cose che ci sono di proposito:
     *
     *  - la via d'uscita ("se ha gia' pagato"): i nostri dati sono vecchi
     *    di mezza giornata, un bonifico di ieri non si vede, e chiedere
     *    soldi gia' arrivati senza nemmeno ammettere che e' possibile fa
     *    arrabbiare — giustamente;
     *  - nessuna data entro cui pagare. Non e' una dimenticanza: un termine
     *    scritto in automatico e' una condizione contrattuale decisa da un
     *    programma.
     *
     * @param  array<int|string, FatturaDaAllegare>  $fatture
     */
    public static function corpoPredefinito(array $fatture): string
    {
        $quante = count($fatture);

        $cosa = $quante === 1
            ? 'la fattura che le alleghiamo risulta ancora da saldare'
            : 'le '.$quante.' fatture che le alleghiamo risultano ancora da saldare';

        return '<p>Buongiorno,</p>'
            .'<p>dai nostri registri '.$cosa.'. Qui sotto trova il riepilogo, '
            .'e in allegato la copia di ciascun documento.</p>'
            .'<p>Se il pagamento è già stato effettuato la preghiamo di segnalarcelo: '
            .'può darsi che non sia ancora stato registrato da noi, e in quel caso '
            .'consideri pure questo messaggio come non inviato.</p>'
            .'<p>Per qualsiasi chiarimento siamo a disposizione.</p>';
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sollecito-pagamento',
            with: [
                'fatture' => array_values($this->fatture),
                'allegati' => array_keys($this->pdf),
                'customMessage' => $this->customMessage,
                'tenant' => $this->tenant,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $allegati = [];

        foreach ($this->pdf as $nome => $contenuto) {
            $allegati[] = Attachment::fromData(fn (): string => $contenuto, $nome)
                ->withMime('application/pdf');
        }

        return $allegati;
    }
}
