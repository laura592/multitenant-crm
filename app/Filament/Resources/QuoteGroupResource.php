<?php

namespace App\Filament\Resources;

use App\Filament\Forms\OffertaCaffeFields;
use App\Filament\Resources\QuoteGroupResource\Pages;
use App\Filament\Resources\QuoteGroupResource\RelationManagers\InviiRelationManager;
use App\Filament\Resources\QuoteGroupResource\RelationManagers\QuotesRelationManager;
use App\Mail\QuoteGroupMail;
use App\Mail\SollecitoPreventivoMail;
use App\Models\Noleggio;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteContatto;
use App\Models\QuoteGroup;
use App\Models\QuoteGroupEmail;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use App\Support\Pdf\OffertaCaffePdf;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * "Offerte": raggruppa piu' preventivi alternativi per lo stesso cliente
 * (es. 3 configurazioni macchina diverse) cosi' da poterli inviare in
 * un'unica email invece che uno alla volta (docs/architecture.md §14).
 * Qui il gruppo rappresenta l'offerta globale; i singoli Quote sono le
 * soluzioni alternative tra cui il cliente puo' scegliere.
 * Schema DB e modelli (QuoteGroup, QuoteGroupEmail) esistevano gia' dalla
 * migrazione iniziale, mancava solo il livello applicativo.
 */
class QuoteGroupResource extends Resource
{
    protected static ?string $model = QuoteGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Offerte';

    protected static ?string $modelLabel = 'Offerta globale';

