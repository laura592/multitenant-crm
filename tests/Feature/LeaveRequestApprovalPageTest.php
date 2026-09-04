<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveRequestResource\Pages\ViewLeaveRequest;
use App\Mail\LeaveRequestDecisionMail;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Regressione per il link "Apri la richiesta" nella mail di notifica ferie:
 * portava alla pagina di modifica invece che a una vista con Approva/Rifiuta
 * (vedi resources/views/mail/new-leave-request.blade.php e
 * LeaveRequestResource::getPages()).
 */
class LeaveRequestApprovalPageTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->admin, $this->tenant, 'admin');
        $this->employee = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Dipendente', 'email' => 'dip@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->employee, $this->tenant, 'dipendente');

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    public function test_view_page_approves_a_pending_leave_request(): void
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->employee->id,
            'type' => LeaveRequest::TYPE_FERIE,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDays(2)->toDateString(),
        ]);

        Livewire::test(ViewLeaveRequest::class, ['record' => $leaveRequest->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->callAction('approve')
            ->assertHasNoActionErrors();

        $leaveRequest->refresh();

        $this->assertSame('approvato', $leaveRequest->status);
        $this->assertSame($this->admin->id, $leaveRequest->approved_by_user_id);
        Mail::assertSent(LeaveRequestDecisionMail::class);
    }

    public function test_view_page_rejects_a_pending_leave_request(): void
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->employee->id,
            'type' => LeaveRequest::TYPE_FERIE,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDays(2)->toDateString(),
        ]);

        Livewire::test(ViewLeaveRequest::class, ['record' => $leaveRequest->getRouteKey()])
            ->callAction('reject')
            ->assertHasNoActionErrors();

        $this->assertSame('rifiutato', $leaveRequest->fresh()->status);
    }

    public function test_employee_without_approve_permission_does_not_see_approve_action(): void
    {
        $this->actingAs($this->employee);
        Filament::setTenant($this->tenant);

        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->employee->id,
            'type' => LeaveRequest::TYPE_FERIE,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDays(2)->toDateString(),
        ]);

        Livewire::test(ViewLeaveRequest::class, ['record' => $leaveRequest->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    }
}
