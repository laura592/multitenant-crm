<?php

namespace App\Filament\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Destinatari fissi avvisati via mail per eventi non legati a un
 * utente/ruolo specifico di QUESTO tenant: ogni evento ha la sua lista
 * dedicata (richieste informazioni, ferie/permessi, preventivi, offerte).
 */
class NotificationSettings extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Impostazioni';

    protected static ?string $navigationLabel = 'Notifiche';

    protected static ?string $title = 'Notifiche';

    protected static string $view = 'filament.pages.notification-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $tenant = Filament::getTenant();

        $this->form->fill([
            'notify_information_request_emails' => $tenant?->notificationRecipients('information_request') ?? [],
            'notify_leave_request_emails' => $tenant?->notificationRecipients('leave_request') ?? [],
            'notify_quote_emails' => $tenant?->notificationRecipients('quote') ?? [],
            'notify_quote_group_emails' => $tenant?->notificationRecipients('quote_group') ?? [],
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Imposta destinatari diversi per ogni tipologia di email, per questa azienda.';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TagsInput::make('notify_information_request_emails')
                    ->label('Richieste informazioni')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('Chi riceve le richieste che arrivano dal sito o da inserimento manuale.'),
                TagsInput::make('notify_leave_request_emails')
                    ->label('Ferie e permessi')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('In copia alle comunicazioni di approvazione/rifiuto.'),
                TagsInput::make('notify_quote_emails')
                    ->label('Preventivi')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('In copia all invio dei preventivi ai clienti.'),
                TagsInput::make('notify_quote_group_emails')
                    ->label('Offerte globali')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('In copia all invio delle offerte con piu soluzioni.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $tenant = Filament::getTenant();
        $state = $this->form->getState();

        $informationRecipients = array_values(array_unique(array_filter((array) ($state['notify_information_request_emails'] ?? []))));
        $leaveRecipients = array_values(array_unique(array_filter((array) ($state['notify_leave_request_emails'] ?? []))));
        $quoteRecipients = array_values(array_unique(array_filter((array) ($state['notify_quote_emails'] ?? []))));
        $quoteGroupRecipients = array_values(array_unique(array_filter((array) ($state['notify_quote_group_emails'] ?? []))));

        $tenant?->update([
            'notify_information_request_emails' => $informationRecipients,
            'notify_leave_request_emails' => $leaveRecipients,
            'notify_quote_emails' => $quoteRecipients,
            'notify_quote_group_emails' => $quoteGroupRecipients,
            // Manteniamo valorizzata la lista legacy finche' esiste codice
            // esterno che potrebbe ancora leggerla direttamente.
            'notify_staff_emails' => array_values(array_unique(array_filter(array_merge(
                $informationRecipients,
                $leaveRecipients,
                $quoteRecipients,
                $quoteGroupRecipients,
            )))),
        ]);

        Notification::make()
            ->title('Destinatari aggiornati')
            ->success()
            ->send();
    }
}