    protected static ?string $pluralModelLabel = 'Offerte globali';

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * La scheda dell'offerta in sola lettura (27/11/2026).
     *
     * Serviva perche' dalla pagina "Da sollecitare" si finiva nel form di
     * modifica, che nasconde proprio le due cose per cui si apre l'offerta
     * prima di telefonare: che cosa gli abbiamo mandato e quando lo abbiamo
     * sentito. Il preventivo singolo ce l'aveva gia' (QuoteResource), il
     * gruppo no.
     *
     * Ricalca l'infolist del preventivo invece di reinventarla: chi passa
     * dall'offerta a una delle sue alternative deve ritrovare le stesse
     * sezioni nello stesso ordine.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('number')->label('Offerta')->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(['default' => 1, 'lg' => 5])
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    // L'offerta non ha una data propria come il preventivo:
                    // quella che conta e' il giorno in cui e' nata.
                    TextEntry::make('created_at')->label('Data')->date('d/m/Y')->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray')
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    // Il valore dell'offerta e' l'alternativa piu' cara, non
                    // la somma: il cliente ne comprera' una, e sommarne sei
                    // gonfierebbe il valore di sei volte (stessa regola della
                    // colonna "Totale" qui sotto e della pagina "Da
                    // sollecitare").
                    //
                    // E' l'IMPONIBILE: i clienti sono aziende, e il numero di
                    // cui si parla al telefono e' sempre senza IVA.
                    TextEntry::make('valore')
                        ->label('Valore dell\'offerta')
                        ->state(fn (QuoteGroup $record) => $record->quotes()->max('subtotal'))
                        ->money('EUR')
                        ->weight('bold')
                        ->helperText('imponibile della soluzione più cara: il cliente ne scegliera\' una')
                        ->placeholder('—')
                        ->columnSpan(['default' => 1, 'lg' => 4]),
                    TextEntry::make('sent_at')
                        ->label('Inviata il')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('non ancora inviata')
                        ->columnSpan(['default' => 1, 'lg' => 4]),
                    TextEntry::make('scelta')
                        ->label('Soluzione scelta')
                        ->state(fn (QuoteGroup $record) => $record->chosenQuote()?->number)
                        ->placeholder('nessuna, ancora')
                        ->columnSpan(['default' => 1, 'lg' => 4]),
                ]),
            // Le alternative sono il motivo per cui l'offerta esiste: una
            // riga a testa, con il link alla scheda del singolo preventivo
            // per chi vuole vedere le righe e il PDF.
            InfolistSection::make('Soluzioni alternative')
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->schema([
                    // Intestazione una volta sola: le TextEntry della
                    // RepeatableEntry non hanno label, altrimenti
                    // "Preventivo"/"Imponibile"/... si ripeterebbero su ogni
                    // riga (stessa scelta delle righe preventivo in
                    // QuoteResource).
                    Grid::make(12)
                        ->schema([
                            TextEntry::make('header_numero')->hiddenLabel()->state('Preventivo')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->columnSpan(['default' => 1, 'lg' => 3]),
                            TextEntry::make('header_cosa')->hiddenLabel()->state('Che cosa propone')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->columnSpan(['default' => 1, 'lg' => 5]),
                            TextEntry::make('header_imponibile')->hiddenLabel()->state('Imponibile')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight()->columnSpan(['default' => 1, 'lg' => 2]),
                            TextEntry::make('header_stato')->hiddenLabel()->state('Stato')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->columnSpan(['default' => 1, 'lg' => 2]),
                        ]),
                    // Niente ->state() su questa RepeatableEntry: con i
                    // modelli le righe stampate si leggono sempre dalla
                    // relazione, e un elenco diverso passato allo state fa
                    // finire i link su righe sbagliate. L'ordine e il
                    // precaricamento si decidono percio' sul record, in
                    // ViewQuoteGroup::mount() — dove la trappola e'
                    // raccontata per esteso.
                    RepeatableEntry::make('quotes')
                        ->hiddenLabel()
                        ->contained(false)
                        ->schema([
                            TextEntry::make('number')
                                ->hiddenLabel()
                                ->weight('bold')
                                ->color('primary')
                                ->icon('heroicon-m-arrow-top-right-on-square')
                                ->iconPosition(IconPosition::After)
                                ->url(fn (Quote $record) => QuoteResource::getUrl('view', ['record' => $record]))
                                ->columnSpan(['default' => 1, 'lg' => 3]),
                            TextEntry::make('cosa')
                                ->hiddenLabel()
                                ->state(fn (Quote $record) => static::cosaPropone($record))
                                ->placeholder('nessuna riga')
                                ->columnSpan(['default' => 1, 'lg' => 5]),
                            TextEntry::make('subtotal')
                                ->hiddenLabel()
                                ->money('EUR')
                                ->alignRight()
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                            TextEntry::make('status')
                                ->hiddenLabel()
                                ->badge()
                                ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                                ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray')
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                        ])
                        ->columns(12),
                ]),
            // Nota interna: si mostra solo se c'e' scritto qualcosa, che una
            // sezione vuota fa solo scorrere la pagina.
            InfolistSection::make('Note interne')
                ->columnSpanFull()
                ->visible(fn (QuoteGroup $record) => filled($record->notes))
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->schema([
                    TextEntry::make('notes')
                        ->hiddenLabel()
                        ->html()
                        ->columnSpanFull(),
                ]),
            InfolistSection::make('Storico invii email')
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->visible(fn (QuoteGroup $record) => $record->emails()->exists())
                ->schema([
                    RepeatableEntry::make('emails')
                        ->hiddenLabel()
                        ->contained(false)
                        ->schema([
                            TextEntry::make('recipient_email')->label('Destinatario'),
                            // Qui dentro non c'e' solo l'invio dell'offerta:
                            // c'e' anche il sollecito, che e' un'altra mail
                            // con un altro testo e altri pulsanti. Senza
                            // questa colonna le due si leggono uguali, e
                            // questo e' il posto in cui si va a controllare
                            // cosa e' partito.
                            TextEntry::make('tipo')
                                ->label('Che cosa')
                                ->badge()
                                ->formatStateUsing(fn (?string $state) => $state === QuoteGroupEmail::TIPO_SOLLECITO ? 'Sollecito' : 'Offerta')
                                ->color(fn (?string $state) => $state === QuoteGroupEmail::TIPO_SOLLECITO ? 'warning' : 'gray'),
                            TextEntry::make('created_at')
                                ->label(fn (QuoteGroupEmail $record) => $record->tipo === QuoteGroupEmail::TIPO_SOLLECITO ? 'Sollecitato il' : 'Inviata il')
                                ->dateTime('d/m/Y H:i'),
                            TextEntry::make('status')
                                ->label('Esito')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => $state === 'sent' ? 'Inviata' : 'Fallita')
                                ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger'),
                            TextEntry::make('error_message')
                                ->label('Errore')
                                ->columnSpanFull()
                                ->visible(fn (QuoteGroupEmail $record) => filled($record->error_message)),
                            InfolistActions::make([
                                InfolistAction::make('preview')
                                    ->label('Anteprima')
                                    ->icon('heroicon-o-eye')
                                    ->color('gray')
                                    ->modalHeading(fn (QuoteGroupEmail $record) => "Anteprima email — {$record->recipient_email}")
                                    ->modalContent(fn (QuoteGroupEmail $record) => new HtmlString(
                                        '<iframe srcdoc="'.e(static::corpoInviato($record)).'" style="width:100%;height:70vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                                    ))
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Chiudi')
                                    ->modalWidth('4xl'),
                            ]),
                        ])
                        ->columns(4),
                ]),
            // Le telefonate non si vedevano da nessuna parte: si segnano
            // dalla pagina "Da sollecitare" e finivano dentro il database.
            // Chi apre la scheda per sapere "a che punto siamo" deve vederle,
            // o la mail di sollecito sembra l'unica cosa che e' successa.
            InfolistSection::make('Quando abbiamo chiesto una risposta')
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->visible(fn (QuoteGroup $record) => $record->contattiDellaTrattativa()->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('contatti')
                        ->hiddenLabel()
                        ->contained(false)
                        ->state(fn (QuoteGroup $record) => $record->contattiDellaTrattativa()
                            ->map(fn (QuoteContatto $c) => [
                                'tipo' => QuoteContatto::tipi()[$c->tipo] ?? $c->tipo,
                                'quando' => $c->data,
                                // instanceof e non un ?->: le relazioni
                                // user() e quote() non portano il tipo, e
                                // l'analisi statica non sa che quel Model ha
                                // un nome o un numero.
                                'chi' => ($autore = $c->user) instanceof User ? $autore->name : null,
                                'dove' => ($preventivo = $c->quote) instanceof Quote
                                    ? 'Sulla soluzione '.$preventivo->number
                                    : 'Su tutta l\'offerta',
                                'nota' => $c->nota,
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('tipo')
                                ->label('Come')
                                ->badge()
                                ->color(fn (string $state) => str_contains($state, 'sollecito') ? 'warning' : 'info'),
                            TextEntry::make('quando')->label('Quando')->dateTime('d/m/Y H:i'),
                            TextEntry::make('chi')->label('Chi')->placeholder('—'),
                            TextEntry::make('dove')->label('Riferito a'),
                            TextEntry::make('nota')
                                ->label('Cosa hanno detto')
                                ->columnSpanFull()
                                ->visible(fn ($state) => filled($state)),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /**
     * Che cosa distingue un'alternativa dalle altre, in una riga.
     *
     * Si riusa QuoteProduct::diceCosaInteressa(): di un preventivo da sei
     * righe quelle che rispondono a "cosa gli stiamo proponendo?" sono la
     * macchina e il depuratore, non l'installazione o il trasporto. Senza
     * quel filtro la colonna diventava un elenco di conseguenze che
     * sull'offerta si leggono tutte uguali -- cioe' inutile come una
     * colonna vuota.
     */
    protected static function cosaPropone(Quote $quote): ?string
    {
        $nomi = [];

        foreach ($quote->baseQuoteProducts as $riga) {
            // instanceof e non un ?->: le relazioni non portano il tipo, e
            // l'analisi statica non sa che quei Model sono una riga di
            // preventivo e un prodotto a catalogo.
            if (! $riga instanceof QuoteProduct || ! $riga->diceCosaInteressa()) {
                continue;
            }

            $prodotto = $riga->product;

            if ($prodotto instanceof Product && filled($prodotto->name)) {
                // La chiave fa da unique(): la stessa macchina in due righe
                // non si scrive due volte.
                $nomi[$prodotto->name] = $prodotto->name;
            }
        }

        return $nomi === [] ? null : implode(' · ', $nomi);
    }

    /**
     * Le alternative dell'offerta, ordinate come nella mail al cliente
     * (sendGroupEmail).
     *
     * Partire da Quote e non da $gruppo->quotes() e' una scelta di tipi:
     * la relazione e' dichiarata senza generics e restituisce dei Model
     * generici, mentre qui servono dei Quote — li chiedono sia
     * SollecitoPreventivoMail sia QuoteGroupMail.
     *
     * @param  array<int, string>  $con  le relazioni da precaricare
     * @return Collection<int, Quote>
     */
    protected static function alternative(QuoteGroup $gruppo, array $con = []): Collection
    {
        return Quote::query()
            ->where('quote_group_id', $gruppo->getKey())
            ->with($con)
            ->orderBy('number')
            ->get();
    }

    /**
     * L'email com'era quando e' partita: testo e oggetto sono quelli
     * salvati sulla riga, non quelli che si genererebbero oggi.
     *
     * Sta qui e non nel relation manager degli invii perche' la stessa
     * anteprima si apre da due posti — la scheda "Vedi" e la scheda "Invii"
     * della modifica — e due copie divergono alla prima correzione.
     *
     * Il sollecito va reso con il SUO modello: dentro quello dell'offerta
     * si vedrebbe il testo giusto con i pulsanti sbagliati, cioe'
     * l'anteprima di una mail che al cliente non e' mai arrivata.
     *
     * Vedi App\Support\OutsideLivewireRender: senza, il rendering parte da
     * dentro una richiesta Livewire e i commenti <!--[if BLOCK]><![endif]-->
     * finiscono visibili nel corpo (bug del 10/11/2026 sull'anteprima del
     * preventivo all'Hotel Alemagna).
     */
    public static function corpoInviato(QuoteGroupEmail $email): string
    {
        $gruppo = $email->quoteGroup;

        // La relazione e' dichiarata su Model: senza il controllo l'analisi
        // statica non sa che e' un'offerta. Un invio senza offerta non
        // esiste (FK), ma un'anteprima vuota e' meglio di un errore.
        if (! $gruppo instanceof QuoteGroup) {
            return '';
        }

        // Stesso eager load di sendGroupEmail(): il corpo elenca le
        // proposte con le loro righe.
        $quotes = static::alternative($gruppo, ['quoteProducts.product', 'quoteProducts.options.product']);

        return OutsideLivewireRender::run(fn () => ($email->tipo === QuoteGroupEmail::TIPO_SOLLECITO
            ? new SollecitoPreventivoMail($gruppo, $quotes, $gruppo->clientUrl(), $email->message, $email->subject)
            : new QuoteGroupMail(
                $gruppo,
                $quotes,
                // Nessun PDF: l'anteprima e' il corpo, gli allegati non
                // entrano nel render().
                [],
                $email->message,
                $email->subject,
                clientUrl: $gruppo->public_token ? $gruppo->clientUrl() : null,
            ))->render());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Riepilogo offerta')
                ->columns(3)
                ->visible(fn (?QuoteGroup $record) => $record !== null)
                ->schema([
                    Forms\Components\Placeholder::make('solutions_overview')
                        ->label('Soluzioni')
                        ->content(fn (?QuoteGroup $record) => $record?->quotes()->count() ?? 0),
                    Forms\Components\Placeholder::make('sent_at_overview')
                        ->label('Inviata il')
                        ->content(fn (?QuoteGroup $record) => $record?->sent_at?->format('d/m/Y H:i') ?? '—'),
                    Forms\Components\Placeholder::make('chosen_quote_overview')
                        ->label('Soluzione scelta')
                        ->content(fn (?QuoteGroup $record) => $record?->chosenQuote()?->number ?? '—'),
                ]),
            Forms\Components\Section::make('Dati offerta globale')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Numero')
                        ->required()
                        ->disabled(fn (?QuoteGroup $record) => $record !== null)
                        ->dehydrated()
                        ->default(fn () => QuoteGroup::nextNumberForTenant(Filament::getTenant()?->id)),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::titleCase($record->full_name))
                        ->searchable(['search_name', 'company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required()
                        ->extraAttributes(['data-tour' => 'quote-groups-field-customer']),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('bozza')
                        ->required(),
                    // Editor e non Textarea: queste note si scrivono come
                    // quelle dei preventivi, e senza grassetto un testo di
                    // qualche riga diventa illeggibile. Stessa barra ridotta
                    // del preventivo, per non avere due comportamenti diversi
                    // nello stesso flusso.
                    Forms\Components\RichEditor::make('notes')
                        ->label('Note')
                        ->toolbarButtons(['bold'])
                        ->helperText('Nota interna: non viene stampata nel PDF ne\' inviata al cliente. Il testo per il cliente va nelle note dei singoli preventivi o nel corpo della mail.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->wrap()->label('Cliente')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('quotes_count')->visibleFrom('md')->counts('quotes')->label('Soluzioni'),
                // Il telefono accanto al cliente: la pagina serve a decidere
                // chi chiamare, e il numero stava solo dentro l'anagrafica.
                // Cliccabile perche' dal telefono o da un portatile con
                // softphone si chiama da qui, e copiabile per tutto il resto.
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Telefono')
                    ->state(fn ($record) => $record->customer?->primaryPhone())
                    ->url(fn ($record) => ($n = $record->customer?->primaryPhone())
                        ? 'tel:'.preg_replace('/[^0-9+]/', '', $n)
                        : null)
                    ->copyable()
                    ->copyMessage('Numero copiato')
                    ->icon('heroicon-m-phone')
                    ->iconPosition(IconPosition::Before)
                    ->color('gray')
                    ->placeholder('—'),
                // Le stesse colonne dell'elenco dei preventivi: chi passa
                // dall'uno all'altro deve trovare le stesse informazioni,
                // non doverle cercare in due posti diversi.
                Tables\Columns\TextColumn::make('customer.province')->visibleFrom('md')
                    ->label('Prov.')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                // Dell'offerta vale l'alternativa piu' cara, non la somma:
                // il cliente ne comprera' una, e sommarne sei gonfierebbe
                // il valore di sei volte (stessa regola della pagina "Da
                // sollecitare" e della mail dell'offerta).
                Tables\Columns\TextColumn::make('quotes_max_total')
                    ->max('quotes', 'total')
                    ->label('Totale')
                    ->money('EUR')
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip('La soluzione più cara dell\'offerta'),
                // Formato italiano e senza secondi: "set 23, 2026 07:07:11"
                // non e' una data che qualcuno legge, e nel resto dell'app
                // si scrive d/m/Y.
                Tables\Columns\TextColumn::make('sent_at')->visibleFrom('md')->label('Inviata il')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('client_last_viewed_at')->visibleFrom('md')
                    ->label('Visto')
                    ->since()
                    ->tooltip(fn (QuoteGroup $record) => $record->client_view_count
                        ? "Aperta {$record->client_view_count} volte, l'ultima il ".$record->client_last_viewed_at?->format('d/m/Y H:i')
                        : null)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                static::previewPdfsTableAction(),
                static::sendEmailTableAction(),
                Tables\Actions\ActionGroup::make([
                    // E' questa azione a mandare il clic sulla riga sulla
                    // scheda in sola lettura invece che nel form: Filament
                    // apre la riga con la prima fra le azioni "view" ed
                    // "edit" che trova (ListRecords), e le cerca anche dentro
                    // i menu. Sta nel menu e non in fila perche' la riga ha
                    // gia' PDF e Invia, e una quarta voce spingeva le colonne
                    // fuori dallo schermo.
                    Tables\Actions\ViewAction::make()
                        ->color('gray'),
                    Tables\Actions\EditAction::make()
                        ->color('gray'),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Form dell'azione "Invia gruppo" (email con un PDF allegato per
     * ciascun preventivo del gruppo), stesso schema di
     * QuoteResource::sendEmailFormSchema() ma tipizzato su QuoteGroup.
     *
     * @return array<Forms\Components\Component>
     */
    public static function sendEmailFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()
                ->required()
                // Al cliente, mai al pagante: vedi QuoteResource.
                ->default(fn (QuoteGroup $record) => $record->customer?->primaryEmail()),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email()
                ->helperText('I destinatari fissi impostati in Impostazioni > Notifiche ricevono comunque una copia.'),
            // Il contratto di noleggio parte con l'offerta solo se lo si
            // chiede: su un'offerta di solo acquisto non c'entra nulla
            // (Laura, 09/10/2026).
            Forms\Components\Select::make('noleggio_id')
                ->label('Allega anche il contratto di noleggio')
                ->placeholder('nessun contratto')
                ->options(fn (QuoteGroup $record) => Noleggio::query()
                    ->where('customer_id', $record->customer_id)
                    ->orderByDesc('created_at')->get()
                    ->mapWithKeys(fn (Noleggio $n) => [
                        $n->id => $n->number.' — '.($n->descrizione ? mb_substr($n->descrizione, 0, 50) : 'noleggio')
                            .' — € '.number_format((float) $n->canone, 2, ',', '.').'/mese',
                    ])->all())
                ->visible(fn (QuoteGroup $record) => Noleggio::query()->where('customer_id', $record->customer_id)->exists()),
            Forms\Components\TextInput::make('subject')
                ->label('Oggetto email')
                ->required()
                ->live(debounce: 400)
                ->default(fn (QuoteGroup $record) => static::defaultGroupEmailSubject($record)),
            // Stessa toolbar dell'invio del singolo preventivo
            // (QuoteResource::sendEmailFormSchema): qui era rimasta una
            // textarea, quindi grassetto ed elenchi non erano disponibili
            // solo sull'offerta globale.
            Forms\Components\RichEditor::make('email_body')
                ->label('Anteprima completa email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->helperText('Questo testo viene inviato realmente nella mail. Puoi modificarlo liberamente.')
                ->default(fn (QuoteGroup $record) => static::defaultGroupEmailBody($record)),
            Forms\Components\Placeholder::make('automatic_sections_preview')
                ->label('Riepilogo soluzioni e allegati (automatico)')
                ->content(fn (QuoteGroup $record, Get $get): HtmlString => new HtmlString(
                    static::buildAutomaticSectionsPreviewHtml(
                        $record,
                        is_string($get('subject')) ? $get('subject') : null,
                        (bool) $get('allega_offerta_caffe'),
                        (array) ($get('contratti_assistenza') ?? []),
                    )
                )),
            // Link alla pagina dove il cliente accetta firmando, rifiuta, fa
            // una domanda o chiede di essere richiamato (QuoteClientController):
            // tanti preventivi restavano "Inviato" senza piu' notizie.
            // I fac-simile dei due contratti Franke viaggiano con l'offerta
            // cosi' il cliente puo' leggerseli prima di decidere, invece di
            // chiederli dopo (Laura, 26/10/2026). Sono copie da leggere: il
            // contratto vero si genera dalla riga macchina del preventivo.
            Forms\Components\CheckboxList::make('contratti_assistenza')
                ->label('Allega i fac-simile dei contratti di assistenza')
                ->options(fn (QuoteGroup $record) => static::contrattiDisponibili($record))
                ->default(fn (QuoteGroup $record) => array_keys(static::contrattiDisponibili($record)))
                ->helperText('Copie da leggere, non da firmare: il canone e\' una percentuale del listino Franke (Full 10%, Easy 5%).')
                ->columns(2)
                ->live()
                ->visible(fn (QuoteGroup $record) => static::contrattiDisponibili($record) !== []),
            Forms\Components\Toggle::make('client_link')
                ->label('Includi il link per rispondere online (accetta e firma, rifiuta, domanda, richiamata)')
                ->default(true)
                ->live(),
            // L'allegato spento di default, dal 26/11/2026.
            //
            // Finche' il PDF viaggiava in allegato il link non lo apriva
            // nessuno: client_view_count era 0 su tutti e 84 i preventivi
            // fermi, cioe' non si sapeva se il cliente avesse almeno
            // guardato. Col solo link il documento sta sulla sua pagina (e
            // da li' si scarica lo stesso), e noi sappiamo se e quante
            // volte l'ha aperto -- che e' un'informazione diversa dal
            // silenzio, e molto piu' utile al telefono.
            //
            // Senza link l'allegato torna obbligatorio: una mail senza
            // documento e senza pagina non manderebbe niente.
            Forms\Components\Toggle::make('allega_pdf')
                ->label('Allega anche i PDF delle soluzioni')
                ->helperText(fn (Get $get) => $get('client_link')
                    ? 'Spento, il cliente apre le soluzioni dalla sua pagina e sappiamo quando lo fa. Acceso, si torna all\'allegato e di aperture non si sa niente.'
                    : 'Senza il link per rispondere online i PDF vanno allegati per forza.')
                ->default(false)
                ->disabled(fn (Get $get) => ! $get('client_link'))
                ->dehydrated(),
            ...OffertaCaffeFields::allegatoAllInvio(fn (?QuoteGroup $record = null) => $record?->customer, 'email_body'),
        ];
    }

    protected static function defaultGroupEmailSubject(QuoteGroup $record): string
    {
        $customerName = DisplayName::titleCase($record->customer?->company_name) ?: (DisplayName::titleCase($record->customer?->full_name) ?? 'Cliente');
        $solutionsCount = max(1, (int) $record->quotes()->count());
        $solutionsLabel = $solutionsCount === 1 ? 'soluzione' : 'soluzioni';

        return "Offerta {$record->number} - {$customerName} - {$solutionsCount} {$solutionsLabel}";
    }

    protected static function defaultGroupEmailBody(QuoteGroup $record): string
    {
        $customerName = DisplayName::titleCase($record->customer?->company_name) ?: (DisplayName::titleCase($record->customer?->full_name) ?? 'Cliente');

        // Chiusura e firma NON stanno qui: le stampa il modello dopo il
        // riepilogo e gli allegati (resources/views/mail/quote-group.blade.php).
        // Prima erano in coda al testo modificabile, quindi la mail salutava a
        // meta' e poi proseguiva con una tabella; e i recapiti comparivano due
        // volte, perche' il piede del modello (x-mail.footer-tenant) li stampa
        // gia' con indirizzo, dati fiscali e IBAN.
        return implode('', [
            '<p>Gentile '.e($customerName).',</p>',
            '<p>siamo lieti di inviarle la nostra offerta con le soluzioni proposte.</p>',
            '<p>Di seguito trova il riepilogo delle soluzioni incluse; in allegato i preventivi in formato PDF.</p>',
        ]);
    }

    /** @param  array<int, string>  $contratti  i fac-simile spuntati nel form */
    protected static function buildAutomaticSectionsPreviewHtml(QuoteGroup $record, ?string $subject, bool $conOffertaCaffe = false, array $contratti = []): string
    {
        $resolvedSubject = filled(trim((string) $subject))
            ? trim((string) $subject)
            : static::defaultGroupEmailSubject($record);

        $quotes = $record->quotes()->select([
            'number',
            'subtotal',
            'payment_method',
            'rental_monthly_fee',
            'rental_months',
        ])->orderBy('number')->get();

        $rows = $quotes->map(function ($quote): string {
            $amountLabel = '€ '.number_format((float) $quote->subtotal, 2, ',', '.').' + IVA';

            if ($quote->payment_method === 'noleggio-operativo' && $quote->rental_monthly_fee) {
                $months = max(1, (int) ($quote->rental_months ?? 1));
                $monthlyFee = (float) $quote->rental_monthly_fee;
                $amountLabel .= ' (canone '.number_format($monthlyFee, 2, ',', '.')."/mese x {$months} mesi)";
            }

            return '<tr>'
                .'<td style="padding:6px 8px;border:1px solid #e2e8f0;">'.e($quote->number).'</td>'
                .'<td style="padding:6px 8px;border:1px solid #e2e8f0;text-align:right;">'.e($amountLabel).'</td>'
                .'</tr>';
        })->implode('');

        $attachments = $quotes->map(fn ($quote): string => 'preventivo-'.$quote->number.'.pdf')
            ->when($conOffertaCaffe, fn ($nomi) => $nomi->push('offerta caffè'))
            ->merge(collect($contratti)->map(fn ($tipo): string => 'fac-simile contratto '.ContrattoAssistenza::nome((string) $tipo)))
            ->implode(', ');

        return '<div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-100">'
            .'<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Oggetto</div>'
            .'<div style="font-weight:700;margin-top:2px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e2e8f0;">'.e($resolvedSubject).'</div>'
            .'<div style="margin-top:6px;font-weight:600;">Riepilogo soluzioni</div>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:6px;">'
            .'<thead><tr><th style="padding:6px 8px;border:1px solid #e2e8f0;text-align:left;background:#f8fafc;">Preventivo</th><th style="padding:6px 8px;border:1px solid #e2e8f0;text-align:right;background:#f8fafc;">Totale proposta</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table>'
            .'<div style="margin-top:10px;"><strong>Allegati:</strong> '.e($attachments).'</div>'
            .'<div style="margin-top:10px;padding-top:8px;border-top:1px solid #e2e8f0;color:#475569;">'
            .'Restiamo a disposizione per qualsiasi chiarimento.<br>Cordiali saluti,<br>'
            .e(static::firma($record))
            .'<br><span style="font-size:11px;color:#64748b;">(chiusura e recapiti sono prestampati: non si modificano da qui)</span>'
            .'</div>'
            .'</div>';
    }

    /**
     * Chi firma la mail: la ragione sociale del tenant, o il suo nome.
     *
     * instanceof e non un ?->: la relazione tenant() arriva dal trait e non
     * porta il tipo, quindi l'analisi statica non sa che quel Model ha una
     * ragione sociale (stessa ragione di Solleciti::inCopiaSempre).
     */
    protected static function firma(QuoteGroup $record): string
    {
        $tenant = $record->tenant;

        if (! $tenant instanceof Tenant) {
            return (string) config('app.name');
        }

        return $tenant->legal_name ?: ($tenant->name ?: (string) config('app.name'));
    }

    /**
     * Il contratto di noleggio scelto nel modulo, pronto da allegare.
     *
     * @return array<string, string>
     */
    /**
     * I fac-simile dei contratti di assistenza proponibili su quest'offerta:
     * solo se c'e' una macchina Franke (gli altri marchi non hanno contratto)
     * e solo quelli il cui modello e' davvero caricato in Documenti.
     *
     * @return array<string, string>
     */
    protected static function contrattiDisponibili(QuoteGroup $record): array
    {
        if (! static::haMacchinaFranke($record)) {
            return [];
        }

        $opzioni = [];

        foreach (ContrattoAssistenza::TIPI as $tipo => $dati) {
            if (ContrattoAssistenzaPdf::modello($tipo, $record->tenant_id) !== null) {
                $opzioni[$tipo] = $dati['nome'].' ('.rtrim(rtrim(number_format($dati['percentuale'], 1, ',', ''), '0'), ',').'% del listino)';
            }
        }

        return $opzioni;
    }

    protected static function haMacchinaFranke(QuoteGroup $record): bool
    {
        // Due cicli invece di flatMap + contains: le relazioni non portano
        // il tipo, e senza gli instanceof l'analisi statica legge $type e
        // chiama perMacchina() su dei Model generici. Si esce alla prima
        // macchina trovata, che su un'offerta da sei alternative e' anche
        // meno lavoro.
        foreach (static::alternative($record, ['quoteProducts.product.brand']) as $quote) {
            foreach ($quote->quoteProducts as $riga) {
                if (! $riga instanceof QuoteProduct) {
                    continue;
                }

                $prodotto = $riga->product;

                if ($prodotto instanceof Product
                    && $prodotto->type === Product::TYPE_MACHINE
                    && ContrattoAssistenza::perMacchina($prodotto)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected static function contrattoDaAllegare(array $data, QuoteGroup $record): array
    {
        $allegati = [];

        $noleggio = filled($data['noleggio_id'] ?? null) ? Noleggio::find($data['noleggio_id']) : null;

        if ($noleggio) {
            $allegati[NoleggioResource::nomeFile($noleggio)] = NoleggioResource::buildPdf($noleggio)->output();
        }

        foreach ((array) ($data['contratti_assistenza'] ?? []) as $tipo) {
            $percorso = ContrattoAssistenzaPdf::modello((string) $tipo, $record->tenant_id);

            if ($percorso === null) {
                continue;
            }

            $nome = 'fac-simile-contratto-'.str_replace(' ', '-', mb_strtolower(ContrattoAssistenza::nome((string) $tipo))).'.pdf';
            $allegati[$nome] = (string) file_get_contents($percorso);
        }

        return $allegati;
    }

    public static function sendGroupEmail(QuoteGroup $record, array $data): void
    {
        $quotes = $record->quotes()->with(['quoteProducts.product', 'quoteProducts.options.product'])->get();

        if ($quotes->isEmpty()) {
            Notification::make()->title('Nessun preventivo nel gruppo')->danger()->send();

            return;
        }

        $email = $record->emails()->create([
            'user_id' => Auth::id(),
            'recipient_email' => $data['recipient_email'],
            'cc_email' => $data['cc_email'] ?? null,
            'subject' => $data['subject'] ?? static::defaultGroupEmailSubject($record),
            'message' => $data['email_body'] ?? static::defaultGroupEmailBody($record),
            'status' => 'sent',
        ]);

        try {
            $offertaCaffe = OffertaCaffeFields::daAllegare($data);

            $conLink = (bool) ($data['client_link'] ?? true);

            // I PDF si generano solo se partono davvero: senza link vanno
            // allegati per forza, col link solo se l'utente lo chiede. Su
            // un'offerta da sei alternative sono sei render di dompdf in
            // meno, che e' anche il pezzo piu' lento dell'invio.
            $pdfContents = ($conLink && ! ($data['allega_pdf'] ?? false))
                ? []
                : $quotes->mapWithKeys(fn ($quote) => [
                    $quote->id => QuoteResource::buildPdf($quote)->output(),
                ])->all();

            Mail::to($data['recipient_email'])
                ->cc(static::ccRecipients($record, $data))
                ->send(
                    (new QuoteGroupMail(
                        $record,
                        $quotes,
                        $pdfContents,
                        $data['email_body'] ?? static::defaultGroupEmailBody($record),
                        $data['subject'] ?? static::defaultGroupEmailSubject($record),
                        $offertaCaffe ? OffertaCaffePdf::perOfferta($offertaCaffe)->output() : null,
                        $offertaCaffe ? OffertaCaffePdf::nomeFile($offertaCaffe) : null,
                        $conLink ? $record->clientUrl() : null,
                        static::contrattoDaAllegare($data, $record),
                    ))->subject($data['subject'] ?? static::defaultGroupEmailSubject($record))
                );

            if ($offertaCaffe) {
                OffertaCaffeResource::registraInvio($offertaCaffe, $data['recipient_email'], $data['cc_email'] ?? null, $data['subject'] ?? null, $data['email_body'] ?? null, inviataCon: "Offerta {$record->number}");
            }

            // Un'offerta scaduta che si rimanda torna in attesa, come un
            // preventivo singolo (vedi QuoteResource): se la rimandi, il
            // cliente ha di nuovo una proposta valida davanti.
            if (in_array($record->status, ['bozza', 'scaduto'], true)) {
                $record->update(['status' => 'inviato', 'sent_at' => now()]);
            }

            // La validita' si scrive su TUTTE le alternative a ogni invio, non
            // solo su quelle in bozza: l'offerta che parte oggi vale da oggi,
            // e senza questa riga le offerte di gruppo restavano senza data
            // di scadenza — invisibili a preventivi:scadi e senza il "valido
            // fino al" sul PDF.
            $quotes->each(function (Quote $quote): void {
                $quote->update([
                    'status' => in_array($quote->status, [Quote::STATUS_BOZZA, Quote::STATUS_SCADUTO], true)
                        ? Quote::STATUS_INVIATO
                        : $quote->status,
                    'valid_until' => $quote->scadenzaDaOggi(),
                ]);
            });

            Notification::make()->title('Offerta globale inviata')->success()->send();
        } catch (\Throwable $e) {
            $email->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * CC manuale (facoltativo, dal form d'invio) unito ai destinatari fissi
     * del tenant configurati per le offerte globali (pagina Notifiche),
     * senza duplicati.
     *
     * @return array<int, string>
     */
    protected static function ccRecipients(QuoteGroup $record, array $data): array
    {
        return array_values(array_unique(array_filter([
            ...(array) ($data['cc_email'] ?? []),
            ...($record->tenant?->notificationRecipients('quote_group') ?? []),
        ])));
    }

    protected static function sendEmailTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('send')
            ->label('Invia')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->form(fn () => static::sendEmailFormSchema())
            ->action(fn (QuoteGroup $record, array $data) => static::sendGroupEmail($record, $data));
    }

    protected static function previewPdfsTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('previewPdfs')
            ->label('PDF')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->action(fn (QuoteGroup $record) => static::streamGroupPdfsZip($record));
    }

    /**
     * Prima di "Invia offerta globale" non c'era modo di rivedere i PDF che
     * sarebbero stati allegati al cliente. Uno zip perche' sendGroupEmail
     * allega un PDF per ciascuna soluzione alternativa (Quote), non un unico
     * PDF composito.
     */
    public static function streamGroupPdfsZip(QuoteGroup $record)
    {
        $quotes = $record->quotes()->with(['quoteProducts.product', 'quoteProducts.options.product'])->get();

        if ($quotes->isEmpty()) {
            Notification::make()->title('Nessun preventivo nel gruppo')->danger()->send();

            return null;
        }

        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'offerta-');
            $zip = new \ZipArchive;
            $zip->open($tmpPath, \ZipArchive::OVERWRITE);

            foreach ($quotes as $quote) {
                $zip->addFromString("preventivo-{$quote->number}.pdf", QuoteResource::buildPdf($quote)->output());
            }

            $zip->close();
            $contents = file_get_contents($tmpPath);
            unlink($tmpPath);
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->danger()
                ->title('Generazione PDF non riuscita')
                ->body('Si e\' verificato un errore imprevisto durante la generazione dei PDF. Riprova o contatta l\'assistenza.')
                ->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($contents), "offerta-{$record->number}.zip");
    }

    public static function getRelations(): array
    {
        return [
            QuotesRelationManager::class,
            InviiRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuoteGroups::route('/'),
            'create' => Pages\CreateQuoteGroup::route('/create'),
            'view' => Pages\ViewQuoteGroup::route('/{record}'),
            'edit' => Pages\EditQuoteGroup::route('/{record}/edit'),
        ];
    }

    /**
     * Centralizzato come QuoteResource::statusLabels(): prima erano
     * duplicati (form, colonna tabella, filtro), rischio di disallineamento
     * al primo stato aggiunto/rinominato.
     */
    public static function statusLabels(): array
    {
        return [
            'bozza' => 'Bozza',
            'inviato' => 'Inviato',
            'scelto' => 'Scelto',
            // Il cliente ha detto no a tutte le soluzioni. Senza questo stato
            // un'offerta rifiutata restava "Inviato" per sempre, in mezzo a
            // quelle che aspettano ancora una risposta (Laura, 12/11/2026:
            // Mass Bianchet ha rifiutato tutti e tre i preventivi).
            // "Scaduto" non e' la stessa cosa -- e infatti non l'ha mai usato
            // nessuno: dice che e' passato il tempo, non che hanno detto no.
            'rifiutato' => 'Rifiutato',
            'scaduto' => 'Scaduto',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'inviato' => 'warning',
            'scelto' => 'success',
            // Rifiutato e scaduto sono due modi diversi di finire male, ma
            // tutti e due finiscono: stesso grigio spento, che non chiama
            // l'occhio come il rosso di un problema da risolvere.
            'rifiutato' => 'danger',
            'scaduto' => 'gray',
        ];
    }
}
