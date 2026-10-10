<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Survey;
use Tests\Feature\Performance\PerformanceTestCase;

class SurveysTest extends PerformanceTestCase
{
    private function createSurvey(): array
    {
        return $this->postJson('/api/v1/experience/surveys', ['title' => 'Team pulse', 'description' => 'A short anonymous check-in.',
            'minimum_responses' => 5, 'questions' => [['prompt' => 'Is workload manageable?']]])->assertCreated()->json();
    }

    public function test_anonymous_survey_suppresses_small_groups_and_only_exposes_aggregates(): void
    {
        $survey = $this->createSurvey();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $respondents = [$this->emp1, $this->emp2, $this->emp3, $this->mgr, $this->outsider];

        foreach (array_slice($respondents, 0, 4) as $employee) {
            $this->actAs($employee);
            $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        }
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('suppressed', true)->assertJsonMissingPath('response_count')->assertJsonMissingPath('questions');

        $this->actAs($this->outsider);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 4]])->assertOk();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 5]])->assertUnprocessable();

        $this->signIn($this->admin);
        $result = $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertOk()->assertJsonPath('suppressed', false)->assertJsonPath('response_count', 5)->json();
        $this->assertSame(5, $result['questions'][0]['distribution'][3]['count']);
        $this->assertTrue($result['questions'][0]['distribution'][4]['suppressed']);
        $this->assertArrayNotHasKey('answer', $result);
        $this->assertSame(1, \DB::table('experience_survey_answer_counts')->count());
        $this->assertSame(5, \DB::table('experience_survey_answer_counts')->value('response_count'));
        $this->assertSame(5, \DB::table('experience_survey_participants')->where('submitted', true)->count());

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/surveys/'.$survey['uuid'].'/results')->assertNotFound();
    }

    public function test_submission_requires_all_valid_rating_answers_and_employee_profile(): void
    {
        $survey = $this->createSurvey();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/publish')->assertOk();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => []])->assertUnprocessable();
        $this->postJson('/api/v1/experience/surveys/'.$survey['uuid'].'/responses', ['answers' => [$survey['questions'][0]['uuid'] => 6]])->assertUnprocessable();
    }
}
