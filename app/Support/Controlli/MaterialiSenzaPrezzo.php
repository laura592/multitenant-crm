<?php

namespace App\Support\Controlli;

use App\Models\Material;
use Illuminate\Database\Eloquent\Builder;

/**
 * I materiali senza prezzo di listino che pero' finiscono su un documento
 * che si fattura.
 *
 * Il listino del materiale non e' una scheda informativa: e' il prezzo che
 * la riga si porta dietro. ServiceReportMaterial lo fotografa quando la
 * riga nasce, e se il listino e' zero lascia l'importo vuoto — la copia con
 * i prezzi stampa "—" e il totale del rapportino non torna. Sulle forniture
 * di un noleggio e' peggio: il prezzo unitario lo detta l'articolo, quindi
 * un listino a zero diventa un canone che non fattura niente.
 *
 * IL PUNTO DELICATO: a zero ci sono 2439 materiali su 3600 (27/11/2026).
 * Quasi tutti arrivano dallo sweep del catalogo Eureka e nessuno li ha mai
 * messi su un rapportino: il prezzo manca perche' non serve. Elencarli
 * tutti non e' un controllo, e' uno sfondo — e seppellisce i 124 che
 * servono davvero.
 *
 * Quindi si guarda solo chi e' gia' stato usato dove si fattura: una riga
 * di rapportino o una fornitura di noleggio. Li' il prezzo mancante non e'
 * un campo vuoto, e' soldi che non si chiedono.
 *
 * Il modello di macchina e l'ordine al fornitore restano fuori di proposito:
 * nessuno dei due valorizza niente, e ci porterebbero dentro altri 200
 * materiali su cui non c'e' nulla da correggere.
 */
class MaterialiSenzaPrezzo
{
    /**
     * La condizione, su una query qualsiasi.
     *
     * Esiste separata da query() perche' la stessa condizione serve a
     * contare sulla scheda dei controlli e a filtrare la tabella dei
     * materiali: se fossero due query scritte due volte, il giorno in cui
     * una cambia il numero annunciato e le righe che si aprono smettono di
     * combaciare — ed e' peggio di non avere il filtro.
     *
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            // NULL e 0.00 sono lo stesso problema: il listino non c'e'. In
            // tabella il primo si vede come "—" e il secondo come "€ 0,00",
            // ma per la riga del rapportino sono identici (il prezzo si
            // copia solo se > 0), e vanno sistemati insieme.
            ->where(fn (Builder $q) => $q
                ->whereNull('list_price')
                ->orWhere('list_price', '<=', 0))
            ->where(fn (Builder $q) => $q
                ->whereExists(fn ($s) => $s
                    ->selectRaw('1')
                    ->from('service_report_materials')
                    // Il rapportino cancellato non fattura piu' niente: se
                    // il materiale compare solo li', non c'e' nulla da
                    // correggere.
                    ->join('service_reports', 'service_reports.id', '=', 'service_report_materials.service_report_id')
                    ->whereColumn('service_report_materials.material_id', 'materials.id')
                    ->whereNull('service_report_materials.deleted_at')
                    ->whereNull('service_reports.deleted_at'))
                ->orWhereExists(fn ($s) => $s
                    ->selectRaw('1')
                    ->from('noleggio_forniture')
                    ->join('noleggi', 'noleggi.id', '=', 'noleggio_forniture.noleggio_id')
                    ->whereColumn('noleggio_forniture.material_id', 'materials.id')
                    ->whereNull('noleggi.deleted_at')));
    }

    /** @return Builder<Material> */
    public static function query(): Builder
    {
        return self::applica(Material::query());
    }
}
