<?php

namespace App\Support\Preventivi;

use App\Mail\SollecitoPreventivoMail;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteContatto;
use App\Models\QuoteEmail;
use App\Models\QuoteGroup;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Chiedere un si' o un no su un preventivo gia' mandato.
 *
 * La manda una persona premendo un pulsante: non c'e' e non ci sara' nessun
 * automatismo che solleciti da solo. In questa azienda le mail ai clienti le
 * manda qualcuno che ci mette la faccia.
 *
 * Vive qui e non dentro la pagina perche' serve da due posti -- la pagina
 * "Da sollecitare" e, un giorno, la scheda del preventivo -- e perche' la
 * pagina e' una tabella AGGREGATA: vedi documento().
 */
class Solleciti
{
    /**
     * La trattativa vera dietro la riga della tabella.
     *
     * La query della pagina raggruppa per COALESCE(quote_group_id, id), e
     * quando Filament apre un'azione con un form NON riusa la riga che si
     * vede: rifa' la query raggruppata filtrando su una chiave sola, e gli
     * aggregati tornano calcolati su quell'unica riga -- "alternative"
     * diventa 1 e l'importo quello di un preventivo solo.
     *
     * Percio' dal record si prendono SOLO quote_group_id e id, che sono
     * MAX e MIN di una chiave e sopravvivono al ricalcolo. Tutto il resto
     * si ricarica da qui.
     */
    public static function documento(mixed $record): Quote|QuoteGroup
    {
        // Si toglie SOLO lo scope del tenant, non tutti.
        // withoutGlobalScopes() porterebbe via anche il soft delete, e un
        // documento cancellato si lascerebbe sollecitare: il link che il
        // cliente riceve e' una pagina 404, perche' QuoteClientController
        // il cancellato non lo serve. Cosi' invece findOrFail fallisce,
        // che e' il verso giusto in cui sbagliare.
        return $record->quote_group_id
            ? QuoteGroup::withoutGlobalScope('tenant')->findOrFail($record->quote_group_id)
            : Quote::withoutGlobalScope('tenant')->findOrFail($record->id);
    }

    /**
     * Le alternative della trattativa, in ordine di numero.
     *
     * @return Collection<int, Quote>
     */
    public static function preventivi(Quote|QuoteGroup $documento): Collection
    {
        if ($documento instanceof Quote) {
            return collect([$documento]);
        }

        return Quote::withoutGlobalScope('tenant')
            ->where('quote_group_id', $documento->id)
            ->with(['customer', 'tenant'])
            ->orderBy('number')
            ->get();
    }

    /**
     * Il modulo d'invio: destinatario, copia, oggetto e testo.
     *
     * Niente allegati e niente spunte: un sollecito non rimanda l'offerta,
     * chiede una risposta. Il documento sta gia' nella pagina del cliente.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()
                ->required()
                // Si precompila solo se e' davvero UN indirizzo. In
                // anagrafica sei clienti hanno nel primo campo due mail
                // separate da ";" o del testo libero
                // ("info@lta.ii p", "Laura.DAddabbo@it.mcd.com x ft"), e
                // uno di loro e' fra le offerte da sollecitare: il modulo
                // si apriva con un valore che la sua stessa validazione
                // rifiuta, e l'errore arrivava dopo aver scritto la mail.
                // Meglio il campo vuoto e il valore sporco scritto sotto,
                // da cui copiare.
                ->default(fn (mixed $record) => self::unIndirizzo(static::destinatario($record)))
                ->helperText(function (mixed $record): ?string {
                    $trovato = static::destinatario($record);

                    return $trovato && ! self::unIndirizzo($trovato)
                        ? 'In anagrafica c\'è scritto: '.$trovato
                        : null;
                }),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (facoltativo)')
                ->email()
                ->helperText(fn (mixed $record): ?string => ($fissi = static::inCopiaSempre(static::documento($record))) !== []
                    ? 'In copia anche: '.implode(', ', $fissi)
                    : null),
            Forms\Components\TextInput::make('subject')
                ->label('Oggetto')
                ->required()
                ->maxLength(255)
                ->default(fn (mixed $record) => SollecitoPreventivoMail::oggettoPredefinito(static::documento($record))),
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->default(function (mixed $record) {
                    $documento = static::documento($record);

                    return SollecitoPreventivoMail::corpoPredefinito($documento, static::preventivi($documento));
                }),
        ];
    }

    /**
     * A chi scrivere: l'ultimo indirizzo che ha davvero ricevuto qualcosa,
     * e in mancanza quello del cliente. Mai il pagante: il preventivo e' di
     * chi l'ha chiesto (stessa regola di QuoteResource::emailDestinatario).
     *
     * Di un'offerta si guardano ENTRAMBE le tabelle, non solo
     * quote_group_emails. Qui le offerte con alternative si mandano quasi
     * sempre come singoli preventivi: 8 gruppi su 12 non hanno nessuna
     * mail di gruppo e ne hanno da una a tre sui preventivi. Guardando
     * solo le mail di gruppo, il modulo si apriva con l'indirizzo
     * dell'anagrafica — su OFF-2026-0011 info@hotelmariver.it invece di
     * renzobressaglia@gmail.com, cioe' un indirizzo che quell'offerta non
     * l'ha mai vista — mentre la colonna qui accanto mostrava quello
     * giusto. Due numeri diversi sulla stessa riga.
     */
    public static function destinatario(mixed $record): ?string
    {
        $documento = static::documento($record);

        $invii = $documento->emails()
            ->where('status', 'sent')
            ->pluck('created_at', 'recipient_email');

        if ($documento instanceof QuoteGroup) {
            $invii = $invii->merge(
                QuoteEmail::query()
                    ->whereIn('quote_id', static::preventivi($documento)->pluck('id')->all())
                    ->where('status', 'sent')
                    ->pluck('created_at', 'recipient_email')
            );
        }

        // Chiave = indirizzo, valore = quando: cosi' lo stesso indirizzo
        // usato piu' volte non pesa di piu', conta solo l'ultima volta.
        $ultimo = $invii->sortDesc()->keys()->first();

        if (is_string($ultimo) && $ultimo !== '') {
            return $ultimo;
        }

        // instanceof e non un ?->: la relazione customer() del gruppo non
        // porta il tipo, e senza il controllo l'analisi statica non sa che
        // quel Model ha un indirizzo (stessa ragione di
        // PreventiviDaSollecitare::tenantId()).
        $cliente = $documento->customer;

        return $cliente instanceof Customer ? $cliente->primaryEmail() : null;
    }

