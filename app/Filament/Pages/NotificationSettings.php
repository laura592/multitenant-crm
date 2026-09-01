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
            'notify_deadline_emails' => $tenant?->notificationRecipients('deadline') ?? [],
            'notify_lavaggio_emails' => $tenant?->notificationRecipients('lavaggio') ?? [],
            'notify_customer_gestionale_review_emails' => $tenant?->notificationRecipients('customer_gestionale_review') ?? [],
            'notify_gestionale_sync_digest_emails' => $tenant?->notificationRecipients('gestionale_sync_digest') ?? [],
            'notify_gestionale_sync_failed_emails' => $tenant?->notificationRecipients('gestionale_sync_failed') ?? [],
            'notify_service_report_emails' => $tenant?->notificationRecipients('service_report') ?? [],
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
                    ->helperText('Chi riceve le richieste che arrivano dal sito o da inserimento manuale.')
                    ->extraAttributes(['data-tour' => 'notification-settings-field-first']),
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
                TagsInput::make('notify_deadline_emails')
                    ->label('Scadenze')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('Promemoria settimanale con le scadenze in avvicinamento o scadute.'),
                TagsInput::make('notify_lavaggio_emails')
                    ->label('Lavaggi da programmare')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('Promemoria settimanale con i piani di lavaggio gia\' scaduti o in scadenza nei 7 giorni successivi.'),
                TagsInput::make('notify_customer_gestionale_review_emails')
                    ->label('Clienti da rivedere su Eureka')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('Preventivo accettato da un cliente nuovo, o modifica su un cliente gia\' collegato a Eureka.'),
                TagsInput::make('notify_gestionale_sync_digest_emails')
                    ->label('Digest sync Eureka')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('Riepilogo giornaliero del sync automatico: differenze trovate, campi compilati, nuovi collegamenti proposti.'),
                TagsInput::make('notify_gestionale_sync_failed_emails')
                    ->label('Sync Eureka fallito')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('danger')
                    ->helperText('Avviso quando il sync automatico giornaliero non riesce a raggiungere Eureka.'),
                TagsInput::make('notify_service_report_emails')
                    ->label('Rapportini')
                    ->placeholder('indirizzo@esempio.it')
                    ->nestedRecursiveRules(['email'])
                    ->splitKeys([',', 'Tab'])
                    ->color('primary')
                    ->helperText('In copia fissa ad ogni invio di rapportino al cliente, oltre al CC inserito manualmente.'),
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
        $deadlineRecipients = array_values(array_unique(array_filter((array) ($state['notify_deadline_emails'] ?? []))));
        $lavaggioRecipients = array_values(array_unique(array_filter((array) ($state['notify_lavaggio_emails'] ?? []))));
        $gestionaleReviewRecipients = array_values(array_unique(array_filter((array) ($state['notify_customer_gestionale_review_emails'] ?? []))));
        $gestionaleSyncDigestRecipients = array_values(array_unique(array_filter((array) ($state['notify_gestionale_sync_digest_emails'] ?? []))));
        $gestionaleSyncFailedRecipients = array_values(array_unique(array_filter((array) ($state['notify_gestionale_sync_failed_emails'] ?? []))));
        $serviceReportRecipients = array_values(array_unique(array_filter((array) ($state['notify_service_report_emails'] ?? []))));

        $tenant?->update([
            'notify_information_request_emails' => $informationRecipients,
            'notify_leave_request_emails' => $leaveRecipients,
            'notify_quote_emails' => $quoteRecipients,
            'notify_quote_group_emails' => $quoteGroupRecipients,
            'notify_deadline_emails' => $deadlineRecipients,
            'notify_lavaggio_emails' => $lavaggioRecipients,
            'notify_customer_gestionale_review_emails' => $gestionaleReviewRecipients,
            'notify_gestionale_sync_digest_emails' => $gestionaleSyncDigestRecipients,
            'notify_gestionale_sync_failed_emails' => $gestionaleSyncFailedRecipients,
            'notify_service_report_emails' => $serviceReportRecipients,
            // Manteniamo valorizzata la lista legacy finche' esiste codice
            // esterno che potrebbe ancora leggerla direttamente.
            'notify_staff_emails' => array_values(array_unique(array_filter(array_merge(
                $informationRecipients,
                $leaveRecipients,
                $quoteRecipients,
                $quoteGroupRecipients,
                $deadlineRecipients,
                $lavaggioRecipients,
                $gestionaleReviewRecipients,
                $gestionaleSyncDigestRecipients,
                $gestionaleSyncFailedRecipients,
                $serviceReportRecipients,
            )))),
        ]);

        Notification::make()
            ->title('Destinatari aggiornati')
            ->success()
            ->send();
    }
}
