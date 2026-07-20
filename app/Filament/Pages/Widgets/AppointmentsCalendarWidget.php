<?php

namespace App\Filament\Pages\Widgets;

use App\Models\Appointment;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Actions;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Calendario in-app degli appuntamenti (docs/architecture.md §15.3). Assorbe,
 * per gli appuntamenti, il ruolo del tableau descritto (mai implementato) in
 * §13.2 — le Deadline senza appuntamento restano nella lista semplice li
 * prevista.
 */
class AppointmentsCalendarWidget extends FullCalendarWidget
{
    public Model | string | null $model = Appointment::class;

    public ?string $technicianId = null;

    public function config(): array
    {
        return [
            'firstDay' => 1,
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay',
            ],
            'initialView' => 'timeGridWeek',
            'editable' => true,
            'selectable' => true,
        ];
    }

    public function fetchEvents(array $info): array
    {
        return Appointment::query()
            ->whereBetween('starts_at', [$info['start'], $info['end']])
            ->when($this->technicianId, fn ($query) => $query->where('technician_id', $this->technicianId))
            ->with(['customer', 'technician'])
            ->get()
            ->map(fn (Appointment $appointment) => EventData::make()
                ->id($appointment->id)
                ->title($appointment->title.($appointment->customer ? " — {$appointment->customer->company_name}" : ''))
                ->start($appointment->starts_at)
                ->end($appointment->ends_at)
                ->backgroundColor(match ($appointment->status) {
                    Appointment::STATUS_COMPLETATO => '#16a34a',
                    Appointment::STATUS_ANNULLATO => '#dc2626',
                    Appointment::STATUS_IN_CORSO => '#d97706',
                    default => '#2563eb',
                })
                ->toArray())
            ->toArray();
    }

    protected function headerActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mountUsing(function (Form $form, array $arguments) {
                    $form->fill([
                        'starts_at' => $arguments['start'] ?? now(),
                        'ends_at' => $arguments['end'] ?? now()->addHour(),
                        'technician_id' => auth()->id(),
                    ]);
                }),
        ];
    }

    protected function modalActions(): array
    {
        return [
            Actions\EditAction::make()
                ->mutateRecordDataUsing(function (array $data, array $arguments) {
                    if (in_array($arguments['type'] ?? null, ['drop', 'resize'], true)) {
                        $data['starts_at'] = $arguments['event']['start'];
                        $data['ends_at'] = $arguments['event']['end'];
                    }

                    return $data;
                }),
            Actions\DeleteAction::make(),
        ];
    }

    public function getFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('title')->label('Titolo')->required()->columnSpanFull(),
            Forms\Components\Select::make('customer_id')
                ->label('Cliente')
                ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                ->searchable(['company_name', 'first_name', 'last_name'])
                ->preload(),
            Forms\Components\Select::make('technician_id')
                ->label('Tecnico')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\DateTimePicker::make('starts_at')->label('Inizio')->required()->native(false),
            Forms\Components\DateTimePicker::make('ends_at')->label('Fine')->required()->native(false)->after('starts_at'),
            Forms\Components\Select::make('status')
                ->label('Stato')
                ->options([
                    Appointment::STATUS_PIANIFICATO => 'Pianificato',
                    Appointment::STATUS_CONFERMATO => 'Confermato',
                    Appointment::STATUS_IN_CORSO => 'In corso',
                    Appointment::STATUS_COMPLETATO => 'Completato',
                    Appointment::STATUS_ANNULLATO => 'Annullato',
                ])
                ->default(Appointment::STATUS_PIANIFICATO)
                ->required(),
            Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
        ];
    }
}
