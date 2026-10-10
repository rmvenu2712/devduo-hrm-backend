<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class HelpdeskTest extends PerformanceTestCase
{
    private function openTicket(): string
    {
        $category = collect($this->getJson('/api/v1/experience/service-categories')->assertOk()->json())->first()['uuid'];
        $this->actAs($this->emp1);
        return $this->postJson('/api/v1/experience/tickets', ['category_uuid' => $category, 'subject' => 'Payslip question', 'description' => 'Please help me understand this payslip.'])
            ->assertCreated()->json('uuid');
    }

    public function test_private_notes_are_hidden_from_employee_and_manager(): void
    {
        $ticket = $this->openTicket();
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'Additional detail from me.'])->assertOk()->assertJsonPath('first_response_at', null);
        $this->signIn($this->admin);
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'We are checking this.', 'visibility' => 'public'])->assertOk()->assertJsonPath('first_response_at', fn ($value) => ! empty($value));
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'Check the payroll export before responding.', 'visibility' => 'private'])->assertOk();

        $this->actAs($this->emp1);
        $response = $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk();
        $response->assertJsonCount(2, 'comments')->assertJsonMissing(['body' => 'Check the payroll export before responding.']);
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'I would like to add an internal note.', 'visibility' => 'private'])->assertForbidden();

        $this->actAs($this->mgr);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonCount(2, 'comments');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonCount(3, 'comments');
    }

    public function test_ticket_visibility_is_tenant_scoped(): void
    {
        $ticket = $this->openTicket();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertNotFound();
    }

    public function test_hr_can_resolve_and_close_but_invalid_transition_is_rejected(): void
    {
        $ticket = $this->openTicket();
        $this->signIn($this->admin);
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'closed'])->assertUnprocessable();
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'resolved', 'resolution' => 'Corrected the payslip'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
    }
}

