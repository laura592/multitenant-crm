<?php

namespace App\Support\Presenze;

/**
 * Come si divide una giornata di lavoro fra ore ordinarie, ore coperte dalla
 * trasferta e straordinario.
 *
 * Unica regola per tutto il riepilogo — mensile, dettaglio giornaliero e i
 * due Excel per il commercialista leggono da qui — perche' prima lo stesso
 * conto era scritto due volte in RiepilogoOre, e una regola nuova messa in un
 * posto solo avrebbe dato due numeri diversi per lo stesso giorno.
 *
 * Nel giorno di trasferta le ore comprese nell'indennita'
 * (config presenze.trasferta_ore_incluse, oggi 1) non sono ne' ordinarie ne'
 * straordinario: sono "coperte". Tenerle fuori dalle ordinarie conta, perche'
 * lo straordinario settimanale si calcola sulle ordinarie della settimana, e
 * altrimenti l'ora pagata dalla trasferta rientrerebbe da li'.
 */
final class GiornataLavorativa
{
    private function __construct(
        public readonly float $ordinarie,
        public readonly float $coperteDaTrasferta,
        public readonly float $straordinario,
    ) {}

    public static function ripartisci(float $lavorate, float $contratto, bool $trasferta): self
    {
        $lavorate = max(0.0, $lavorate);
        $contratto = max(0.0, $contratto);

        // Sotto il contratto non cambia niente, trasferta o no: le ore che
        // mancano restano mancanti come in qualunque altro giorno.
        $ordinarie = min($lavorate, $contratto);
        $oltre = $lavorate - $ordinarie;

        $incluse = $trasferta ? (float) config('presenze.trasferta_ore_incluse', 1) : 0.0;
        $coperte = min($oltre, $incluse);

        return new self(
            ordinarie: $ordinarie,
            coperteDaTrasferta: $coperte,
            straordinario: $oltre - $coperte,
        );
    }
}
