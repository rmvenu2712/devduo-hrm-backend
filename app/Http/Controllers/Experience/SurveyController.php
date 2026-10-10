<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\Models\Survey;
use App\Domain\Experience\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

final class SurveyController
{
    public function __construct(private SurveyService $surveys) {}

    public function index(Request $r)
    {
        $rows = $this->surveys->visible($r->user())->orderByDesc('opens_at')->limit(500)->get()
            ->filter(fn ($survey) => $r->user()->allows('experience.survey.manage') || $this->surveys->eligible($r->user(), $survey))->values();
        $perPage = min(50, max(1, (int) $r->query('per_page', 20)));
        $page = max(1, (int) $r->query('page', 1));
        return new LengthAwarePaginator($rows->forPage($page, $perPage)->map(fn ($survey) => $this->surveys->present($survey, $r->user())), $rows->count(), $perPage, $page, ['path' => $r->url()]);
    }

    public function audienceOptions() { return $this->surveys->audienceOptions(); }

    public function create(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:2000',
            'opens_at' => 'nullable|date_format:Y-m-d', 'closes_at' => 'nullable|date_format:Y-m-d', 'retention_until' => 'required|date_format:Y-m-d',
            'campaign_type' => ['sometimes', Rule::in(['engagement', 'pulse', 'onboarding', 'exit', 'custom'])], 'requires_consent' => 'sometimes|boolean',
            'minimum_responses' => 'sometimes|integer|min:5|max:1000', 'eligibility' => 'sometimes|array:legal_entity_ids,location_ids,designation_ids',
            'eligibility.legal_entity_ids' => 'sometimes|array|max:50', 'eligibility.legal_entity_ids.*' => 'required|integer|min:1|distinct',
            'eligibility.location_ids' => 'sometimes|array|max:50', 'eligibility.location_ids.*' => 'required|integer|min:1|distinct',
            'eligibility.designation_ids' => 'sometimes|array|max:50', 'eligibility.designation_ids.*' => 'required|integer|min:1|distinct',
            'questions' => 'required|array|min:1|max:10', 'questions.*.prompt' => 'required|string|max:300']);
        if (! empty($d['opens_at']) && ! empty($d['closes_at']) && $d['closes_at'] < $d['opens_at']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['closes_at' => 'The close date must be on or after the open date.']);
        }
        if ($d['retention_until'] <= ($d['closes_at'] ?? $d['opens_at'] ?? now()->toDateString())) throw \Illuminate\Validation\ValidationException::withMessages(['retention_until' => 'Retention must continue beyond the survey close date.']);
        $survey = $this->surveys->create($r->user(), $d);
        return response()->json($this->surveys->present($survey, $r->user()), 201);
    }

    public function publish(Request $r, string $uuid)
    {
        $survey = Survey::where('uuid', $uuid)->firstOrFail();
        return $this->surveys->present($this->surveys->publish($survey, $r->user()), $r->user());
    }

    public function submit(Request $r, string $uuid)
    {
        $survey = $this->surveys->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $d = $r->validate(['answers' => 'required|array|min:1|max:10', 'answers.*' => 'required|integer|min:1|max:5', 'consent' => 'sometimes|boolean']);
        $this->surveys->submit($survey, $r->user(), $d['answers'], $d['consent'] ?? false);
        return response()->json(['submitted' => true]);
    }

    public function results(Request $r, string $uuid)
    {
        return $this->surveys->results(Survey::where('uuid', $uuid)->firstOrFail());
    }
}
