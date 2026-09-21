<?php

namespace App\Filament\Actions;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Closure;
use Filament\Actions\Action as PageAction;
use Filament\Actions\ActionGroup as PageActionGroup;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * Il pulsante "Contatta": chiama, WhatsApp, email del cliente a un clic,
 * uguale nell'elenco clienti, nelle richieste informazioni e nei
 * preventivi (richiesta dell'ufficio, 21/09/2026: i dati per contattare
 * il cliente devono stare a portata di mano, non dentro l'anagrafica).
 *
 * Le voci sono fisse (primo e secondo telefono, WhatsApp sul primo
 * cellulare, prima e seconda email) e si nascondono quando il dato manca:
 * nelle tabelle le azioni si dichiarano una volta per tutte le righe.
 */
class ContattaCliente
{
    /**
     * Nelle tabelle le voci stanno in cima al menu "⋮" della riga, come
     * sezione a parte: un secondo pulsante col telefono accanto alla
     * colonna Telefono sembrava un doppione (21/09/2026).
     *
     * @param  Closure(?Model): ?Customer  $cliente
     */
    public static function perTabella(Closure $cliente): TableActionGroup
    {
        return TableActionGroup::make(self::voci(TableAction::class, $cliente))
            ->dropdown(false);
    }

    /** Nelle pagine di un record il cliente e' gia' noto: niente closure. */
    public static function perPagina(?Customer $cliente): PageActionGroup
    {
        return PageActionGroup::make(self::voci(PageAction::class, fn () => $cliente))
            ->label('Contatta')
            ->icon('heroicon-o-phone')
            ->color('success')
            ->button()
            ->visible(self::haContatti($cliente));
    }

    /**
     * @param  class-string<PageAction|TableAction>  $classe
     * @param  Closure(?Model): ?Customer  $cliente
     * @return array<int, PageAction|TableAction>
     */
    private static function voci(string $classe, Closure $cliente): array
    {
        $telefono = fn (?Model $record, int $i) => $cliente($record)?->phones[$i] ?? null;
        $email = fn (?Model $record, int $i) => $cliente($record)?->emails[$i] ?? null;
        $cellulare = fn (?Model $record) => collect($cliente($record)?->phones ?? [])
            ->map(fn ($n) => PhoneNumber::whatsapp($n))->filter()->first();

        $voci = [];

        foreach ([0, 1] as $i) {
            $voci[] = $classe::make("chiama_{$i}")
                ->label(fn (?Model $record = null) => 'Chiama '.PhoneNumber::display($telefono($record, $i)))
                ->icon('heroicon-o-phone')
                ->url(fn (?Model $record = null) => 'tel:'.$telefono($record, $i))
                ->visible(fn (?Model $record = null) => filled($telefono($record, $i)));
        }

        $voci[] = $classe::make('whatsapp')
            ->label('WhatsApp')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->url(fn (?Model $record = null) => 'https://wa.me/'.$cellulare($record), shouldOpenInNewTab: true)
            ->visible(fn (?Model $record = null) => filled($cellulare($record)));

        foreach ([0, 1] as $i) {
            $voci[] = $classe::make("email_{$i}")
                ->label(fn (?Model $record = null) => 'Scrivi a '.$email($record, $i))
                ->icon('heroicon-o-envelope')
                ->url(fn (?Model $record = null) => 'mailto:'.$email($record, $i))
                ->visible(fn (?Model $record = null) => filled($email($record, $i)));
        }

        return $voci;
    }

    private static function haContatti(?Customer $cliente): bool
    {
        return filled($cliente?->phones) || filled($cliente?->emails);
    }
}
