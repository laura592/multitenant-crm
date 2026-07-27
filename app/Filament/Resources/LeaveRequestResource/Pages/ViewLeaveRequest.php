<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLeaveRequest extends ViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Mirror delle azioni approve/reject della tabella (vedi
            // LeaveRequestResource::table()): qui servono perche' il link
            // "Apri la richiesta" nella mail di notifica porta a questa
            // pagina invece che direttamente alla modifica.
            Actions\Action::make('approve')
                ->label('Approva')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (LeaveRequest $record) => $record->status !== 'approvato' && auth()->user()?->can('approve', $record))
                ->requiresConfirmation()
                ->action(function (LeaveRequest $record) {
                    $record->approve(auth()->user());
                    Notification::make()->title('Richiesta approvata')->success()->send();
                    Notification::make()
                        ->title('Richiesta ferie/permesso approvata')
                        ->body(LeaveRequestResource::decisionNotificationBody($record))
                        ->success()
                        ->sendToDatabase($record->user);

                    if ($record->user?->email) {
                        LeaveRequestResource::sendDecisionMail($record);
                    }
                }),
            Actions\Action::make('reject')
                ->label('Rifiuta')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (LeaveRequest $record) => $record->status !== 'rifiutato' && auth()->user()?->can('approve', $record))
                ->requiresConfirmation()
                ->action(function (LeaveRequest $record) {
                    $record->reject(auth()->user());
                    Notification::make()->title('Richiesta rifiutata')->danger()->send();
                    Notification::make()
                        ->title('Richiesta ferie/permesso rifiutata')
                        ->body(LeaveRequestResource::decisionNotificationBody($record))
                        ->danger()
                        ->sendToDatabase($record->user);

                    if ($record->user?->email) {
                        LeaveRequestResource::sendDecisionMail($record);
                    }
                }),
        ];
    }
}
