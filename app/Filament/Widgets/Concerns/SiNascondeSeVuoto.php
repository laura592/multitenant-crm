<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Un blocco della dashboard che non ha niente da mostrare non si mostra
 * (Laura, 24/11/2026).
 *
 * Filament decide se un widget compare guardando solo canView(), che e' il
 * cancello dei permessi: con il permesso ma senza dati la tabella viene
 * disegnata comunque, con il suo emptyState dentro. Al 24/11/2026 erano due
 * riquadri su nove — "Invii Eureka falliti" e "Rapportini che non si possono
 * inviare" — che dicevano "va tutto bene" occupando lo spazio di quelli che
 * invece chiedevano qualcosa.
 *
 * ATTENZIONE, perche' e' il tranello in cui siamo gia' caduti: in Filament 3
 * NON esiste nessun canDisplay(). Era un metodo della v2.
 * RapportiniBloccatiWidget ne aveva uno, completo di commento che spiegava
 * come il widget sparisse da solo quando non c'era niente da sbloccare, e
 * non e' mai stato chiamato da nessuno — il grep in vendor/filament non lo
 * trova da nessuna parte. L'unico punto di aggancio e' canView().
 *
 * Gli emptyState dei widget restano dove sono: questo controllo decide se il
 * blocco nasce, ma una tabella si puo' svuotare dopo. Il bottone "Fatto" de
 * "Il mio giro" chiude l'ultima riga del giro senza ricaricare la pagina, e
 * in quel momento l'emptyState e' l'unica cosa che resta da far vedere.
 */
trait SiNascondeSeVuoto
{
    /**
     * Memoria per singola richiesta.
     *
     * canView() viene chiamato due volte per ogni widget: una da
     * SezioneWidget::canView(), per sapere se il titolo di sezione ha ancora
     * qualcosa sotto di se', e una da Filament quando filtra i widget della
     * pagina. La seconda volta non deve ripagare la query — e per
     * RapportiniBloccatiWidget vorrebbe dire rileggere e rivalidare in PHP
     * sei mesi di rapportini.
     *
     * Statica di trait: ogni classe che lo usa ha la propria copia, quindi i
     * widget non si mescolano fra loro.
     */
    protected static ?bool $memoHaDati = null;

    protected static function haDati(): bool
    {
        return static::$memoHaDati ??= static::contaDati() > 0;
    }

    /**
     * Quante righe avrebbe il blocco adesso. Zero vuol dire "non mostrarlo".
     */
    abstract protected static function contaDati(): int;
}
