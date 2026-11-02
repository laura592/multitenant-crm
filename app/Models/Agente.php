<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chi segue il cliente: l'agente di zona.
 *
 * Un'anagrafica e non un campo di testo sul cliente, perche' quello che
 * serve non e' scrivere un nome ma raggruppare -- "tutti i clienti di
 * Guirotto" -- e con il testo libero lo stesso agente diventa tre agenti
 * diversi al primo errore di battitura.
 */
class Agente extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    protected $table = 'agenti';

    protected $fillable = ['tenant_id', 'nome', 'zona', 'email', 'telefono', 'attivo', 'note'];

    protected $casts = ['attivo' => 'boolean'];

    protected $attributes = ['attivo' => true];

    public function clienti(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Come si legge in un elenco: "Modolo (Jesolo)". */
    public function etichetta(): string
    {
        return $this->zona ? "{$this->nome} ({$this->zona})" : $this->nome;
    }
}
