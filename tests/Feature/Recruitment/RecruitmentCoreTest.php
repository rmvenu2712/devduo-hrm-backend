<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\People\Models\Employee;
use App\Domain\Recruitment\Operations\{RecruitmentEmployee360Provider, RecruitmentInsightProvider, RecruitmentQualityRules};
use App\Models\{Permission, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class RecruitmentCoreTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($this->admin);
    }

    private function requisition(int $openings = 1, array $extra = []): string
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENG'.Str::upper(Str::random(4)), 'title' => 'Software engineer', 'description' => 'Build tested applications.'])->assertCreated()->json('uuid');
        $uuid = $this->postJson('/api/v1/recruitment/requisitions', $extra + ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->admin->id, 'openings' => $openings, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Approved staffing plan', 'budget_amount' => '1200000', 'budget_currency' => 'inr',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin);

        return $uuid;
    }

    private function candidate(string $email = 'asha@example.test', string $name = 'Asha Rao'): string
    {
        return $this->postJson('/api/v1/recruitment/candidates', ['name' => $name, 'email' => $email, 'phone' => '+91 90000 00000', 'source' => 'Referral', 'privacy_notice_version' => '2026.1', 'consent_reference' => 'Consent ref 001',
            'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
    }

    private function applicationAtOffer(string $req, string $candidate): string
    {
        $app = $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $candidate, 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated()->json('uuid');
        $from = 'applied';
        foreach (['screening', 'assessment', 'interview', 'offer'] as $stage) {
            $this->postJson('/api/v1/recruitment/applications/'.$app.'/transition', ['stage' => $stage, 'expected_stage' => $from, 'status' => 'active', 'reason' => 'Progressing on job-related criteria', 'idempotency_key' => (string) Str::uuid()])->assertOk();
            $from = $stage;
        }

        return $app;
    }

    private function acceptedOffer(string $app): string
    {
        $offer = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Software engineer', 'start_date' => '2026-11-02', 'compensation_reference' => 'Band E3, structure reference STR-2', 'currency' => 'inr'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/submit')->assertOk();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/approve')->assertForbidden(); // maker cannot check
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/offers/'.$offer.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->signIn($this->admin)->postJson('/api/v1/recruitment/offers/'.$offer.'/send')->assertOk();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/accept')->assertOk()->assertJsonPath('status', 'accepted');

        return $offer;
    }

    private function handoverPayload(array $o = []): array
    {
        return $o + ['assignment' => ['department_id' => $this->org['department'][0], 'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0], 'location_id' => $this->org['location'][0]]];
    }

    public function test_offer_to_employee_handover_end_to_end(): void
    {
        $req = $this->requisition();
        $app = $this->applicationAtOffer($req, $this->candidate());
        $offer = $this->acceptedOffer($app);
        $this->context($this->admin->tenant_id);
        $before = Employee::count();

        $res = $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertCreated()->assertJsonStructure(['employee_uuid', 'employee_number', 'status']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before + 1, Employee::count());
        $retry = $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertCreated();
        $this->assertSame($res->json('employee_uuid'), $retry->json('employee_uuid'));
        $this->context($this->admin->tenant_id);
        $this->assertSame($before + 1, Employee::count()); // no duplicate employment

        $employee = Employee::where('uuid', $res->json('employee_uuid'))->firstOrFail();
        $this->assertSame('preboarding', $employee->status);
        $this->assertSame('2026-11-02', $employee->joined_on->toDateString());
        $this->assertSame('Asha Rao', $employee->person->legal_name);
        $this->assertSame($this->org['legal_entity'][0], (int) DB::table('employee_assignments')->where('employee_id', $employee->id)->value('legal_entity_id'));
        $this->assertDatabaseHas('recruitment_applications', ['uuid' => $app, 'status' => 'hired', 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('recruitment_requisitions', ['uuid' => $req, 'status' => 'filled', 'filled_count' => 1]);

        $sections = app(RecruitmentEmployee360Provider::class)->sections($this->admin, $employee, []);
        $this->assertSame('Recruitment source', $sections[0]['title']);
        $this->assertSame('Referral', $sections[0]['items'][0]['value']);
        $this->assertSame([], app(RecruitmentEmployee360Provider::class)->sections($this->userWithRole('Team member', 'm@alpha.test'), $employee, []));

        $analytics = $this->getJson('/api/v1/recruitment/analytics')->assertOk()->json();
        $this->assertSame(1, $analytics['requisitions']['filled']);
        $this->assertSame(1, $analytics['time_to_fill']['filled_requisitions']);
        $this->assertSame(1, $analytics['pipeline']['by_status']['hired']);
        $this->assertSame('100.0', $analytics['offers']['acceptance_rate']);
    }

    public function test_handover_requires_accepted_offer_and_free_headcount(): void
    {
        $req = $this->requisition(1);
        $first = $this->applicationAtOffer($req, $this->candidate('one@example.test', 'Ravi One'));
        $second = $this->applicationAtOffer($req, $this->candidate('two@example.test', 'Meena Two'));
        $o1 = $this->acceptedOffer($first);
        $o2 = $this->acceptedOffer($second);
        $draft = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $this->applicationAtOffer($this->requisition(), $this->candidate('three@example.test', 'Sam Three')), 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$draft.'/handover', $this->handoverPayload())->assertConflict();
        $this->postJson('/api/v1/recruitment/offers/'.$o1.'/handover', $this->handoverPayload())->assertCreated();
        $this->postJson('/api/v1/recruitment/offers/'.$o2.'/handover', $this->handoverPayload())->assertConflict(); // single opening already filled
    }

    public function test_handover_warns_about_existing_person_and_needs_confirmation(): void
    {
        $this->hire('Asha Rao', ['personal_email' => 'asha@example.test']);
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->context($this->admin->tenant_id);
        $count = Employee::count();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertConflict()->assertJsonPath('code', 'possible_duplicate');
        $this->context($this->admin->tenant_id);
        $this->assertSame($count, Employee::count());
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload(['confirm_duplicates' => true]))->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertSame($count + 1, Employee::count());
    }

    public function test_offer_versioning_and_state_rules(): void
    {
        $app = $this->applicationAtOffer($this->requisition(), $this->candidate());
        $v1 = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->assertCreated()->assertJsonPath('version', 1)->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$v1.'/send')->assertConflict(); // not approved
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer II', 'start_date' => '2026-11-09'])->assertCreated()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('recruitment_offers', ['uuid' => $v1, 'status' => 'superseded']);
        $this->assertNotSame('Band E3', DB::table('recruitment_offers')->value('compensation_reference'));
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'X', 'start_date' => '2026-11-09', 'benchmark_employee_uuid' => (string) Str::uuid()])->assertStatus(404);
    }

    public function test_interview_scorecards_are_private_to_each_panelist(): void
    {
        $app = $this->applicationAtOffer($this->requisition(), $this->candidate());
        $this->context($this->admin->tenant_id);
        $view = Permission::where('key', 'recruitment.view')->firstOrFail();
        $role = Role::create(['name' => 'Panelist', 'description' => 'Interview panel', 'data_scope' => 'self', 'system' => false]);
        $role->permissions()->sync([$view->id]);
        $p1 = new User(['name' => 'Panel One', 'email' => 'p1@alpha.test', 'password' => 'StrongPassword123']);
        $p1->tenant_id = $this->admin->tenant_id; $p1->save(); $p1->roles()->attach($role->id);
        $p2 = new User(['name' => 'Panel Two', 'email' => 'p2@alpha.test', 'password' => 'StrongPassword123']);
        $p2->tenant_id = $this->admin->tenant_id; $p2->save(); $p2->roles()->attach($role->id);

        $this->signIn($p1)->getJson('/api/v1/recruitment/applications')->assertOk()->assertJsonCount(0, 'data'); // not on the panel yet
        $this->signIn($this->admin);
        $interview = $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-07T09:00', 'ends_at' => '2026-10-07T10:00', 'timezone' => 'Asia/Kolkata', 'panelist_ids' => [$p1->id, $p2->id]])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Clash', 'starts_at' => '2026-10-07T09:30', 'ends_at' => '2026-10-07T10:30', 'timezone' => 'Asia/Kolkata', 'panelist_ids' => [$p1->id]])->assertUnprocessable();
        $this->assertDatabaseHas('work_items', ['assignee_id' => $p1->id, 'source_module' => 'recruitment', 'status' => 'open']);

        $this->signIn($p1)->getJson('/api/v1/recruitment/applications')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 4, 'recommendation' => 'yes', 'strengths' => 'Clear reasoning', 'concerns' => 'Needs depth in testing'])->assertCreated();
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 4, 'recommendation' => 'yes'])->assertConflict();
        $this->assertNotSame('Clear reasoning', DB::table('recruitment_scorecards')->value('strengths')); // encrypted at rest
        $this->assertDatabaseHas('work_items', ['assignee_id' => $p1->id, 'source_module' => 'recruitment', 'status' => 'completed']);

        $this->signIn($p2)->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 2, 'recommendation' => 'no'])->assertCreated();
        $this->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', true);
        $this->signIn($p1)->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'X', 'starts_at' => '2026-10-08T09:00', 'ends_at' => '2026-10-08T10:00', 'timezone' => 'UTC', 'panelist_ids' => [$p1->id]])->assertForbidden();
        $this->signIn($this->admin)->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('recruitment_interviews', ['uuid' => $interview, 'status' => 'completed']);
    }

    public function test_authorization_and_tenant_isolation(): void
    {
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member);
        foreach (['offers', 'analytics', 'interviews', 'postings'] as $path) $this->getJson('/api/v1/recruitment/'.$path)->assertForbidden();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertForbidden();

        $other = $this->bootTenant('beta');
        $this->signIn($other);
        $this->getJson('/api/v1/recruitment/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload(['assignment' => ['department_id' => $this->org['department'][0], 'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0], 'location_id' => $this->org['location'][0]]]))->assertNotFound();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/withdraw')->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/recruitment/analytics')->json('requisitions.filled'));
    }

    public function test_candidate_duplicates_masking_and_anonymization(): void
    {
        $uuid = $this->candidate();
        $this->postJson('/api/v1/recruitment/candidates', ['name' => 'X', 'email' => 'ASHA@Example.test', 'source' => 's', 'privacy_notice_version' => 'v', 'consent_reference' => 'c', 'consented_at' => '2026-10-01T00:00:00Z', 'retention_until' => '2027-04-01'])->assertConflict();
        $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => 'other@example.test', 'source' => 's', 'privacy_notice_version' => 'v', 'consent_reference' => 'c', 'consented_at' => '2026-10-01T00:00:00Z', 'retention_until' => '2027-04-01'])
            ->assertCreated()->assertJsonPath('possible_duplicates.0', $uuid);
        $this->assertNotSame('+91 90000 00000', DB::table('recruitment_candidates')->value('phone'));

        $this->context($this->admin->tenant_id);
        $viewer = $this->userWithRole('HR administrator', 'hr2@alpha.test');
        $this->getJson('/api/v1/recruitment/candidates')->assertOk()->assertJsonPath('data.1.email', 'asha@example.test');
        $this->postJson('/api/v1/recruitment/candidates/'.$uuid.'/anonymize', [])->assertConflict(); // still inside retention
        $this->postJson('/api/v1/recruitment/candidates/'.$uuid.'/anonymize', ['erasure_request' => true])->assertOk();
        $this->assertDatabaseHas('recruitment_candidates', ['uuid' => $uuid, 'name' => 'Anonymized applicant']);
        $this->assertNotNull($viewer);
    }

    public function test_postings_requisition_budget_and_registries(): void
    {
        $req = $this->requisition(2);
        $this->assertDatabaseHas('recruitment_requisitions', ['uuid' => $req, 'budget_amount' => '1200000.00', 'budget_currency' => 'INR']);
        $posting = $this->postJson('/api/v1/recruitment/postings', ['requisition_uuid' => $req, 'title' => 'Software engineer', 'description' => 'Join us.', 'channel' => 'internal'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/postings/'.$posting.'/publish')->assertOk()->assertJsonPath('status', 'published');
        $this->postJson('/api/v1/recruitment/postings/'.$posting.'/publish')->assertConflict();
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/close')->assertOk();
        $this->assertDatabaseHas('recruitment_postings', ['uuid' => $posting, 'status' => 'closed']);

        $pending = $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $this->postJson('/api/v1/recruitment/jobs', ['code' => 'OPS', 'title' => 'Ops', 'description' => 'Ops'])->json('uuid'), 'legal_entity_id' => $this->org['legal_entity'][0],
            'recruiter_id' => $this->admin->id, 'hiring_manager_id' => $this->admin->id, 'openings' => 1, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'r', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker);
        $titles = collect(app(RecruitmentInsightProvider::class)->insights($this->checker))->pluck('key')->all();
        $this->assertContains('recruitment.requisitions_pending', $titles);
        $this->assertNotNull($pending);
        $this->assertCount(4, RecruitmentQualityRules::all());
        $this->assertSame([], iterator_to_array(RecruitmentQualityRules::all()[2]->scan($this->admin->tenant_id), false));
    }
}
