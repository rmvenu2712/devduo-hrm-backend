<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\Models\Survey;
use App\Domain\Experience\SurveyService;
use Illuminate\Http\Request;

final class SurveyController
{
    public function __construct(private SurveyService $surveys) {}

    public function index(Request $r)
    {
        return $this->surveys->visible($r->user())->orderByDesc('opens_at')->paginate(min(50, max(1, (int) $r->query('per_page', 20))))
            ->through(fn ($survey) => $this->surveys->present($survey, $r->user()));
    }

    public function create(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:2000',
            'opens_at' => 'nullable|date_format:Y-m-d', 'closes_at' => 'nullable|date_format:Y-m-d',
            'minimum_responses' => 'sometimes|integer|min:5|max:1000', 'questions' => 'required|array|min:1|max:10', 'questions.*.prompt' => 'required|string|max:300']);
        if (! empty($d['opens_at']) && ! empty($d['closes_at']) && $d['closes_at'] < $d['opens_at']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['closes_at' => 'The close date must be on or after the open date.']);
        }
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
        $d = $r->validate(['answers' => 'required|array|min:1|max:10', 'answers.*' => 'required|integer|min:1|max:5']);
        $this->surveys->submit($survey, $r->user(), $d['answers']);
        return response()->json(['submitted' => true]);
    }

    public function results(Request $r, string $uuid)
    {
        return $this->surveys->results(Survey::where('uuid', $uuid)->firstOrFail());
    }
}
