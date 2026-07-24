<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteGroupResource\Pages;
use App\Filament\Resources\QuoteGroupResource\RelationManagers\QuotesRelationManager;
use App\Mail\QuoteGroupMail;
use App\Models\QuoteGroup;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

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

    protected static ?string $navigationLabel = 'Offerte';

    protected static ?string $modelLabel = 'Offerta globale';

    protected static ?string $pluralModelLabel = 'Offerte globali';

    protected static ?string $recordTitleAttribute = 'number';

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
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('bozza')
                        ->required(),
                    Forms\Components\Textarea::make('notes')
                        ->label('Note')
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
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('quotes_count')->counts('quotes')->label('Soluzioni'),
                Tables\Columns\TextColumn::make('sent_at')->label('Inviata il')->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
            ])
            ->actions([
                static::previewPdfsTableAction(),
                static::sendEmailTableAction(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->color('gray'),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
                ->default(fn (QuoteGroup $record) => $record->customer?->primaryEmail()),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email()
                ->helperText('I destinatari fissi impostati in Impostazioni > Notifiche ricevono comunque una copia.'),
            Forms\Components\TextInput::make('subject')
                ->label('Oggetto email')
                ->required()
                ->live(debounce: 400)
                ->default(fn (QuoteGroup $record) => static::defaultGroupEmailSubject($record)),
            Forms\Components\Textarea::make('email_body')
                ->label('Anteprima completa email (modificabile)')
                ->rows(14)
                ->helperText('Questo testo viene inviato realmente nella mail. Puoi modificarlo liberamente.')
                ->default(fn (QuoteGroup $record) => static::defaultGroupEmailBody($record)),
        ];
    }

    protected static function defaultGroupEmailSubject(QuoteGroup $record): string
    {
        $customerName = $record->customer?->company_name ?: ($record->customer?->full_name ?? 'Cliente');
        $solutionsCount = max(1, (int) $record->quotes()->count());
        $solutionsLabel = $solutionsCount === 1 ? 'soluzione' : 'soluzioni';

        return "Offerta {$record->number} - {$customerName} - {$solutionsCount} {$solutionsLabel}";
    }

    protected static function defaultGroupEmailBody(QuoteGroup $record): string
    {
        $customerName = $record->customer?->company_name ?: ($record->customer?->full_name ?? 'Cliente');
        $tenant = $record->tenant ?: $record->customer?->tenant;
        $signatureLines = static::commercialSignatureLines($tenant);

        return implode("\n", [
            "Gentile {$customerName},",
            '',
            'siamo lieti di inviarle la nostra offerta con le soluzioni proposte.',
            '',
            'Di seguito trova il riepilogo delle soluzioni incluse; in allegato i preventivi in formato PDF.',
            '',
            'Restiamo a disposizione per qualsiasi chiarimento.',
            '',
            ...$signatureLines,
        ]);
    }

    protected static function commercialSignatureLines(?\App\Models\Tenant $tenant): array
    {
        $contact = Auth::user()?->name ?: ($tenant?->name ?? config('app.name'));

        $contacts = array_values(array_filter([
            $tenant?->phone ? 'Tel. '.$tenant->phone : null,
            $tenant?->email,
        ]));

        return array_values(array_filter([
            'Cordiali saluti,',
            $contact,
            $tenant?->legal_name && $tenant->legal_name !== $contact ? $tenant->legal_name : null,
            ! empty($contacts) ? implode(' - ', $contacts) : null,
        ]));
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
            $pdfContents = $quotes->mapWithKeys(fn ($quote) => [
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
                    ))->subject($data['subject'] ?? static::defaultGroupEmailSubject($record))
                );

            if ($record->status === 'bozza') {
                $record->update(['status' => 'inviato', 'sent_at' => now()]);
            }

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
            ->label('Invia offerta globale')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->form(fn () => static::sendEmailFormSchema())
            ->action(fn (QuoteGroup $record, array $data) => static::sendGroupEmail($record, $data));
    }

    protected static function previewPdfsTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('previewPdfs')
            ->label('Anteprima PDF')
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
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuoteGroups::route('/'),
            'create' => Pages\CreateQuoteGroup::route('/create'),
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
            'scaduto' => 'Scaduto',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'inviato' => 'warning',
            'scelto' => 'success',
            'scaduto' => 'danger',
        ];
    }
}
