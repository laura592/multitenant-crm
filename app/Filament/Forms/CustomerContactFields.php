<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

/**
 * Schema condiviso email/telefoni multipli (+ PEC opzionale): un cliente puo'
 * avere piu' recapiti (es. amministrazione e referente tecnico), il primo
 * elemento di ogni repeater e' considerato quello "principale" (usato come
 * default nei form di invio email, vedi Customer::primaryEmail()).
 *
 * Usato in CustomerResource e ovunque si crei un cliente al volo
 * (createOptionForm in QuoteResource, InformationRequestResource,
 * ServiceReportResource) cosi' i vari punti di inserimento restano coerenti.
 */
class CustomerContactFields
{
    /**
     * @param bool $withPec se true, include il campo PEC (solo nel form
     *   completo di CustomerResource, non nei createOptionForm compatti).
     */
    public static function schema(bool $withPec = false): array
    {
        $fields = [
            Repeater::make('emails')
                ->label('Email')
                ->simple(
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        // ->email() da solo valida solo la sintassi RFC: cose
                        // come "nome@gmail" (senza .com) o "info@ite" passano
                        // comunque, perche' un dominio a singola parola e'
                        // sintatticamente valido anche se non recapitabile.
                        // ':dns' controlla che il dominio abbia record
                        // DNS/MX validi, cosi' questi refusi vengono
                        // bloccati in inserimento invece di scoprirli solo
                        // quando l'invio fallisce.
                        ->rule('email:rfc,dns')
                        ->required()
                        ->maxLength(255)
                )
                ->defaultItems(0)
                ->addActionLabel('Aggiungi email')
                ->reorderable(false),
            Repeater::make('phones')
                ->label('Telefoni')
                ->simple(TextInput::make('phone')->label('Telefono')->tel()->required()->maxLength(255))
                ->defaultItems(0)
                ->addActionLabel('Aggiungi telefono')
                ->reorderable(false),
        ];

        if ($withPec) {
            $fields[] = TextInput::make('pec')->label('PEC')->email()->rule('email:rfc,dns')->maxLength(255);
        }

        return $fields;
    }
}
