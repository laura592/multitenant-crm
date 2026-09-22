<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un giro di un lavoro con Eureka: vedi Support\Gestionale\DiarioEsecuzioni.
 */
class EsecuzioneEureka extends Model
{
    public const IN_CORSO = 'in_corso';

    public const OK = 'ok';

    public const ERRORE = 'errore';

    protected $table = 'esecuzioni_eureka';

    protected $fillable = ['comando', 'avviata_il', 'finita_il', 'esito', 'riepilogo', 'errore'];

    protected $casts = [
        'avviata_il' => 'datetime',
        'finita_il' => 'datetime',
        'riepilogo' => 'array',
    ];
}
