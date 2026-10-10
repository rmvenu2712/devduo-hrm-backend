<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;

class AnnouncementsTest extends PerformanceTestCase
{
    public function test_published_announcement_tracks_employee_read_and_acknowledgment(): void
    {
        $created = $this->postJson('/api/v1/experience/announcements', [
            'title' => 'Benefits enrollment closes Friday', 'body' => 'Please review the available plans.', 'acknowledgment_required' => true,
        ])->assertCreated()->json();

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/publish')->assertOk()->assertJsonPath('status', 'published');

        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.acknowledged_at', null);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/read')->assertOk()->assertJsonPath('read_at', fn ($value) => ! empty($value))->assertJsonPath('acknowledged_at', null);
        $response = $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertOk();
        $response->assertJsonPath('read_at', fn ($value) => ! empty($value))->assertJsonPath('acknowledged_at', fn ($value) => ! empty($value));
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertOk();

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertNotFound();
    }

    public function test_acknowledgment_is_rejected_when_not_required(): void
    {
        $created = $this->postJson('/api/v1/experience/announcements', [
            'title' => 'Office hours', 'body' => 'The office will close early.',
        ])->assertCreated()->json();
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/announcements/'.$created['uuid'].'/acknowledge')->assertUnprocessable();
    }
}
