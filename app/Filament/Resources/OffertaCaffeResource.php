<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Forms\OffertaCaffeFields;
use App\Filament\Resources\OffertaCaffeResource\Pages;
use App\Filament\Resources\OffertaCaffeResource\RelationManagers\InviiRelationManager;
use App\Mail\OffertaCaffeMail;
use App\Models\OffertaCaffe;
use App\Support\DisplayName;
use App\Support\Pdf\OffertaCaffePdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Le offerte caffe' salvate, come i preventivi: si prepara l'offerta, si
 * guarda il PDF, la si manda quando si vuole, e resta lo storico di cosa e'
 * stato offerto a chi e a che prezzo (21/09/2026).
 */
class OffertaCaffeResource extends Resource
{
    use ApreStampeInNuovaScheda;

    protected static ?string $model = OffertaCaffe::class;

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static ?string $tenantRelationshipName = 'offerteCaffe';

    protected static ?string $slug = 'offerte-caffe';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Offerte caffè';

    protected static ?string $modelLabel = 'offerta caffè';

    protected static ?string $pluralModelLabel = 'Offerte caffè';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->required()
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Forms\Components\DatePicker::make('date')
                        ->label('Data')
                        ->default(now())
                        ->required(),
                ]),
            Forms\Components\Section::make()
                ->columns(2)
                ->schema(OffertaCaffeFields::campi()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Cliente')
                    ->wrap()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')->label('Data')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('valida_fino')->visibleFrom('md')->label('Valida fino al')->date('d/m/Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('righe')->visibleFrom('md')
                    ->label('Caffè')
                    ->state(fn (OffertaCaffe $record) => collect($record->righe)->pluck('nome')->filter()->implode(', '))
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => OffertaCaffe::STATI[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'inviata' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('user.name')->visibleFrom('md')->label('Fatta da')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Stato')->options(OffertaCaffe::STATI),
            ])
            ->actions([
                static::pdfTableAction(),
                static::inviaTableAction(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->color('gray'),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /** @param  mixed  $livewire  il componente da cui parte l'azione */
    public static function apriPdf(OffertaCaffe $record, $livewire): void
    {
        static::apriPdfInNuovaScheda(
            fn () => OffertaCaffePdf::perOfferta($record),
            OffertaCaffePdf::nomeFile($record),
            $livewire,
        );
    }

    /** @return array<Forms\Components\Component> */
    public static function inviaFormSchema(): array
    {
        return [
            // Al cliente, mai al pagante: la stessa regola del preventivo.
            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()
                ->required()
                ->default(fn (OffertaCaffe $record) => $record->customer?->primaryEmail()),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email()
                ->helperText('I destinatari fissi dei preventivi (Impostazioni > Notifiche) ricevono comunque una copia.'),
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->default(fn (OffertaCaffe $record) => static::testoEmail($record)),
        ];
    }

    /** Si saluta il cliente e firma l'azienda, come nella mail del preventivo. */
    public static function testoEmail(OffertaCaffe $record): string
    {
        $cliente = $record->customer;
        $nome = DisplayName::titleCase($cliente?->company_name) ?: (DisplayName::titleCase($cliente?->full_name) ?? 'Cliente');
        $firma = $record->tenant?->legal_name ?: ($record->tenant?->name ?: config('app.name'));

        return implode('', [
            '<p>Gentile '.e($nome).',</p>',
            '<p>in allegato la nostra offerta per il caffè e i prodotti solubili.</p>',
            '<p>Restiamo a disposizione per qualsiasi chiarimento.</p>',
            '<p>Grazie,<br>'.e($firma).'</p>',
        ]);
    }

    public static function invia(OffertaCaffe $record, array $data): void
    {
        $cc = array_values(array_unique(array_filter([
            $data['cc_email'] ?? null,
            ...($record->tenant?->notificationRecipients('quote') ?? []),
        ])));

        try {
            Mail::to($data['recipient_email'])
                ->cc($cc)
                ->send(new OffertaCaffeMail($record, OffertaCaffePdf::perOfferta($record)->output(), $data['custom_message'] ?? null));

            static::registraInvio($record, $data['recipient_email'], $data['cc_email'] ?? null, "Offerta caffè {$record->number}", $data['custom_message'] ?? null);

            Notification::make()->title('Offerta caffè inviata')->success()->send();
        } catch (\Throwable $e) {
            report($e);
            static::registraInvio($record, $data['recipient_email'], $data['cc_email'] ?? null, "Offerta caffè {$record->number}", $data['custom_message'] ?? null, errore: $e->getMessage());

            Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Annota l'invio nello storico dell'offerta. Da sola o insieme a un
     * preventivo: in quel caso $inviataCon dice con quale.
     */
    public static function registraInvio(OffertaCaffe $record, string $destinatario, ?string $cc, ?string $oggetto, ?string $messaggio, ?string $inviataCon = null, ?string $errore = null): void
    {
        $record->emails()->create([
            'user_id' => Auth::id(),
            'inviata_con' => $inviataCon,
            'recipient_email' => $destinatario,
            'cc_email' => $cc,
            'subject' => $oggetto,
            'message' => $messaggio,
            'status' => $errore ? 'failed' : 'sent',
            'error_message' => $errore,
        ]);

        if (! $errore && $record->status === 'bozza') {
            $record->update(['status' => 'inviata']);
        }
    }

    protected static function pdfTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pdf')
            ->label('PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(fn (OffertaCaffe $record, $livewire) => static::apriPdf($record, $livewire));
    }

    protected static function inviaTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('invia')
            ->label('Invia')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->form(fn () => static::inviaFormSchema())
            ->action(fn (OffertaCaffe $record, array $data) => static::invia($record, $data));
    }

    public static function getRelations(): array
    {
        return [
            InviiRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOfferteCaffe::route('/'),
            'create' => Pages\CreateOffertaCaffe::route('/create'),
            'edit' => Pages\EditOffertaCaffe::route('/{record}'),
        ];
    }
}
