<?php

namespace App\Filament\Resources\LetturaCasettaResource\Pages;

use App\Filament\Resources\LetturaCasettaResource;
use App\Mail\LetturaCasettaMail;
use App\Models\LetturaCasetta;
use App\Models\LetturaCasettaEmail;
use App\Support\Casette\AvvisoFiltro;
use App\Support\Casette\RendicontoLetturaPdf;
use App\Support\OutsideLivewireRender;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class EditLetturaCasetta extends EditRecord
{
    protected static string $resource = LetturaCasettaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->azioneRendiconto(),
            $this->azioneInvio(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * La lettura di questa pagina. getRecord() e' tipizzato Model e
     * l'analisi statica non sa che qui e' sempre una LetturaCasetta.
     */
    protected function lettura(): LetturaCasetta
    {
        /** @var LetturaCasetta $record */
        $record = $this->getRecord();

        return $record;
    }

    protected function afterSave(): void
    {
        $lettura = $this->getRecord();

        if ($lettura instanceof LetturaCasetta) {
            AvvisoFiltro::perLettura($lettura);
        }
    }

    /**
     * Lo stesso PDF che parte in allegato, per guardarlo prima di spedirlo.
     * Si apre, non si scarica: controllarlo tre volte non deve lasciare tre
     * copie nei Download (vedi RendicontoCasettaController).
     */
    protected function azioneRendiconto(): Actions\Action
    {
        return Actions\Action::make('rendiconto')
            ->label('PDF della lettura')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->url(fn () => route('letture-casette.rendiconto', $this->lettura()))
            ->openUrlInNewTab();
    }

    /**
     * Il rendiconto all'amministrazione.
     *
     * Le foto arrivavano su WhatsApp, slegate dalla data e dalla casetta;
     * qui partono in un PDF solo, con la data della lettura in testa. Il
     * destinatario e' precompilato ma resta modificabile: le casette sono
     * due oggi, ma la lista di chi le fattura non e' scolpita.
     */
    protected function azioneInvio(): Actions\Action
    {
        return Actions\Action::make('invia')
            ->label('Invia il rendiconto')
            ->icon('heroicon-o-paper-airplane')
            ->modalWidth('4xl')
            ->modalHeading('Invia il rendiconto della lettura')
            ->modalSubmitActionLabel('Invia')
            ->form([
                Forms\Components\Placeholder::make('riepilogo')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->riepilogoInvio()),
                Forms\Components\TagsInput::make('destinatari')
                    ->label('A')
                    ->default([LetturaCasettaResource::DESTINATARIO_RENDICONTO])
                    ->suggestions([LetturaCasettaResource::DESTINATARIO_RENDICONTO])
                    ->splitKeys([',', ' ', 'Tab', 'Enter'])
                    ->required()
                    ->rules([fn () => $this->regolaEmail()]),
                Forms\Components\TagsInput::make('cc')
                    ->label('CC (opzionale)')
                    ->splitKeys([',', ' ', 'Tab', 'Enter'])
                    ->rules([fn () => $this->regolaEmail()]),
                // Gia' scritto, non vuoto: il testo che parte si legge e si
                // corregge qui, invece di doverlo immaginare.
                Forms\Components\RichEditor::make('messaggio')
                    ->label('Testo della mail (modificabile)')
                    ->helperText('Questo testo parte davvero. Le differenze di periodo e il PDF si aggiungono da soli.')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'link', 'undo', 'redo'])
                    ->live(debounce: 500)
                    ->default(fn () => LetturaCasettaMail::testoStandard($this->lettura())),
                Forms\Components\Tabs::make('anteprime')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Come arriva la mail')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                Forms\Components\Placeholder::make('anteprima_email')
                                    ->hiddenLabel()
                                    ->content(fn (Forms\Get $get): HtmlString => $this->anteprimaEmail($get('messaggio'))),
                            ]),
                        Forms\Components\Tabs\Tab::make('Il PDF allegato')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\Placeholder::make('anteprima_pdf')
                                    ->hiddenLabel()
                                    ->content(fn (): HtmlString => new HtmlString(
                                        '<iframe src="'.e(route('letture-casette.rendiconto', $this->lettura()))
                                        .'" style="width:100%;height:60vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                                    )),
                            ]),
                    ]),
            ])
            ->action(fn (array $data) => $this->invia($data));
    }

    /**
     * La mail come la vedra' chi la riceve, testo compreso.
     *
     * Vedi App\Support\OutsideLivewireRender: il Placeholder e' reattivo
     * (->live() sull'editor qui sopra), quindi questo render parte sempre
     * mentre Livewire sta ridisegnando il pannello -- senza il fix nel corpo
     * comparirebbero i commenti <!--[if BLOCK]><![endif]-->.
     */
    protected function anteprimaEmail(mixed $messaggio): HtmlString
    {
        $html = OutsideLivewireRender::run(fn () => (new LetturaCasettaMail(
            $this->lettura(),
            '',
            is_string($messaggio) ? $messaggio : null,
        ))->render());

        return new HtmlString(
            '<iframe srcdoc="'.e($html).'" style="width:100%;height:60vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
        );
    }

    /** @param  array<string, mixed>  $data */
    protected function invia(array $data): void
    {
        $lettura = $this->lettura();

        $destinatari = $this->indirizzi($data['destinatari'] ?? []);
        $cc = $this->indirizzi($data['cc'] ?? []);

        if ($destinatari === []) {
            Notification::make()
                ->title('Nessun destinatario valido')
                ->danger()
                ->send();

            return;
        }

        // Vedi App\Support\OutsideLivewireRender: senza, il PDF e la parte
        // testo dell'email mostrano i commenti <!--[if BLOCK]><![endif]-->
        // che Livewire inietta attorno a ogni @if.
        $pdf = OutsideLivewireRender::run(fn () => RendicontoLetturaPdf::per($lettura));

        $email = $lettura->emails()->create([
            'user_id' => auth()->id(),
            'recipient_email' => implode(', ', $destinatari),
            'cc_email' => $cc ? implode(', ', $cc) : null,
            'subject' => RendicontoLetturaPdf::oggetto($lettura),
            'message' => $data['messaggio'] ?? null,
            'status' => 'sent',
        ]);

        try {
            OutsideLivewireRender::run(fn () => Mail::to($destinatari)
                ->cc($cc)
                ->send(new LetturaCasettaMail($lettura, $pdf->output(), $data['messaggio'] ?? null)));

            Notification::make()
                ->title('Rendiconto inviato')
                ->body(implode(', ', $destinatari))
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
     * Che cosa sta per partire, detto prima di premere Invia: quante foto
     * ci sono davvero nel PDF e se e' gia' stato mandato una volta. Una
     * lettura senza foto si spedisce lo stesso, ma non di nascosto.
     */
    protected function riepilogoInvio(): HtmlString
    {
        $lettura = $this->lettura();
        $quante = $lettura->quanteFoto();

        $righe = [
            'Lettura del <strong>'.e($lettura->data->format('d/m/Y')).'</strong>'
                .($lettura->machineUnit?->serial_number ? ' — casetta '.e($lettura->machineUnit->serial_number) : ''),
            $quante === 0
                ? '<span style="color:#b45309;">Nessuna foto: il PDF parte senza immagini.</span>'
                : $quante.' '.($quante === 1 ? 'foto allegata' : 'foto allegate').' nel PDF',
        ];

        /** @var LetturaCasettaEmail|null $ultimo */
        $ultimo = $lettura->emails()->first();

        if ($ultimo) {
            $righe[] = 'Già inviato il <strong>'.e($ultimo->created_at?->format('d/m/Y \a\l\l\e H:i') ?? '').'</strong> a '.e($ultimo->recipient_email);
        }

        return new HtmlString(implode('<br>', $righe));
    }

    /**
     * Gli indirizzi scritti nel modale, ripuliti. TagsInput non valida nulla
     * da solo: la regola sotto avvisa, questa impedisce che una riga vuota o
     * un refuso finisca a Mail::to().
     *
     * @param  array<int, mixed>|string  $valori
     * @return array<int, string>
     */
    protected function indirizzi(array|string $valori): array
    {
        return collect((array) $valori)
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    protected function regolaEmail(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            foreach ((array) $value as $item) {
                if (! filter_var($item, FILTER_VALIDATE_EMAIL)) {
                    $fail("\"{$item}\" non e' un indirizzo email valido.");
                }
            }
        };
    }
}
