<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cio' che il cliente ha fatto dalla pagina del link nella mail del
 * preventivo: vedi App\Http\Controllers\QuoteClientController.
 */
class QuoteResponse extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_ACCEPTED = 'accettato';

    public const TYPE_REJECTED = 'rifiutato';

    public const TYPE_QUESTION = 'domanda';

    public const TYPE_CALLBACK = 'richiamata';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'quote_group_id',
        'type',
        'signer_name',
        'signer_role',
        'email',
        'phone',
        'preferred_time',
        'reason',
        'message',
        'signature_path',
        'accepted_pdf_path',
        'accepted_pdf_sha256',
        'ip_address',
        'user_agent',
        'handled_at',
        'handled_by',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public static function typeLabels(): array
    {
        return [
            self::TYPE_ACCEPTED => 'Confermato e firmato',
            self::TYPE_REJECTED => 'Per ora no',
            self::TYPE_QUESTION => 'Domanda',
            self::TYPE_CALLBACK => 'Chiede di essere richiamato',
        ];
    }

    public static function typeColors(): array
    {
        return [
            self::TYPE_ACCEPTED => 'success',
            self::TYPE_REJECTED => 'danger',
            self::TYPE_QUESTION => 'info',
            self::TYPE_CALLBACK => 'warning',
        ];
    }

    public static function reasonLabels(): array
    {
        return [
            'prezzo' => 'Il prezzo',
            'altro_fornitore' => 'Abbiamo scelto un altro fornitore',
            'tempistiche' => 'Non è il momento giusto',
            'non_serve' => 'Non ci serve più',
            'altro' => 'Altro',
        ];
    }

    public static function preferredTimeLabels(): array
    {
        return [
            'mattina' => 'In mattinata',
            'pomeriggio' => 'Nel pomeriggio',
            'indifferente' => 'Quando preferite',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quoteGroup(): BelongsTo
    {
        return $this->belongsTo(QuoteGroup::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Domande e richieste di richiamata aspettano qualcuno dell'ufficio;
     * accettazioni e rifiuti chiudono gia' da soli il preventivo.
     */
    public function needsFollowUp(): bool
    {
        return in_array($this->type, [self::TYPE_QUESTION, self::TYPE_CALLBACK], true)
            && $this->handled_at === null;
    }
}
