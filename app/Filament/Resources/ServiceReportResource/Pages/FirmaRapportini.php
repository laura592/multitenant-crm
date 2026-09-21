<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Resources\ServiceReportResource;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Firma in blocco (21/09/2026): il tecnico fuori da un cliente compila un
 * rapportino per macchina con "Salva e nuovo (stesso cliente)", senza far
 * firmare ogni volta; alla fine gira il tablet e il cliente firma una volta
 * sola per tutti. Con un solo rapportino e' il "Salva e fai firmare".
 *
 * Ogni rapportino resta un documento a se' (una scheda Eureka, un
 * rapportino): la stessa firma viene riportata su ciascuno.
 */
class FirmaRapportini extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = ServiceReportResource::class;

    protected static string $view = 'filament.resources.service-report-resource.pages.firma-rapportini';

    public ?string $cliente = null;

    public ?array $data = [];

    public function mount(): void
    {
        $this->cliente = request()->query('cliente');

        abort_unless($this->cliente && Customer::whereKey($this->cliente)->exists(), 404);

        $scelti = array_values(array_filter((array) request()->query('rapportini', [])));
        $daFirmare = $this->daFirmare();

        // Senza una scelta esplicita: quelli di oggi, cioe' la visita in corso.
        $preselezionati = $scelti !== []
            ? $daFirmare->whereIn('id', $scelti)
            : $daFirmare->filter(fn (ServiceReport $r) => $r->intervention_date?->isToday());

        $this->form->fill([
            'rapportini' => $preselezionati->pluck('id')->values()->all(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Firma rapportini';
    }

    public function getSubheading(): ?string
    {
        return DisplayName::customerOption($this->customer());
    }

    public function customer(): ?Customer
    {
        return Customer::find($this->cliente);
    }

    /**
     * I rapportini di questo cliente ancora senza firma che l'utente puo'
     * modificare (non quelli gia' su Eureka, firmati su carta a suo tempo).
     *
     * @return Collection<int, ServiceReport>
     */
    public function daFirmare(): Collection
    {
        return ServiceReport::query()
            ->with(['machineUnit', 'technician', 'materialsUsed.material'])
            ->where('customer_id', $this->cliente)
            ->whereNull('customer_signature_path')
            ->where('status', '!=', 'rifiutato')
            ->where('intervention_date', '>=', now()->subDays(30)->startOfDay())
            ->orderByDesc('intervention_date')
            ->orderBy('number')
            ->get()
            // isLocked() anche esplicito: il super admin scavalca la policy,
            // ma un rapportino gia' su Eureka non si firma piu' da qui.
            ->filter(fn (ServiceReport $r) => ! $r->isLocked() && auth()->user()?->can('update', $r))
            ->values();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\CheckboxList::make('rapportini')
                    ->label('Rapportini da firmare')
                    ->options(fn () => $this->daFirmare()->mapWithKeys(fn (ServiceReport $r) => [$r->id => $r->number]))
                    ->descriptions(fn () => $this->daFirmare()->mapWithKeys(fn (ServiceReport $r) => [$r->id => static::riassunto($r)]))
                    ->required()
                    ->validationMessages(['required' => 'Scegli almeno un rapportino.'])
                    ->bulkToggleable()
                    ->columnSpanFull(),
                Forms\Components\Section::make('Firma del cliente')
                    ->description('Una sola firma per tutti i rapportini scelti: viene riportata su ciascuno.')
                    ->schema([
                        Forms\Components\TextInput::make('customer_signature_name')
                            ->label('Nome e cognome (stampatello)')
                            ->required()
                            ->maxLength(255),
                        SignaturePad::make('customer_signature_path')
                            ->label('Firma')
                            ->required(),
                    ]),
            ]);
    }

    /**
     * "18/09 · Faema E71 SN-001 · Manutenzione ordinaria — cambio guarnizioni
     * (3 ricambi)": quanto basta al cliente per riconoscere cosa firma.
     */
    public static function riassunto(ServiceReport $r): string
    {
        $tipo = ServiceReportResource::interventionTypeLabels()[$r->intervention_type] ?? null;
        $lavoro = trim((string) ($r->work_performed ?: $r->problem_description));
        $ricambi = $r->materialsUsed->count();

        return collect([
            $r->intervention_date?->format('d/m/Y'),
            $r->machineUnit ? trim($r->machineUnit->display_name.' '.$r->machineUnit->serial_number) : ($r->machine_serial_number ?: null),
            $tipo,
            $lavoro !== '' ? mb_strimwidth($lavoro, 0, 90, '…') : null,
            $ricambi ? $ricambi.($ricambi === 1 ? ' voce' : ' voci').' tra ricambi e manodopera' : null,
        ])->filter()->implode(' · ');
    }

    public function firma(): void
    {
        $data = $this->form->getState();

        $rapportini = $this->daFirmare()->whereIn('id', $data['rapportini'] ?? []);

        if ($rapportini->isEmpty()) {
            Notification::make()->title('Nessun rapportino da firmare')->warning()->send();

            return;
        }

        DB::transaction(function () use ($rapportini, $data) {
            foreach ($rapportini as $rapportino) {
                $rapportino->update([
                    'customer_signature_name' => $data['customer_signature_name'],
                    // Lo stesso file per tutti: e' la stessa firma, data
                    // una volta sola davanti al tecnico.
                    'customer_signature_path' => $data['customer_signature_path'],
                    'signed_at' => now(),
                ]);
            }
        });

        $n = $rapportini->count();

        Notification::make()
            ->title($n === 1 ? "Rapportino {$rapportini->first()->number} firmato" : "{$n} rapportini firmati")
            ->body($rapportini->pluck('number')->implode(', '))
            ->success()
            ->send();

        $this->redirect($n === 1
            ? ServiceReportResource::getUrl('view', ['record' => $rapportini->first()])
            : ServiceReportResource::getUrl('index'));
    }

    public static function urlPer(ServiceReport|Collection $rapportini): string
    {
        $rapportini = $rapportini instanceof ServiceReport ? collect([$rapportini]) : $rapportini;

        return static::getUrl([
            'cliente' => $rapportini->first()?->customer_id,
            'rapportini' => $rapportini->pluck('id')->all(),
        ]);
    }
}
