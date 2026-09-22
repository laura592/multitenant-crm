<?php

namespace App\Filament\Widgets\Gestionale;

use App\Filament\Resources\ServiceReportResource;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use App\Support\Gestionale\ControlloPaganteFattura;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Schede Eureka il cui pagante non torna con la fattura
 * (ControlloPaganteFattura). Il CRM non corregge niente: la scheda si
 * corregge su Eureka, poi "Ricontrolla" la rilegge e il CRM si allinea.
 */
class GestionaleSchedeDaCorreggereWidget extends BaseWidget
{
    protected static ?string $heading = 'Schede da correggere su Eureka — pagante diverso dalla fattura';

    protected int|string|array $columnSpan = 'full';

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        return ServiceReport::query()->whereNotNull('pagante_fattura_customer_id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('schedeDaCorreggere')
            ->query(static::baseQuery()->with(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer']))
            ->defaultSort('intervention_date', 'desc')
            ->description('Il pagante del CRM è quello della scheda Eureka. Qui ci sono le schede la cui fattura è intestata a un altro: correggi la scheda su Eureka, poi "Ricontrolla".')
            ->headerActions([
                Tables\Actions\Action::make('ricontrolla_tutte')
                    ->label('Ricontrolla tutte su Eureka')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function () {
                        $esito = ControlloPaganteFattura::ricontrolla(Filament::getTenant());
                        Notification::make()
                            ->title("Sistemate: {$esito['sistemati']} — ancora da correggere: {$esito['ancora']}")
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Rapportino')
                    ->weight('medium')
                    ->searchable()
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record]))
                    ->description(fn (ServiceReport $record) => $record->gestionale_number),

                Tables\Columns\TextColumn::make('intervention_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('pagante_scheda')
                    ->label('Pagante sulla scheda')
                    ->state(fn (ServiceReport $record) => DisplayName::titleCase(rescue(fn () => $record->invoiceRecipient()->company_name, null, false)))
                    ->wrap(),

                Tables\Columns\TextColumn::make('pagante_fattura')
                    ->label('Fattura intestata a')
                    ->state(fn (ServiceReport $record) => DisplayName::titleCase(Customer::withoutGlobalScopes()->whereKey($record->pagante_fattura_customer_id)->value('company_name')))
                    ->weight('medium')
                    ->color('danger')
                    ->wrap(),

                Tables\Columns\TextColumn::make('fattura')
                    ->label('Fattura')
                    ->state(fn (ServiceReport $record) => $record->etichettaFatturaEureka())
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('ricontrolla')
                    ->label('Ricontrolla su Eureka')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (ServiceReport $record) {
                        $esito = ControlloPaganteFattura::ricontrolla(Filament::getTenant(), collect([$record]));
                        Notification::make()
                            ->title($esito['ancora'] ? 'Su Eureka la scheda non torna ancora con la fattura' : 'Scheda sistemata: il pagante è allineato')
                            ->{$esito['ancora'] ? 'warning' : 'success'}()
                            ->send();
                    }),

                Tables\Actions\Action::make('va_bene')
                    ->label('Va bene così')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('La differenza fra scheda e fattura è voluta: non si segnala più, finché non cambia la scheda o la fattura. Il pagante nel CRM resta quello della scheda.')
                    ->action(fn (ServiceReport $record) => ControlloPaganteFattura::vaBene($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('ricontrolla_selezionate')
                    ->label('Ricontrolla le selezionate')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Collection $records) {
                        $esito = ControlloPaganteFattura::ricontrolla(Filament::getTenant(), $records);
                        Notification::make()->title("Sistemate: {$esito['sistemati']} — ancora da correggere: {$esito['ancora']}")->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50, 100]);
    }
}
