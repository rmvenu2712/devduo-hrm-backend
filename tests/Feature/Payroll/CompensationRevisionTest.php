<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Groups\GroupResolver;
use App\Domain\Payroll\Models\EmployeeCompensation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CompensationRevisionTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, PayrollFixtures;

    protected function setUp(): void { parent::setUp(); $this->bootTenant(); $this->signIn($this->admin); }

    private function comp(string $code, array $o = []): string
    {
        $uuid = $this->postJson('/api/v1/payroll/components', $o + ['name' => $code, 'code' => $code, 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-01-01'])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);

        return $uuid;
    }

    /** @return array{0:string,1:\App\Domain\People\Models\Employee,2:array<string,string>} */
    private function setup3(): array
    {
        $group = $this->payrollGroup();
        $employee = $this->hire('Asha Rao');
        app(GroupResolver::class)->assign($employee, $group, '2026-01-05');
        $c = ['BASE' => $this->comp('BASE'), 'HRA' => $this->comp('HRA', ['calc_method' => 'percentage', 'config' => ['base_component' => 'BASE', 'percent' => '40']]),
            'BONUS' => $this->comp('BONUS', ['formula' => 'IF(BASE > 40000, ROUND(BASE * 10%, 0), 0)'])];

        return [$group->uuid, $employee, $c];
    }

    private function checker(): \App\Models\User { return $this->userWithRole('Workspace administrator', 'checker@alpha.test'); }

    public function test_formula_and_percentage_components_resolve_with_trace_and_derived_rates(): void
    {
        [$group, $employee, $c] = $this->setup3();
        $checker = $this->checker();
        $uuid = $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-10-01', 'reason' => 'Initial', 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['component_uuid' => $c['BASE'], 'amount' => '50000.00'], ['component_uuid' => $c['HRA']], ['component_uuid' => $c['BONUS']]]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->signIn($checker)->postJson('/api/v1/compensation/compensations/'.$uuid.'/decision', ['decision' => 'approved'])->assertOk();
        $this->context($checker->tenant_id);
        $r = app(CompensationResolver::class)->current($employee->id, '2026-10-15');
        $this->assertSame('50000.00', $r['base_pay']);
        $lines = collect($r['lines'])->keyBy('component_code');
        $this->assertSame('20000.00', $lines['HRA']['amount']);
        $this->assertSame('5000.00', $lines['BONUS']['amount']);
        $this->assertSame('75000.00', $r['gross_reference']);
        $this->assertSame('900000.00', $r['annual_compensation']);
        $this->assertSame('3461.5385', $r['daily_rate']);
        $this->assertNull(app(CompensationResolver::class)->current($employee->id, '2026-09-30'));
        $trace = EmployeeCompensation::first()->lines->firstWhere('component_id', DB::table('salary_components')->where('code', 'BONUS')->value('id'))->basis;
        $this->assertSame('IF(BASE > 40000, ROUND(BASE * 10%, 0), 0)', $trace['formula']);
        $this->assertArrayHasKey('trace', $trace);
    }

    public function test_masked_without_reveal_permission(): void
    {
        [$group, $employee, $c] = $this->setup3();
        $checker = $this->checker();
        $uuid = $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-10-01', 'reason' => 'Initial', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $c['BASE'], 'amount' => '50000.00']]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->signIn($checker)->postJson('/api/v1/compensation/compensations/'.$uuid.'/decision', ['decision' => 'approved'])->assertOk();
        $this->context($checker->tenant_id);
        $this->getJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation?as_of=2026-10-10')->assertOk()->assertJsonPath('compensation.lines.0.amount', '50000.00');
        $this->assertDatabaseHas('audit_logs', ['action' => 'compensation.viewed']);
        $this->context($checker->tenant_id);
        $role = \App\Models\Role::create(['name' => 'Viewer only', 'tenant_id' => $checker->tenant_id, 'data_scope' => 'tenant']);
        $role->permissions()->attach(\App\Models\Permission::where('key', 'payroll.compensation.view')->pluck('id'));
        $viewer = new \App\Models\User(['name' => 'V', 'email' => 'v@alpha.test', 'password' => 'StrongPassword123']); $viewer->tenant_id = $checker->tenant_id; $viewer->save(); $viewer->roles()->attach($role->id);
        $this->signIn($viewer->refresh())->getJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation?as_of=2026-10-10')->assertOk()->assertJsonPath('compensation.masked', true)->assertJsonPath('compensation.lines.0.amount', null);
    }

    public function test_revision_maker_checker_future_and_backdated_history_is_preserved(): void
    {
        [$group, $employee, $c] = $this->setup3();
        $checker = $this->checker();
        $lines = fn ($base) => [['component_uuid' => $c['BASE'], 'amount' => $base]];
        $uuid = $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-01-05', 'reason' => 'Initial', 'idempotency_key' => (string) Str::uuid(), 'lines' => $lines('30000.00')])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->signIn($checker)->postJson('/api/v1/compensation/compensations/'.$uuid.'/decision', ['decision' => 'approved'])->assertOk();
        $this->signIn($this->admin);
        $body = ['employee_uuid' => $employee->uuid, 'type' => 'increment', 'effective_date' => '2026-07-01', 'reason' => 'Annual', 'lines' => $lines('33000.00')];
        $prev = $this->postJson('/api/v1/compensation/revisions/preview', $body)->assertOk()->assertJsonPath('change.percent', '10.00')->assertJsonPath('persisted', false)->assertJsonPath('backdated', true);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, EmployeeCompensation::count());
        $key = (string) Str::uuid();
        $rev = $this->postJson('/api/v1/compensation/revisions', $body + ['idempotency_key' => $key])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/compensation/revisions', $body + ['idempotency_key' => $key])->assertCreated()->assertJsonPath('uuid', $rev);
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, EmployeeCompensation::count());
        $this->postJson('/api/v1/compensation/revisions/'.$rev.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->signIn($checker)->postJson('/api/v1/compensation/revisions/'.$rev.'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'applied');
        $this->context($checker->tenant_id);
        $res = app(CompensationResolver::class);
        $this->assertSame('30000.00', $res->current($employee->id, '2026-06-30')['base_pay']);
        $this->assertSame('33000.00', $res->current($employee->id, '2026-07-01')['base_pay']);
        $this->assertCount(2, $res->between($employee->id, '2026-06-01', '2026-07-31'));
        $this->assertDatabaseHas('domain_events', ['type' => 'payroll.compensation.revised']);
        // a second revision on the same effective date supersedes without deleting history
        $this->signIn($this->admin);
        $rev2 = $this->postJson('/api/v1/compensation/revisions', array_merge($body, ['type' => 'market_correction', 'idempotency_key' => (string) Str::uuid(), 'lines' => $lines('34000.00')]))->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->signIn($checker)->postJson('/api/v1/compensation/revisions/'.$rev2.'/decision', ['decision' => 'approved'])->assertOk();
        $this->context($checker->tenant_id);
        $this->assertSame('34000.00', $res->current($employee->id, '2026-08-01')['base_pay']);
        $this->assertSame(1, EmployeeCompensation::where('status', 'superseded')->count());
        $this->assertSame(3, EmployeeCompensation::count());
        $this->getJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation/history')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_pending_and_rejected_are_never_resolved_and_unknown_components_fail(): void
    {
        [$group, $employee, $c] = $this->setup3();
        $uuid = $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-01-05', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $c['BASE'], 'amount' => '1000']]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->assertNull(app(CompensationResolver::class)->current($employee->id, '2026-10-01'));
        $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-01-05', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => (string) Str::uuid(), 'amount' => '1']]])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['effective_from' => '2026-01-05', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $c['BASE'], 'amount' => '1000.00']], 'group_uuid' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_structures_and_component_versions(): void
    {
        [$group, $employee, $c] = $this->setup3();
        $s = $this->postJson('/api/v1/compensation/structures', ['code' => 'STD', 'name' => 'Standard', 'effective_from' => '2026-01-01', 'currency' => 'INR', 'components' => [['component_code' => 'BASE'], ['component_code' => 'HRA', 'required' => false]]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/compensation/structures/'.$s.'/activate')->assertOk()->assertJsonPath('status', 'active');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/compensation/structures', ['code' => 'BAD', 'name' => 'Bad', 'effective_from' => '2026-01-01', 'components' => [['component_code' => 'NOPE']]])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        // structure requires BASE; BONUS is not in the structure
        $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['structure_uuid' => $s, 'effective_from' => '2026-02-01', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $c['BONUS']]]])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/compensation/employees/'.$employee->uuid.'/compensation', ['structure_uuid' => $s, 'effective_from' => '2026-02-01', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $c['BASE'], 'amount' => '1000.00']]])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, DB::table('compensation_lines')->count()); // BASE + auto HRA (percentage)
        // used component is immutable; a new version closes the previous one
        $this->patchJson('/api/v1/payroll/components/'.$c['BASE'], ['name' => 'Renamed'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/components/'.$c['BASE'].'/versions', ['effective_from' => '2027-01-01', 'name' => 'Base v2'])->assertCreated()->assertJsonPath('version', 2);
        $this->context($this->admin->tenant_id);
        $this->assertSame('2026-12-31', substr((string) DB::table('salary_components')->where('code', 'BASE')->where('version', 1)->value('effective_to'), 0, 10));
        $this->postJson('/api/v1/payroll/components', ['name' => 'Loop', 'code' => 'LOOP', 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-01-01', 'formula' => 'LOOP * 2'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/formulas/check', ['formula' => 'IF(BASE > 1, 1, 0) + eval', 'variables' => ['BASE' => '2']])->assertOk()->assertJsonPath('valid', false);
    }
}
