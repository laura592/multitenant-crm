<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Il titolo della pagina "nuovo record".
 *
 * La traduzione italiana di Filament dice 'Nuovo :label', che va bene solo
 * se il nome della risorsa e' maschile: sulle nove risorse dal nome
 * femminile usciva "Nuovo offerta caffe'", "Nuovo scadenza", "Nuovo azienda
 * partner". La sovrascrittura in lang/vendor usa "Crea :label", che regge
 * qualunque genere.
 *
 * Il secondo test e' il vero motivo per cui questo file esiste: le
 * traduzioni dei pacchetti si sovrascrivono per FILE INTERO, non per
 * chiave. Una copia incompleta non da' errore — fa solo sparire le
 * etichette rimaste fuori, e uno si ritrova i pulsanti del form con scritto
 * "filament-panels::resources/pages/create-record.form.actions.create.label".
 */
class TitoliRisorseTest extends TestCase
{
    public function test_il_titolo_regge_i_nomi_femminili(): void
    {
        foreach (['offerta caffè', 'scadenza', 'richiesta informazioni', 'azienda partner', 'preventivo', 'rapportino'] as $nome) {
            $this->assertSame(
                "Crea {$nome}",
                __('filament-panels::resources/pages/create-record.title', ['label' => $nome]),
            );
        }
    }

    public function test_la_sovrascrittura_non_ha_perso_per_strada_le_altre_etichette(): void
    {
        $chiavi = [
            'filament-panels::resources/pages/create-record.breadcrumb' => 'Nuovo',
            'filament-panels::resources/pages/create-record.form.actions.cancel.label' => 'Annulla',
            'filament-panels::resources/pages/create-record.form.actions.create.label' => 'Salva',
            'filament-panels::resources/pages/create-record.form.actions.create_another.label' => 'Salva & nuovo',
            'filament-panels::resources/pages/create-record.notifications.created.title' => 'Salvato',
        ];

        foreach ($chiavi as $chiave => $atteso) {
            $this->assertSame($atteso, __($chiave), "La chiave {$chiave} non c'e' piu' nel file sovrascritto.");
        }
    }
}
