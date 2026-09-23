<?php

namespace App\Filament\Forms;

use App\Models\Customer;
use App\Models\OffertaCaffe;
use App\Models\ProdottoCaffe;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * I campi dell'offerta caffe', gli stessi dalla pagina Offerte caffe' e
 * dall'invio di un preventivo, cosi' il foglio esce uguale da qualunque parte
 * lo si prepari.
 *
 * I caffe' si scelgono uno per uno dal listino: si parte vuoti, non da tutto
 * il listino da sfoltire (21/09/2026). Prezzo e formato arrivano dal listino
 * e si ritoccano per il cliente; sull'offerta resta una copia, cosi' un
 * cambio di listino non tocca cio' che e' gia' stato promesso.
 */
final class OffertaCaffeFields
{
    /** @return array<Forms\Components\Component> */
    public static function campi(): array
    {
        return [
            Forms\Components\Repeater::make('righe')
                ->label('Caffè da offrire')
                ->schema([
                    Forms\Components\Select::make('prodotto_caffe_id')
                        ->label('Prodotto')
                        ->options(fn () => self::opzioniListino())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $prodotto = $state ? ProdottoCaffe::find($state) : null;
                            $set('nome', $prodotto?->nome);
                            $set('gruppo', $prodotto?->gruppo);
                            $set('formato', $prodotto?->formato);
                            $set('prezzo', MoneyInput::format($prodotto?->prezzo));
                        })
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    Forms\Components\Hidden::make('nome'),
                    Forms\Components\Hidden::make('gruppo'),
                    Forms\Components\TextInput::make('formato')
                        ->label('Formato')
                        ->columnSpan(1),
                    MoneyInput::make('prezzo')
                        ->label('Prezzo')
                        ->helperText('Solo per questa offerta: il listino non cambia.')
                        ->required()
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                ])
                ->columns(6)
                ->defaultItems(1)
                ->addActionLabel('Aggiungi un caffè')
                ->reorderable(false)
                ->minItems(1)
                ->columnSpanFull(),
            Forms\Components\DatePicker::make('valida_fino')
                ->label('Valida fino al')
                ->default(now()->addDays(30)),
            // 22%: e' l'aliquota con cui il gestionale fattura caffe',
            // decaffeinato, orzo e cioccolato (verificato sulle fatture
            // Eureka il 21/09/2026). I prezzi del listino sono imponibili.
            Forms\Components\Textarea::make('note')
                ->label('Note')
                ->default('Prezzi IVA 22% esclusa.')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    /**
     * Il listino attivo diviso per gruppo, con formato e prezzo nell'etichetta
     * per scegliere senza aprire il listino.
     *
     * @return array<string, array<string, string>>
     */
    public static function opzioniListino(): array
    {
        return ProdottoCaffe::query()->inListino()->get()
            ->groupBy(fn (ProdottoCaffe $p) => $p->etichettaGruppo())
            ->map(fn ($prodotti) => $prodotti->mapWithKeys(fn (ProdottoCaffe $p) => [
                $p->getKey() => collect([$p->nome, $p->formato])->filter()->implode(' · ')
                    .' — € '.number_format((float) $p->prezzo, 2, ',', '.'),
            ])->all())
            ->all();
    }

    /**
     * Per il form d'invio di un preventivo o di un gruppo: si sceglie una
     * delle offerte caffe' del cliente, o se ne crea una li' per li', e parte
     * nella stessa email come secondo allegato.
     *
     * Accendendo l'interruttore il testo della mail la nomina da solo (e la
     * frase sparisce spegnendolo): un allegato che la mail non cita il
     * cliente rischia di non aprirlo.
     *
     * @param  \Closure(mixed): ?Customer  $cliente  riceve il record dell'invio (preventivo o gruppo)
     * @param  string  $campoTesto  il RichEditor col testo della mail in quel form
     * @return array<Forms\Components\Component>
     */
    public static function allegatoAllInvio(\Closure $cliente, string $campoTesto): array
    {
        $puo = fn () => auth()->user()?->can('viewAny', OffertaCaffe::class) ?? false;

        return [
            Forms\Components\Toggle::make('allega_offerta_caffe')
                ->label('Allega anche un\'offerta caffè')
                ->visible($puo)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set, ?bool $state) => $set(
                    $campoTesto,
                    $state ? self::conFraseCaffe((string) $get($campoTesto)) : self::senzaFraseCaffe((string) $get($campoTesto)),
                )),
            Forms\Components\Select::make('offerta_caffe_id')
                ->label('Offerta caffè')
                ->helperText('Le offerte caffè di questo cliente. Con il + ne prepari una nuova.')
                ->visible(fn (Get $get) => $puo() && $get('allega_offerta_caffe'))
                ->required(fn (Get $get) => (bool) $get('allega_offerta_caffe'))
                ->options(fn ($record) => OffertaCaffe::query()
                    ->where('customer_id', $cliente($record)?->getKey())
                    ->latest('date')->latest()
                    ->get()
                    ->mapWithKeys(fn (OffertaCaffe $o) => [$o->getKey() => $o->etichetta()])
                    ->all())
                ->default(fn ($record) => OffertaCaffe::query()
                    ->where('customer_id', $cliente($record)?->getKey())
                    ->latest('date')->latest()
                    ->value('id'))
                ->createOptionForm(self::campi())
                ->createOptionModalHeading('Nuova offerta caffè')
                ->createOptionUsing(fn (array $data, $record) => OffertaCaffe::create([
                    ...$data,
                    'tenant_id' => $cliente($record)?->tenant_id,
                    'customer_id' => $cliente($record)?->getKey(),
                ])->getKey()),
        ];
    }

    public const FRASE_EMAIL = '<p>In allegato trova anche la nostra offerta per il caffè e i prodotti solubili.</p>';

    /**
     * La frase va prima dei saluti ("Restiamo a disposizione..."), dove
     * c'e': altrimenti in coda. Se c'e' gia', il testo resta com'e'.
     */
    public static function conFraseCaffe(string $testo): string
    {
        if (str_contains($testo, self::FRASE_EMAIL)) {
            return $testo;
        }

        $saluti = mb_stripos($testo, 'Restiamo a disposizione');
        $inizioParagrafo = $saluti === false ? false : mb_strrpos(mb_substr($testo, 0, $saluti), '<');

        if ($inizioParagrafo === false) {
            return $testo.self::FRASE_EMAIL;
        }

        return mb_substr($testo, 0, $inizioParagrafo).self::FRASE_EMAIL.mb_substr($testo, $inizioParagrafo);
    }

    public static function senzaFraseCaffe(string $testo): string
    {
        return str_replace(self::FRASE_EMAIL, '', $testo);
    }

    /** L'offerta caffe' scelta nel form d'invio, se l'interruttore e' acceso. */
    public static function daAllegare(array $data): ?OffertaCaffe
    {
        if (empty($data['allega_offerta_caffe']) || empty($data['offerta_caffe_id'])) {
            return null;
        }

        return OffertaCaffe::find($data['offerta_caffe_id']);
    }
}
