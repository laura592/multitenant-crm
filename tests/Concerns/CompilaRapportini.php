<?php

namespace Tests\Concerns;

use App\Filament\Resources\ServiceReportResource\Pages\RapportiniAPassi;
use App\Models\ServiceReport;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Compilare il rapportino a passi come si compilava il vecchio modulo con
 * fillForm(): i campi della visita (cliente, tecnico, data, stato, firma)
 * nel primo passo, tutti gli altri nel passo della macchina. La macchina e'
 * machine_unit_id, o "Senza una macchina precisa" se non c'e'.
 */
trait CompilaRapportini
{
    /** @var array<int, string> */
    private array $campiDellaVisita = ['customer_id', 'technician_id', 'intervention_date', 'status', 'customer_signature_name', 'customer_signature_path'];

    protected function nuovoRapportino(array $dati = [], array $query = []): Testable
    {
        return $this->compilaRapportino(Livewire::withQueryParams($query)->test(RapportiniAPassi::class), $dati);
    }

    protected function modificaRapportino(ServiceReport $rapportino, array $dati = []): Testable
    {
        return $this->compilaRapportino(Livewire::test(RapportiniAPassi::class, ['record' => $rapportino->getKey()]), $dati, $rapportino->getKey());
    }

    protected function compilaRapportino(Testable $pagina, array $dati, ?string $chiave = null): Testable
    {
        foreach ($this->campiDellaVisita as $campo) {
            if (! array_key_exists($campo, $dati)) {
                continue;
            }

            $valore = $dati[$campo] instanceof DateTimeInterface ? $dati[$campo]->format('Y-m-d') : $dati[$campo];

            // Rimettere lo stesso cliente azzererebbe le macchine scelte.
            if ($campo === 'customer_id' && $pagina->get('data.customer_id') === $valore) {
                continue;
            }

            $pagina->set("data.{$campo}", $valore);
        }

        if ($chiave === null) {
            $scelte = $pagina->get('data.macchine') ?? [];
            $chiave = $dati['machine_unit_id'] ?? ($scelte[0] ?? RapportiniAPassi::GENERALE);

            if (! in_array($chiave, $scelte, true)) {
                $pagina->set('data.macchine', [...$scelte, $chiave]);
            }
        }

        // Campo per campo, anche dentro le righe, come fillForm(): cosi'
        // scattano le regole di ciascun campo (scegliere l'impianto scrive
        // le vie, e le vie accendono il lavaggio).
        $delPasso = array_diff_key($dati, array_flip([...$this->campiDellaVisita, 'machine_unit_id']));

        foreach (Arr::dot($delPasso) as $campo => $valore) {
            $pagina->set("data.lavori.{$chiave}.{$campo}", $valore);
        }

        return $pagina;
    }

    /**
     * Lo stato del primo (o unico) passo macchina.
     *
     * @return array<string, mixed>
     */
    protected function statoPasso(Testable $pagina): array
    {
        return $pagina->get($this->passo($pagina)) ?? [];
    }

    /**
     * Il percorso dello stato del passo: "data.lavori.{chiave}" del primo (o
     * unico) passo macchina.
     */
    protected function passo(Testable $pagina): string
    {
        return 'data.lavori.'.array_key_first($pagina->get('data.lavori') ?? []);
    }
}
