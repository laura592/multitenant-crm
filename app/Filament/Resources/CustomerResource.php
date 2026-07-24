<?php

namespace App\Filament\Resources;

use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Clienti';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clienti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    // Nessuno dei due era obbligatorio: si poteva salvare un
                    // cliente senza alcun nome/ragione sociale, pur essendo la
                    // base di full_name usato ovunque nell'app. Richiesto
                    // almeno uno dei due (ragione sociale B2B, o nome
                    // referente se non c'e' un'azienda).
                    Forms\Components\TextInput::make('first_name')->label('Nome')->maxLength(255)
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('company_name'))),
                    Forms\Components\TextInput::make('last_name')->label('Cognome')->maxLength(255),
                    Forms\Components\TextInput::make('company_name')->label('Ragione sociale')->maxLength(255)->columnSpanFull()
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('first_name')))
                        ->helperText('Obbligatoria la ragione sociale oppure almeno il nome del referente.'),
                    ...CustomerContactFields::schema(),
                    Forms\Components\TextInput::make('website')->label('Sito web')->url()->maxLength(255),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(3)
                ->schema(ItalianAddressFields::schema(withGeocoding: true)),
            Forms\Components\Hidden::make('latitude'),
            Forms\Components\Hidden::make('longitude'),
            Forms\Components\Section::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255),
                    Forms\Components\TextInput::make('vat_number')->label('P.IVA')->maxLength(255),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                    Forms\Components\TextInput::make('pec')->label('PEC')->email()->maxLength(255),
                ]),
            Forms\Components\Section::make('Lavaggi')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('lavaggio_frequency_days')
                        ->label('Cadenza (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Es. 20 o 30. Ogni nuovo lavaggio registrato sposta in automatico la prossima scadenza di questi giorni.'),
                    Forms\Components\DatePicker::make('lavaggio_next_due_date')
                        ->label('Prossima scadenza lavaggio')
                        ->helperText('Calcolata da sola se imposti la cadenza, ma puoi correggerla a mano in qualunque momento.'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextEntry::make('company_name')->label('Ragione sociale')->placeholder('—'),
                    TextEntry::make('full_name')->label('Referente')->placeholder('—'),
                    TextEntry::make('emails')->label('Email')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('phones')->label('Telefoni')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('website')->label('Sito web')->placeholder('—')
                        ->url(fn (Customer $record) => $record->website, shouldOpenInNewTab: true),
                ]),
            InfolistSection::make('Indirizzo')
                ->columns(3)
                ->schema([
                    TextEntry::make('street')->label('Via')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('postal_code')->label('CAP')->placeholder('—'),
                    TextEntry::make('city')->label('Città')->placeholder('—'),
                    TextEntry::make('province')->label('Provincia')->placeholder('—'),
                ]),
            InfolistSection::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    TextEntry::make('tax_code')->label('Codice fiscale')->placeholder('—'),
                    TextEntry::make('vat_number')->label('P.IVA')->placeholder('—'),
                    TextEntry::make('sdi')->label('Codice SDI')->placeholder('—'),
                    TextEntry::make('pec')->label('PEC')->placeholder('—'),
                ]),
            InfolistSection::make('Lavaggi')
                ->columns(2)
                ->schema([
                    TextEntry::make('lavaggio_frequency_days')
                        ->label('Cadenza')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?int $state) => $state ? "Ogni {$state} giorni" : null),
                    TextEntry::make('lavaggio_next_due_date')
                        ->label('Prossima scadenza')
                        ->date()
                        ->placeholder('—')
                        ->color(fn (Customer $record) => match (true) {
                            $record->lavaggio_next_due_date === null => 'gray',
                            $record->lavaggio_next_due_date->isPast() => 'danger',
                            $record->lavaggio_next_due_date->diffInDays(now()) <= 5 => 'warning',
                            default => 'success',
                        }),
                ]),
            InfolistSection::make('Gestionale')
                ->columns(3)
                ->schema([
                    TextEntry::make('source')
                        ->label('Origine')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === Customer::SOURCE_GESTIONALE ? 'Gestionale' : 'App'),
                    TextEntry::make('gestionale_code')->label('Codice gestionale')->placeholder('—'),
                    TextEntry::make('approved_for_gestionale_at')->label('Pronto per invio dal')->date()->placeholder('—'),
                    TextEntry::make('sent_to_gestionale_at')->label('Inviato il')->date()->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Ragione sociale')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('emails')
                    ->label('Email')
                    ->listWithLineBreaks()
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereRaw('JSON_SEARCH(emails, "one", ?) IS NOT NULL', ["%{$search}%"])),
                Tables\Columns\TextColumn::make('phones')
                    ->label('Telefoni')
                    ->listWithLineBreaks()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('website')
                    ->label('Sito web')
                    ->placeholder('—')
                    ->url(fn (Customer $record) => $record->website, shouldOpenInNewTab: true)
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')->label('Città')->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Origine')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Customer::SOURCE_GESTIONALE => 'Gestionale',
                        default => 'App',
                    })
                    ->color(fn (string $state) => match ($state) {
                        Customer::SOURCE_GESTIONALE => 'gray',
                        default => 'info',
                    })
                    ->icon(fn (Customer $record) => match (true) {
                        $record->source === Customer::SOURCE_GESTIONALE => null,
                        $record->sent_to_gestionale_at !== null => 'heroicon-o-check-circle',
                        default => 'heroicon-o-x-circle',
                    })
                    ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                    ->iconColor(fn (Customer $record) => match (true) {
                        $record->sent_to_gestionale_at !== null => 'success',
                        $record->approved_for_gestionale_at !== null => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Customer $record) => match (true) {
                        $record->source === Customer::SOURCE_GESTIONALE => 'Origine gestionale',
                        $record->sent_to_gestionale_at !== null => 'Inviato al gestionale il '.$record->sent_to_gestionale_at->format('d/m/Y'),
                        $record->approved_for_gestionale_at !== null => 'Pronto per invio al gestionale (preventivo accettato)',
                        default => 'Non ancora inviato al gestionale',
                    }),
            ])
            ->defaultSort('company_name')
            ->filters([
                Tables\Filters\Filter::make('no_location')
                    ->label('Senza posizione GPS')
                    ->query(fn ($query) => $query->where(function ($inner) {
                        $inner->whereNull('latitude')->orWhereNull('longitude');
                    })),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Origine')
                    ->options([
                        Customer::SOURCE_GESTIONALE => 'Gestionale',
                        Customer::SOURCE_APP => 'App',
                    ]),
                Tables\Filters\Filter::make('ready_for_gestionale')
                    ->label('Pronti per invio al gestionale')
                    ->query(fn ($query) => $query
                        ->where('source', Customer::SOURCE_APP)
                        ->whereNotNull('approved_for_gestionale_at')
                        ->whereNull('sent_to_gestionale_at')),
            ])
            ->actions([
                Tables\Actions\Action::make('segna_inviato_gestionale')
                    ->label('Segna come inviato al gestionale')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Customer $record) => $record->readyForGestionaleSync())
                    ->requiresConfirmation()
                    ->action(fn (Customer $record) => $record->markSentToGestionale()),
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
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
            ])
            ->emptyStateHeading('Nessun cliente ancora')
            ->emptyStateDescription('Aggiungi il primo cliente con "Nuovo".')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
