<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Access\DataScope;
use App\Domain\Experience\HelpdeskService;
use App\Domain\Experience\Models\{ServiceCategory, Ticket};
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class HelpdeskController
{
    public function __construct(private HelpdeskService $helpdesk, private DataScope $scope) {}

    private function find(Request $r, string $uuid): Ticket
    {
        return $this->helpdesk->visibleTickets($r->user())->where('uuid', $uuid)->firstOrFail();
    }

    public function categories()
    {
        return $this->helpdesk->categories()->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description]);
    }

    public function createCategory(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', Rule::unique('experience_service_categories', 'code')->where('tenant_id', $r->user()->tenant_id)], 'name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000', 'target_hours' => 'nullable|integer|min:1|max:8760']);
        $category = $this->helpdesk->createCategory($d);
        return response()->json(['uuid' => $category->uuid, 'code' => $category->code, 'name' => $category->name, 'description' => $category->description, 'target_hours' => $category->target_hours], 201);
    }

    public function index(Request $r)
    {
        $q = $this->helpdesk->visibleTickets($r->user())->with('category')->latest('id');
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        if ($r->filled('category_uuid')) $q->whereIn('category_id', ServiceCategory::where('uuid', $r->query('category_uuid'))->select('id'));
        return $q->paginate(min(100, max(1, (int) $r->query('per_page', 25))))->through(fn ($ticket) => $this->helpdesk->present($ticket, $r->user()));
    }

    public function show(Request $r, string $uuid)
    {
        return $this->helpdesk->present($this->find($r, $uuid), $r->user());
    }

    public function store(Request $r)
    {
        $d = $r->validate(['category_uuid' => 'required|uuid', 'subject' => 'required|string|max:200', 'description' => 'required|string|max:10000']);
        $employeeId = $this->scope->ownEmployeeIds($r->user())[0] ?? abort(403, 'No employee profile is linked to this account.');
        $employee = Employee::findOrFail($employeeId);
        $ticket = $this->helpdesk->create($r->user(), $employee, $d);
        return response()->json($this->helpdesk->present($ticket, $r->user()), 201);
    }

    public function comment(Request $r, string $uuid)
    {
        $d = $r->validate(['body' => 'required|string|max:10000', 'visibility' => ['sometimes', Rule::in(['public', 'private'])]]);
        $ticket = $this->find($r, $uuid);
        $this->helpdesk->comment($r->user(), $ticket, $d['body'], $d['visibility'] ?? 'public');
        return $this->helpdesk->present($ticket->refresh(), $r->user());
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => ['sometimes', Rule::in(['in_progress', 'waiting_employee', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'assignee_id' => 'sometimes|nullable|integer', 'resolution' => 'sometimes|nullable|string|max:1000']);
        return $this->helpdesk->present($this->helpdesk->update($r->user(), $this->find($r, $uuid), $d), $r->user());
    }
}
