<?php

namespace App\Mail;

use App\Models\Quote;
use App\Models\QuoteGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * La mail che chiede un si' o un no su un preventivo gia' mandato.
 *
 * NON riporta l'offerta da capo: il cliente ce l'ha gia'. Porta una
 * domanda e la pagina dove rispondere -- accetto, non mi interessa, ho una
 * domanda, richiamatemi -- che esiste gia' e conta anche le aperture.
 *
 * Nessun allegato, mai: un PDF ri-allegato dice "guarda di nuovo", e qui
 * invece si chiede di decidere. Chi lo vuole lo scarica dalla pagina.
 *
 * La manda una persona premendo un pulsante. Non c'e' nessun automatismo
 * che solleciti da solo: in questa azienda le mail ai clienti le manda
 * qualcuno che ci mette la faccia.
 */
class SollecitoPreventivoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Quote>  $preventivi  le alternative della trattativa
     */
    public function __construct(
        public Quote|QuoteGroup $documento,
        public Collection $preventivi,
        public string $clientUrl,
        public ?string $customMessage = null,
        public ?string $subjectText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText ?: self::oggettoPredefinito($this->documento));
    }

    public static function oggettoPredefinito(Quote|QuoteGroup $documento): string
    {
        // "Promemoria", non "Ci aggiorna?" (Laura, 27/11/2026: "orribile
        // questa imposizione"). Un promemoria ricorda una cosa nostra; la
        // domanda chiedeva al cliente di giustificarsi, e in un oggetto —
        // l'unica riga che si legge sempre — suonava come una sollecitazione
        // a sbrigarsi.
        return $documento instanceof QuoteGroup
            ? "Promemoria offerta {$documento->number}"
            : "Promemoria preventivo {$documento->number}";
    }

    /**
     * Il testo gia' pronto nella finestra d'invio, da modificare prima di
     * mandare.
     *
     * Scritto da Laura (27/11/2026). La mia prima versione era piu' secca
     * ("un no ci e' utile quanto un si'"): questa dice la stessa cosa
     * nella lingua con cui l'azienda parla ai suoi clienti, e non e' un
     * dettaglio -- la mail la firma lei.
     *
     * La chiusura ("La ringraziamo... Cordiali saluti") NON sta qui: e'
     * prestampata nel template, dopo i pulsanti. Dentro il testo
     * modificabile verrebbe prima della domanda, e si saluterebbe prima di
     * aver chiesto.
     *
     * @param  Collection<int, Quote>  $preventivi
     */
    public static function corpoPredefinito(Quote|QuoteGroup $documento, Collection $preventivi): string
    {
        $primo = $preventivi->first();
        $quando = $primo?->date?->locale('it')->isoFormat('D MMMM');
        $piuDiUno = $preventivi->count() > 1;

        // Come si chiama il documento lo decide il DOCUMENTO, non quante
        // alternative gli sono rimaste vive: un'offerta con una sola
        // soluzione porta sempre un numero OFF-, e l'oggetto della mail la
        // chiama "Offerta". Contando le alternative, su OFF-2026-0015 la
        // stessa mail diceva "Offerta" nell'oggetto e "il preventivo
        // OFF-2026-0015" nel testo.
        $comeSiChiama = $documento instanceof QuoteGroup ? 'offerta' : 'preventivo';

        $cosa = $piuDiUno
            ? "l'{$comeSiChiama} {$documento->number}, con ".$preventivi->count().' soluzioni a confronto'
            : ($comeSiChiama === 'offerta' ? "l'offerta {$documento->number}" : "il preventivo {$documento->number}");

        $importo = $primo && ! $piuDiUno
            ? ', per un importo di € '.number_format((float) $primo->subtotal, 2, ',', '.').' + IVA'
            : '';

        $testo = '<p>Buongiorno,</p>'
            // "Le" maiuscolo: e' il pronome di cortesia, come sulla mail
            // d'invio ("Se nel frattempo Le servisse piu' tempo"). I
            // possessivi -- la sua decisione, un suo riscontro -- restano
            // minuscoli, sempre come li'.
            .'<p>'.($quando ? "lo scorso {$quando} Le abbiamo inviato " : 'Le abbiamo inviato ')
            .$cosa.$importo.'.</p>'
            .'<p>Volevamo sapere se ha avuto modo di valutare la nostra proposta e se '
            .($piuDiUno ? 'una delle soluzioni può essere ancora' : 'può essere ancora')
            .' di suo interesse.</p>'
            .'<p>Qualunque sia la sua decisione, ci farebbe piacere ricevere un suo riscontro, '
            .'così da sapere se proseguire o se, per il momento, possiamo archiviare la richiesta.</p>';

        // La riga sulla scadenza entra nel testo modificabile e non nel
        // template: cosi' si puo' togliere o riscrivere caso per caso.
        if ($primo && $primo->oltreLaValidita()) {
            $scaduto = match (true) {
                $piuDiUno => 'Le proposte sono scadute',
                $comeSiChiama === 'offerta' => 'L\'offerta è scaduta',
                default => 'Il preventivo è scaduto',
            };

            $testo .= '<p>'.$scaduto
                .' il '.$primo->scadeIl()->format('d/m/Y')
                // Un pronome solo per tutti e due i verbi: prima
                // "riprenderla ... aggiornarlo" mescolava i generi nella
                // stessa frase.
                .'; qualora desiderasse riprender'.($pronome = match (true) {
                    $piuDiUno => 'le',
                    $comeSiChiama === 'offerta' => 'la',
                    default => 'lo',
                })
                .', saremo naturalmente disponibili ad aggiornar'.$pronome
                .' alle condizioni attuali.</p>';
        }

        return $testo;
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sollecito-preventivo',
            with: [
                'documento' => $this->documento,
                'preventivi' => $this->preventivi,
                'clientUrl' => $this->clientUrl,
                'customMessage' => $this->customMessage,
                'tenant' => $this->documento->tenant,
            ],
        );
    }
}
