<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\PdfCompressor;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un documento della sezione "Documenti" (fino al 21/09/2026 "Listini").
 *
 * Il nome del modello e della tabella e' rimasto quello dei listini: i
 * permessi dei ruoli si chiamano price::list e rinominarli avrebbe voluto
 * dire toccare i ruoli in produzione. La categoria dice cos'e' davvero.
 *
 * I contratti di assistenza sono i modelli dell'ufficio che
 * ContrattoAssistenzaPdf mette in coda alla pagina dei dati: vale quello in
 * vigore, vedi contrattoInVigore().
 */
class PriceList extends Model
{
    use BelongsToTenant, HasUuids, SharedAcrossTenants;

    public const CARTELLA = 'price-lists';

    public const LISTINO = 'listino';

    /** Cataloghi dei fornitori senza prezzi (John Guest, Global Fountain). */
    public const CATALOGO = 'catalogo';

    public const CONTRATTO_FULL = 'contratto_full';

    public const CONTRATTO_EASY = 'contratto_easy';

    public const ALTRO = 'altro';

    /** @var array<string, string> */
    public const CATEGORIE = [
        self::LISTINO => 'Listino',
        self::CATALOGO => 'Catalogo',
        self::CONTRATTO_FULL => 'Contratto Full-Service',
        self::CONTRATTO_EASY => 'Contratto Easy-Service',
        self::ALTRO => 'Altro',
    ];

    /** Categoria del documento per ciascun tipo di ContrattoAssistenza::TIPI. */
    public const CONTRATTI = [
        ContrattoAssistenza::FULL => self::CONTRATTO_FULL,
        ContrattoAssistenza::EASY => self::CONTRATTO_EASY,
    ];

    /**
     * La sottocartella di CARTELLA per ogni categoria: sul disco i file
     * stanno divisi come nel pannello. I due contratti insieme, il nome del
     * file dice quale.
     *
     * @var array<string, string>
     */
    public const SOTTOCARTELLE = [
        self::LISTINO => 'listini',
        self::CATALOGO => 'cataloghi',
        self::CONTRATTO_FULL => 'contratti',
        self::CONTRATTO_EASY => 'contratti',
        self::ALTRO => 'altro',
    ];

