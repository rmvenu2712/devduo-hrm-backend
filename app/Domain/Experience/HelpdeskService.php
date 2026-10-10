<?php

namespace App\Domain\Experience;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{ServiceCategory, Ticket, TicketComment};
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HelpdeskService
{
    public function __construct(private DataScope $scope) {}

    public function categories()
    {
        if (! ServiceCategory::exists()) {
            foreach ([['PAYROLL', 'Payroll'], ['BENEFITS', 'Benefits'], ['LEAVE', 'Leave'], ['WORKPLACE', 'Workplace'], ['OTHER', 'Other']] as [$code, $name]) {
                ServiceCategory::firstOrCreate(['code' => $code], ['name' => $name, 'active' => true]);
            }
        }
        return ServiceCategory::where('active', true)->orderBy('name')->get();
    }

    public function visibleTickets(User $user)
    {
        if ($user->allows('experience.ticket.manage') && $this->scope->hasTenantWide($user, 'experience.ticket.manage')) return Ticket::query();
        $ids = $this->scope->ownEmployeeIds($user);
        if ($user->allows('experience.view')) {
            $employees = $this->scope->constrain(DB::table('employees')->where('employees.tenant_id', $user->tenant_id)->whereNull('employees.deleted_at'), $user, 'experience.view')->pluck('employees.id')->all();
            $ids = array_values(array_unique([...$ids, ...$employees]));
        }
        return Ticket::whereIn('employee_id', $ids ?: [0]);
    }

    private function canManageTicket(User $user, Ticket $ticket): bool
    {
        return $user->allows('experience.ticket.manage') && ($this->scope->hasTenantWide($user, 'experience.ticket.manage') || $this->scope->canAccess($user, $ticket->employee_id, 'experience.ticket.manage'));
    }

    public function present(Ticket $ticket, User $viewer): array
    {
        $ticket->loadMissing('category', 'comments');
        $private = $this->canManageTicket($viewer, $ticket);
        $employee = Employee::with('person')->find($ticket->employee_id);
        $comments = $ticket->comments->filter(fn ($c) => $private || $c->visibility === 'public')->map(fn ($c) => [
            'uuid' => $c->uuid, 'body' => $c->body, 'visibility' => $c->visibility,
            'author' => User::whereKey($c->author_id)->value('name'), 'created_at' => $c->created_at?->toIso8601String(),
        ])->values()->all();
        return [
            'uuid' => $ticket->uuid, 'subject' => $ticket->subject, 'description' => $ticket->description,
            'category' => ['uuid' => $ticket->category?->uuid, 'name' => $ticket->category?->name],
            'employee' => ['uuid' => $employee?->uuid, 'name' => trim(($employee?->person?->legal_first_name ?? '').' '.($employee?->person?->legal_last_name ?? ''))],
            'assignee_id' => $ticket->assignee_id, 'priority' => $ticket->priority,
            'status' => $ticket->status, 'resolution' => $ticket->resolution, 'sla_due_at' => $ticket->sla_due_at?->toIso8601String(), 'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(), 'created_at' => $ticket->created_at?->toIso8601String(), 'comments' => $comments,
        ];
    }

    public function create(User $actor, Employee $employee, array $data): Ticket
    {
        $category = ServiceCategory::where('uuid', $data['category_uuid'])->where('active', true)->firstOrFail();
        $ticket = Ticket::create([
            'employee_id' => $employee->id, 'category_id' => $category->id, 'requester_id' => $actor->id,
            'subject' => $data['subject'], 'description' => $data['description'], 'priority' => 'normal', 'status' => 'open',
            'sla_due_at' => $category->target_hours ? now()->addHours($category->target_hours) : null,
        ]);
        Audit::record('ticket.created', 'experience', 'ticket:'.$ticket->uuid, ['category' => $category->code]);
        return $ticket;
    }

    public function comment(User $actor, Ticket $ticket, string $body, string $visibility = 'public'): TicketComment
    {
        abort_unless($visibility === 'public' || $this->canManageTicket($actor, $ticket), 403, 'Only HR service staff with ticket access can add private notes.');
        if ($ticket->status === 'closed') throw ValidationException::withMessages(['ticket' => 'A closed ticket cannot receive comments.']);
        $comment = TicketComment::create(['ticket_id' => $ticket->id, 'author_id' => $actor->id, 'visibility' => $visibility, 'body' => $body]);
        if ($visibility === 'public' && $this->canManageTicket($actor, $ticket) && ! $ticket->first_response_at) $ticket->update(['first_response_at' => now()]);
        Audit::record($visibility === 'private' ? 'ticket.private_note_added' : 'ticket.comment_added', 'experience', 'ticket:'.$ticket->uuid);
        return $comment;
    }

    public function update(User $actor, Ticket $ticket, array $data): Ticket
    {
        abort_unless($this->canManageTicket($actor, $ticket), 403, 'You cannot manage this ticket.');
        $before = $ticket->status;
        if (isset($data['status'])) {
            $allowed = match ($before) {
                'open' => ['in_progress', 'waiting_employee', 'resolved'],
                'in_progress' => ['waiting_employee', 'resolved'],
                'waiting_employee' => ['in_progress', 'resolved'],
                'resolved' => ['closed', 'in_progress'],
                default => [],
            };
            if (! in_array($data['status'], $allowed, true)) throw ValidationException::withMessages(['status' => 'That status change is not allowed.']);
            $ticket->status = $data['status'];
            $ticket->resolved_at = $data['status'] === 'resolved' ? now() : ($data['status'] === 'in_progress' ? null : $ticket->resolved_at);
            $ticket->closed_at = $data['status'] === 'closed' ? now() : null;
        }
        if (array_key_exists('assignee_id', $data)) {
            if ($data['assignee_id'] && ! User::where('tenant_id', $actor->tenant_id)->where('active', true)->whereKey($data['assignee_id'])->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose an active user in this workspace.']);
            }
            $ticket->assignee_id = $data['assignee_id'];
        }
        if (isset($data['priority'])) $ticket->priority = $data['priority'];
        if (array_key_exists('resolution', $data)) $ticket->resolution = $data['resolution'];
        $ticket->save();
        Audit::record('ticket.updated', 'experience', 'ticket:'.$ticket->uuid, ['from' => $before, 'to' => $ticket->status]);
        return $ticket->refresh();
    }

    public function createCategory(array $data): ServiceCategory
    {
        return ServiceCategory::create(['uuid' => (string) Str::uuid(), 'code' => Str::upper($data['code']), 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'active' => true, 'target_hours' => $data['target_hours'] ?? null]);
    }
}