    /** L'indirizzo, se quello che c'e' scritto e' un indirizzo solo. */
    private static function unIndirizzo(?string $valore): ?string
    {
        $valore = trim((string) $valore);

        return $valore !== '' && filter_var($valore, FILTER_VALIDATE_EMAIL) ? $valore : null;
    }

    /**
     * Chi riceve copia di ogni sollecito, oltre al CC scelto sul momento.
     *
     * Sono gli stessi destinatari fissi dell'invio del preventivo
     * (QuoteResource::ccRecipients). Il sollecito e' l'unica mail che
     * usciva verso un cliente senza lasciare copia in ufficio: chi risponde
     * al telefono il giorno dopo non sapeva che gli avevamo scritto.
     *
     * @return array<int, string>
     */
    public static function inCopiaSempre(Quote|QuoteGroup $documento): array
    {
        $evento = $documento instanceof QuoteGroup ? 'quote_group' : 'quote';

        // instanceof e non un ?->: la relazione tenant() arriva dal trait e
        // non porta il tipo (stessa ragione del cliente, qui sopra).
        $tenant = $documento->tenant;

        return $tenant instanceof Tenant ? $tenant->notificationRecipients($evento) : [];
    }

    /**
     * Manda il sollecito e lo registra.
     *
     * DUE COSE CHE NON FA, al contrario dell'invio del preventivo:
     *
     *  - non tocca lo stato;
     *  - non riscrive valid_until.
     *
     * Un sollecito che allunga da se' la validita' rimetterebbe in piedi un
     * prezzo che il testo della mail, poche righe sopra, ha appena
     * dichiarato scaduto: la mail contraddirebbe il CRM, e si regalerebbe
     * un listino vecchio.
     *
     * @param  array<string, mixed>  $dati
     */
    public static function invia(mixed $record, array $dati): void
    {
        $documento = static::documento($record);
        $preventivi = static::preventivi($documento);

        $email = $documento->emails()->create([
            'user_id' => Auth::id(),
            'recipient_email' => $dati['recipient_email'],
            'cc_email' => $dati['cc_email'] ?? null,
            'subject' => $dati['subject'],
            'message' => $dati['custom_message'] ?? null,
            'tipo' => QuoteEmail::TIPO_SOLLECITO,
            'status' => 'sent',
        ]);

        try {
            Mail::to($dati['recipient_email'])
                ->cc(array_values(array_unique(array_filter([
                    $dati['cc_email'] ?? null,
                    ...static::inCopiaSempre($documento),
                ]))))
                ->send(new SollecitoPreventivoMail(
                    $documento,
                    $preventivi,
                    $documento->clientUrl(),
                    $dati['custom_message'] ?? null,
                    $dati['subject'],
                ));

            // Il contatto si scrive SOLO a invio riuscito: contarlo su una
            // mail mai partita vorrebbe dire bruciare uno dei due solleciti
            // senza che il cliente abbia ricevuto niente.
            static::registra($documento, [
                'tipo' => QuoteContatto::TIPO_SOLLECITO,
                'data' => now(),
                'nota' => 'Mail a '.$dati['recipient_email'],
            ]);

            Notification::make()
                ->title('Sollecito inviato')
                ->body('A '.$dati['recipient_email'].'.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $email->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            Notification::make()
                ->title('Invio fallito')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Scrive un contatto sulla trattativa: una telefonata, un incontro, o il
     * sollecito appena partito.
     *
     * Una colonna sola delle due e' piena, mai tutte e due: il contatto sta
     * sulla TRATTATIVA, e su un'offerta con sei alternative si telefona una
     * volta sola.
     *
     * @param  array<string, mixed>  $dati
     */
    public static function registra(Quote|QuoteGroup $documento, array $dati): QuoteContatto
    {
        return QuoteContatto::create([
            'tenant_id' => $documento->tenant_id,
            'quote_id' => $documento instanceof Quote ? $documento->id : null,
            'quote_group_id' => $documento instanceof QuoteGroup ? $documento->id : null,
            'user_id' => Auth::id(),
            'tipo' => $dati['tipo'],
            'data' => $dati['data'] ?? now(),
            'nota' => $dati['nota'] ?? null,
        ]);
    }
}
