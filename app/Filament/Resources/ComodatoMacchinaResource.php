<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComodatoMacchinaResource\Pages;
use App\Models\ComodatoMacchina;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Piano di comodato d'uso macchina: costi di investimento/manutenzione e
 * calcolo del costo per erogazione (vedi ComodatoMacchina::calcolaCostoPerErogazione()).
 * Tabella e modello esistevano gia' (files/DATABASE-SCHEMA.md) ma senza
 * risorsa Filament non erano raggiungibili da nessuna schermata: era quindi
 * impossibile crearne uno nuovo, pur essendo gia' selezionabile come
 * "Macchina (comodato)" da Interventi/Manutenzioni.
 */
class ComodatoMacchinaResource extends Resource
{
    protected static ?string $model = ComodatoMacchina::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Comodato macchine';

    protected static ?string $modelLabel = 'Comodato macchina';

    protected static ?string $pluralModelLabel = 'Comodato macchine';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Macchina e cliente')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nome_macchina')->label('Macchina')->required()->maxLength(255),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload(),
                ]),
            Forms\Components\Section::make('Investimento')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('costo_macchina')->label('Costo macchina (€)')->numeric()->required(),
                    Forms\Components\TextInput::make('costo_attrezzatura')->label('Costo attrezzatura (€)')->numeric()->default(0),
                    Forms\Components\TextInput::make('anni_ammortamento')->label('Anni ammortamento')->numeric()->required(),
                ]),
            Forms\Components\Section::make('Consumabili e manutenzione')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('prezzo_annuale_consumabili')->label('Consumabili annui (€)')->numeric()->default(0),
                    Forms\Components\TextInput::make('costi_manutenzione_annui')->label('Manutenzione annua (€)')->numeric()->default(0),
                    Forms\Components\TextInput::make('costo_caffe_per_battitura')->label('Costo caffè per battitura (€)')->numeric()->default(0),
                ]),
            Forms\Components\Section::make('Erogazioni')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('erogazioni_annuali_minime')->label('Erogazioni annue minime')->numeric(),
                    Forms\Components\TextInput::make('erogazioni_previste_annue')->label('Erogazioni annue previste')->numeric(),
                ]),
            Forms\Components\Section::make('Canone e margine')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('canone_fisso_annuale')->label('Canone fisso annuale (€)')->numeric()->default(0),
                    Forms\Components\TextInput::make('margine_percentuale')->label('Margine (%)')->numeric()->default(0),
                ]),
            Forms\Components\Textarea::make('note')->label('Note')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nome_macchina')->label('Macchina')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->placeholder('—'),
                Tables\Columns\TextColumn::make('costo_macchina')->label('Costo macchina')->money('EUR'),
                Tables\Columns\TextColumn::make('anni_ammortamento')->label('Anni amm.'),
                Tables\Columns\TextColumn::make('costo_per_battitura')->label('Costo/battitura')
                    ->state(fn (ComodatoMacchina $record) => $record->costo_per_battitura !== null
                        ? number_format((float) $record->costo_per_battitura, 4).' €'
                        : '—'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Macchina')
                ->columns(2)
                ->schema([
                    TextEntry::make('nome_macchina')->label('Macchina'),
                    TextEntry::make('customer.company_name')->label('Cliente')->placeholder('—'),
                ]),
            InfolistSection::make('Costo per erogazione')
                ->description('Calcolato sulle erogazioni annue previste (o minime, se le previste non sono impostate).')
                ->columns(3)
                ->schema([
                    TextEntry::make('dettaglio.ammortamento_per_erogazione')
                        ->label('Ammortamento')
                        ->state(fn (ComodatoMacchina $record) => self::dettaglio($record)['ammortamento_per_erogazione'] ?? null)
                        ->money('EUR')
                        ->placeholder('—'),
                    TextEntry::make('dettaglio.manutenzione_per_erogazione')
                        ->label('Manutenzione')
                        ->state(fn (ComodatoMacchina $record) => self::dettaglio($record)['manutenzione_per_erogazione'] ?? null)
                        ->money('EUR')
                        ->placeholder('—'),
                    TextEntry::make('dettaglio.consumabili_per_erogazione')
                        ->label('Consumabili')
                        ->state(fn (ComodatoMacchina $record) => self::dettaglio($record)['consumabili_per_erogazione'] ?? null)
                        ->money('EUR')
                        ->placeholder('—'),
                    TextEntry::make('dettaglio.caffe_per_erogazione')
                        ->label('Caffè')
                        ->state(fn (ComodatoMacchina $record) => self::dettaglio($record)['caffe_per_erogazione'] ?? null)
                        ->money('EUR')
                        ->placeholder('—'),
                    TextEntry::make('dettaglio.canone_fisso_per_erogazione')
                        ->label('Canone fisso')
                        ->state(fn (ComodatoMacchina $record) => self::dettaglio($record)['canone_fisso_per_erogazione'] ?? null)
                        ->money('EUR')
                        ->placeholder('—'),
                    TextEntry::make('costo_per_battitura')
                        ->label('Totale per erogazione')
                        ->state(fn (ComodatoMacchina $record) => $record->costo_per_battitura)
                        ->money('EUR')
                        ->weight('bold')
                        ->placeholder('—'),
                ]),
            InfolistSection::make('Note')
                ->schema([
                    TextEntry::make('note')->label('')->placeholder('—')->columnSpanFull(),
                ])
                ->visible(fn (ComodatoMacchina $record) => filled($record->note)),
        ]);
    }

    /** @return array<string, mixed> */
    protected static function dettaglio(ComodatoMacchina $record): array
    {
        return $record->calcolaCostoPerErogazione()['dettaglio'] ?? [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComodatoMacchine::route('/'),
            'create' => Pages\CreateComodatoMacchina::route('/create'),
            'view' => Pages\ViewComodatoMacchina::route('/{record}'),
            'edit' => Pages\EditComodatoMacchina::route('/{record}/edit'),
        ];
    }
}
