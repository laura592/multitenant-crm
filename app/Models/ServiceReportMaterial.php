<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceReportMaterial extends Model
{
    use HasUuids, LogsAuditTrail, SoftDeletes;

    protected $fillable = [
        'service_report_id',
        'material_id',
        'quantity',
        'unit_cost_snapshot',
        'line_total_snapshot',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost_snapshot' => 'decimal:2',
        'line_total_snapshot' => 'decimal:2',
    ];

    /**
     * Il prezzo si fotografa qui, alla scrittura della riga.
     *
     * Le righe nate nel CRM arrivavano senza prezzo: le scorciatoie
     * ("Chiamata", "Manodopera", "Lavaggio eseguito"...) creano la riga con
     * material_id e quantita' e basta, e nessuno riempiva unit_cost_snapshot.
     * Risultato: RT-2026-0770 con CHIORD e ORE a listino 46,20 e 42,00 ma
     * importo vuoto — la copia con i prezzi mostrava "—" e il totale non
     * tornava. L'import da Eureka faceva gia' la cosa giusta (vedi
     * ImportEurekaServiceReports), il form no.
     *
     * Sta nel modello e non nel form perche' le strade che creano una riga
     * sono molte — scorciatoie, repeater a mano, unione di un doppione — e
     * la regola e' una sola.
     *
     * Non sovrascrive mai un prezzo gia' presente: quello arrivato da Eureka
     * e' il dato buono, e il listino nel frattempo puo' essere cambiato. Un
     * prezzo corretto a mano resta quindi com'e'.
     *
     * Due eccezioni, dove il prezzo di prima non descrive piu' la riga:
     * cambiando la QUANTITA' si rifa' l'importo (correggendo "2" in "3"
     * restava l'importo di due) e cambiando l'ARTICOLO si rifa' tutto, prezzo
     * compreso — quello vecchio era di un altro materiale.
     */
    protected static function booted(): void
    {
        static::saving(function (self $riga): void {
            // Cambiato l'articolo, il prezzo di prima non vuol piu' dire
            // niente: era di un altro materiale. Succedeva correggendo una
            // riga nel repeater — RT-2026-0647 aveva DC3 (102,80) al prezzo
            // di F3 (77,16), l'articolo con cui la riga era nata.
            //
            // Si rilegge dal database e non da $riga->material: la relazione
            // in memoria e' ancora quella del materiale vecchio.
            if ($riga->exists && $riga->isDirty('material_id')) {
                $listino = (float) (Material::whereKey($riga->material_id)->value('list_price') ?? 0);

                $riga->unit_cost_snapshot = $listino > 0 ? $listino : null;
                $riga->line_total_snapshot = null;
            }

            if ($riga->unit_cost_snapshot === null) {
                $listino = (float) (Material::whereKey($riga->material_id)->value('list_price') ?? 0);

                if ($listino > 0) {
                    $riga->unit_cost_snapshot = $listino;
                }
            }

            $daRifare = $riga->line_total_snapshot === null
                || ($riga->exists && ($riga->isDirty('quantity') || $riga->isDirty('unit_cost_snapshot')));

            if ($daRifare && $riga->unit_cost_snapshot !== null && $riga->quantity !== null) {
                $riga->line_total_snapshot = round((float) $riga->unit_cost_snapshot * (float) $riga->quantity, 2);
            }
        });
    }

    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
