<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\CustomerResource;
use App\Models\ProdottoCaffe;
use App\Support\Pdf\OffertaCaffePdf;
use Filament\Actions;
use Filament\Forms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    use ApreStampeInNuovaScheda;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Il modulo che il cliente deve firmare, gia' compilato con quello
            // che sappiamo di lui: vedi App\Support\Pdf\SchedaAnagraficaPdf.
            Actions\Action::make('scheda_anagrafica')
                ->label('Scheda anagrafica')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('customers.scheda-anagrafica', $this->record)),
            // Documento a parte dal preventivo: l'ufficio non vuole il caffe'
            // fra le righe della macchina, e quanti chili prendera' il cliente
            // non si sa (21/09/2026). Parte dal listino caffe' e i prezzi si
            // ritoccano qui per questo cliente, senza toccare il listino.
            Actions\Action::make('offerta_caffe')
                ->label('Offerta caffè')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('viewAny', ProdottoCaffe::class) ?? false)
                ->modalHeading('Offerta caffè')
                ->modalDescription('Prezzi dal listino caffè. Quello che cambi qui vale solo per questa offerta.')
                ->modalSubmitActionLabel('Crea PDF')
                ->modalWidth('3xl')
                ->form([
                    Forms\Components\Repeater::make('righe')
                        ->label('Prodotti')
                        ->schema([
                            Forms\Components\Hidden::make('gruppo')->default('caffe'),
                            Forms\Components\TextInput::make('nome')
                                ->label('Prodotto')
                                ->required()
                                ->columnSpan(3),
                            Forms\Components\TextInput::make('formato')
                                ->label('Formato')
                                ->columnSpan(1),
                            MoneyInput::make('prezzo')
                                ->label('Prezzo')
                                ->required()
                                ->columnSpan(2),
                        ])
                        ->columns(6)
                        // I prezzi gia' all'italiana: il default di un Repeater
                        // non passa da formatStateUsing, e la maschera dei soldi
                        // leggerebbe il punto di "17.80" come migliaia (1.780).
                        ->default(fn () => collect(OffertaCaffePdf::righeDaListino())
                            ->map(fn (array $riga) => [...$riga, 'prezzo' => MoneyInput::format($riga['prezzo'])])
                            ->all())
                        ->addActionLabel('Aggiungi un prodotto')
                        ->reorderable(false)
                        ->minItems(1),
                    Forms\Components\DatePicker::make('valida_fino')
                        ->label('Valida fino al')
                        ->default(now()->addDays(30)),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->default('Prezzi IVA esclusa.')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $cliente = $this->record;

                    static::apriPdfInNuovaScheda(
                        fn () => OffertaCaffePdf::crea(
                            $cliente,
                            $data['righe'] ?? [],
                            filled($data['valida_fino'] ?? null) ? Carbon::parse($data['valida_fino']) : null,
                            $data['note'] ?? null,
                        ),
                        'offerta-caffe-'.Str::slug((string) $cliente->company_name).'-'.now()->format('Y-m-d').'.pdf',
                        $this,
                    );
                }),
            Actions\EditAction::make(),
        ];
    }
}