    /**
     * I PDF caricati dall'utente arrivano spesso a piena risoluzione di
     * scansione: dopo ogni upload proviamo a ricomprimerli con Ghostscript
     * (PdfCompressor), senza bloccare il salvataggio se non e' disponibile.
     *
     * I contratti no: sono testo, gia' leggeri, e un documento da firmare
     * resta com'e' uscito dall'ufficio.
     */
    protected static function booted(): void
    {
        // Cambiata la categoria, il file segue nella sua cartella. Niente
        // arrow function: sistemaFile() torna false quando non sposta nulla,
        // e un false dal saving annulla il salvataggio.
        static::saving(function (PriceList $priceList): void {
            $priceList->sistemaFile();
        });

        static::saved(function (PriceList $priceList) {
            if ($priceList->isContratto()) {
                return;
            }

            if ($priceList->wasChanged('file_path') && $priceList->file_path) {
                PdfCompressor::compressInPlace(Storage::disk('public')->path($priceList->file_path));
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'category',
        'name',
        'valid_from',
        'valid_to',
        'file_path',
        'notes',
    ];

    protected $attributes = [
        'category' => self::LISTINO,
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** La cartella dei file di una categoria, es. price-lists/contratti. */
    public static function cartella(?string $categoria): string
    {
        return self::CARTELLA.'/'.(self::SOTTOCARTELLE[$categoria] ?? self::SOTTOCARTELLE[self::ALTRO]);
    }

    /**
     * Un nome di file leggibile dal nome del documento ("Liebherr listino
     * 2026" -> liebherr-listino-2026.pdf), libero nella cartella: se c'e'
     * gia' diventa -2, -3... Prima del 21/09/2026 Filament salvava ogni
     * upload con un codice a caso (01KYJ1NWCP....pdf), illeggibile quando
     * il file si scarica o si cerca sul disco.
     */
    public static function nomeFileLibero(string $base, string $cartella): string
    {
        $radice = Str::slug($base) ?: 'documento';
        $nome = "{$radice}.pdf";

        for ($n = 2; Storage::disk('public')->exists("{$cartella}/{$nome}"); $n++) {
            $nome = "{$radice}-{$n}.pdf";
        }

        return $nome;
    }

    /** Il nome che Filament dava da solo prima del 21/09/2026: un ULID. */
    public static function haNomeACaso(string $percorso): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.pdf$/i', basename($percorso));
    }

    /**
     * Dove dovrebbe stare il file: nella cartella della sua categoria e, se
     * ha ancora il nome a caso, col nome del documento. Null se e' gia' a
     * posto (o se il file non c'e').
     */
    public function percorsoGiusto(): ?string
    {
        if (blank($this->file_path) || ! Storage::disk('public')->exists($this->file_path)) {
            return null;
        }

        $cartella = self::cartella($this->category);
        $nomeACaso = self::haNomeACaso($this->file_path);

        if (dirname($this->file_path) === $cartella && ! $nomeACaso) {
            return null;
        }

        $nome = $nomeACaso ? (string) $this->name : pathinfo($this->file_path, PATHINFO_FILENAME);

        return $cartella.'/'.self::nomeFileLibero($nome, $cartella);
    }

    /**
     * Sposta il file dove dice percorsoGiusto(). Cambia solo file_path: a
     * salvare ci pensa chi chiama (il saving qui sopra, o il comando
     * documenti:categorizza). True se ha spostato qualcosa.
     */
    public function sistemaFile(): bool
    {
        $nuovo = $this->percorsoGiusto();

        if ($nuovo === null) {
            return false;
        }

        Storage::disk('public')->move($this->file_path, $nuovo);
        $this->file_path = $nuovo;

        return true;
    }

    public function isContratto(): bool
    {
        return in_array($this->category, self::CONTRATTI, true);
    }

    /**
     * Il modello di contratto da usare oggi: fra i PDF di quella categoria
     * validi a oggi, del tenant o condivisi, quello con la decorrenza piu'
     * recente (senza "Valido dal" conta la data di caricamento). Null se
     * l'ufficio non ne ha caricato nessuno: il contratto allora non si
     * genera (ContrattoAssistenzaPdf).
     */
    public static function contrattoInVigore(string $tipo, ?string $tenantId): ?self
    {
        $categoria = self::CONTRATTI[$tipo] ?? null;

        if ($categoria === null) {
            return null;
        }

        $oggi = now()->toDateString();

        return static::query()
            ->withoutGlobalScopes()
            ->where('category', $categoria)
            ->whereNotNull('file_path')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $oggi))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $oggi))
            ->orderByRaw('COALESCE(valid_from, DATE(created_at)) DESC')
            ->latest('created_at')
            ->first();
    }

    /**
     * Nessuna data = validita' aperta (listino tuttora in vigore, senza una
     * scadenza nota). Usato per il badge di stato in tabella.
     *
     * Per un contratto "in corso" non basta: se ce ne sono due validi se ne
     * usa uno solo, e l'altro e' "sostituito".
     */
    public function status(): string
    {
        $stato = $this->statoDaDate();

        if ($stato !== 'in_corso' || ! $this->isContratto()) {
            return $stato;
        }

        $tipo = array_search($this->category, self::CONTRATTI, true);

        return self::contrattoInVigore($tipo, Filament::getTenant()?->id ?? $this->tenant_id)?->is($this) ? 'in_uso' : 'sostituito';
    }

    private function statoDaDate(): string
    {
        $today = now()->startOfDay();

        if ($this->valid_from && $today->lt($this->valid_from)) {
            return 'futuro';
        }

        if ($this->valid_to && $today->gt($this->valid_to)) {
            return 'scaduto';
        }

        return 'in_corso';
    }
}
