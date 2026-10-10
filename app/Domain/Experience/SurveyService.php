<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{Survey, SurveyAnswerCount, SurveyParticipant, SurveyQuestion};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SurveyService
{
    public function visible(User $viewer)
    {
        $q = Survey::query()->with('questions');
        if ($viewer->allows('experience.survey.manage')) return $q;
        $today = TenantClock::today();
        return $q->where('status', 'published')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', $today));
    }

    public function present(Survey $survey, User $viewer): array
    {
        return ['uuid' => $survey->uuid, 'title' => $survey->title, 'description' => $survey->description, 'status' => $survey->status,
            'opens_at' => $survey->opens_at?->format('Y-m-d'), 'closes_at' => $survey->closes_at?->format('Y-m-d'),
            'submitted' => SurveyParticipant::where('survey_id', $survey->id)->where('user_id', $viewer->id)->where('submitted', true)->exists(),
            'questions' => $survey->questions->map(fn ($q) => ['uuid' => $q->uuid, 'prompt' => $q->prompt, 'kind' => $q->kind, 'scale' => [1, 2, 3, 4, 5]])->values()];
    }

    public function create(User $actor, array $data): Survey
    {
        return DB::transaction(function () use ($actor, $data) {
            $survey = Survey::create(['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'status' => 'draft', 'opens_at' => $data['opens_at'] ?? TenantClock::today(), 'closes_at' => $data['closes_at'] ?? null,
                'minimum_responses' => $data['minimum_responses'] ?? 5, 'created_by' => $actor->id]);
            foreach ($data['questions'] as $i => $question) {
                SurveyQuestion::create(['uuid' => (string) Str::uuid(), 'survey_id' => $survey->id, 'position' => $i + 1,
                    'prompt' => $question['prompt'], 'kind' => 'rating']);
            }
            Audit::record('survey.created', 'experience', 'survey:'.$survey->uuid, ['question_count' => count($data['questions']), 'minimum_responses' => $survey->minimum_responses]);
            return $survey->load('questions');
        });
    }

    public function publish(Survey $survey, User $actor): Survey
    {
        if ($survey->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft surveys can be published.']);
        $survey->update(['status' => 'published', 'published_by' => $actor->id]);
        Audit::record('survey.published', 'experience', 'survey:'.$survey->uuid);
        return $survey->load('questions');
    }

    public function submit(Survey $survey, User $actor, array $answers): void
    {
        if ($survey->status !== 'published' || ($survey->opens_at && $survey->opens_at->gt(TenantClock::today())) || ($survey->closes_at && $survey->closes_at->lt(TenantClock::today()))) {
            throw ValidationException::withMessages(['survey' => 'This survey is not currently open.']);
        }
        $questions = $survey->questions;
        $expected = $questions->pluck('uuid')->sort()->values()->all();
        $provided = collect(array_keys($answers))->sort()->values()->all();
        if ($expected !== $provided) throw ValidationException::withMessages(['answers' => 'Submit one rating for every survey question.']);
        if (count(app(\App\Domain\Access\DataScope::class)->ownEmployeeIds($actor)) === 0) abort(403, 'Only employees may respond to this survey.');

        DB::transaction(function () use ($survey, $actor, $answers, $questions) {
            $participant = SurveyParticipant::firstOrCreate(['survey_id' => $survey->id, 'user_id' => $actor->id]);
            $participant = SurveyParticipant::whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if ($participant->submitted) throw ValidationException::withMessages(['survey' => 'You have already submitted a response.']);
            foreach ($questions as $question) {
                $bucket = SurveyAnswerCount::firstOrCreate(['survey_id' => $survey->id, 'question_id' => $question->id, 'answer' => (int) $answers[$question->uuid]], ['response_count' => 0]);
                $bucket = SurveyAnswerCount::whereKey($bucket->id)->lockForUpdate()->firstOrFail();
                $bucket->increment('response_count');
            }
            $participant->update(['submitted' => true]);
        });
    }

    public function results(Survey $survey): array
    {
        $count = SurveyParticipant::where('survey_id', $survey->id)->where('submitted', true)->count();
        if ($count < $survey->minimum_responses) return ['suppressed' => true, 'minimum_responses' => $survey->minimum_responses];
        $questions = $survey->questions->map(function ($question) use ($survey, $count) {
            $counts = SurveyAnswerCount::where('survey_id', $survey->id)->where('question_id', $question->id)->pluck('response_count', 'answer');
            return ['uuid' => $question->uuid, 'prompt' => $question->prompt,
                'distribution' => collect(range(1, 5))->map(fn ($rating) => ['rating' => $rating, 'count' => (int) ($counts[$rating] ?? 0) >= $survey->minimum_responses ? (int) $counts[$rating] : null,
                    'suppressed' => (int) ($counts[$rating] ?? 0) < $survey->minimum_responses])->all()];
        })->values()->all();
        return ['suppressed' => false, 'response_count' => $count, 'minimum_responses' => $survey->minimum_responses, 'questions' => $questions];
    }
}
