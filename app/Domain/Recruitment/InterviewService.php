<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Contracts\CandidateChannelAdapter;
use App\Domain\Recruitment\Models\{Interview, Panelist, Scorecard};
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InterviewService
{
    public function schedule(User $actor, array $data): Interview
    {
        abort_unless($actor->allows('recruitment.manage'), 403);
        return DB::transaction(function () use ($actor, $data) {
            $app = app(HiringScope::class)->applications($actor, 'recruitment.manage')->where('uuid', $data['application_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($app->status === 'active', 409, 'Closed applications cannot be scheduled.');
            $tz = $data['timezone'];
            $starts = CarbonImmutable::parse($data['starts_at'], $tz)->utc();
            $ends = CarbonImmutable::parse($data['ends_at'], $tz)->utc();
            if ($ends <= $starts) throw ValidationException::withMessages(['ends_at' => 'The interview must end after it starts.']);
            $panel = collect($data['panelist_ids'])->unique()->values();
            $users = User::where('active', true)->whereIn('id', $panel)->get();
            if ($users->count() !== $panel->count()) throw ValidationException::withMessages(['panelist_ids' => 'Choose active users from this workspace.']);
            foreach ($users as $u) {
                if (!$u->allows('recruitment.view')) throw ValidationException::withMessages(['panelist_ids' => 'Panelists need recruitment viewing permission.']);
                $clash = Panelist::where('user_id', $u->id)->whereIn('interview_id', Interview::where('status', 'scheduled')->where('starts_at', '<', $ends->format('Y-m-d H:i:s'))->where('ends_at', '>', $starts->format('Y-m-d H:i:s'))->select('id'))->exists();
                if ($clash) throw ValidationException::withMessages(['panelist_ids' => $u->name.' already has an interview at that time.']);
            }
            $i = Interview::create(['application_id' => $app->id, 'round' => $data['round'], 'starts_at' => $starts->format('Y-m-d H:i:s'), 'ends_at' => $ends->format('Y-m-d H:i:s'), 'timezone' => $tz,
                'mode' => $data['mode'] ?? 'video', 'location_text' => $data['location_text'] ?? null, 'created_by' => $actor->id]);
            foreach ($users as $u) {
                Panelist::create(['interview_id' => $i->id, 'user_id' => $u->id]);
                RecruitmentNotify::tell($u->id, 'Interview scheduled', $i->round.' on '.$starts->setTimezone($tz)->format('Y-m-d H:i').' ('.$tz.')');
                RecruitmentNotify::task($u->id, 'Submit interview feedback', 'Complete the scorecard after the interview.', 'scorecard:'.$i->uuid.':'.$u->id);
            }
            app(CandidateChannelAdapter::class)->interviewScheduled(['uuid' => $i->uuid, 'starts_at' => $starts->toIso8601String()]);
            Audit::record('interview.scheduled', 'recruitment', $i->uuid);
            return $i;
        });
    }

    public function cancel(User $actor, string $uuid): Interview
    {
        abort_unless($actor->allows('recruitment.manage'), 403);
        return DB::transaction(function () use ($actor, $uuid) {
            $i = Interview::where('uuid', $uuid)->whereIn('application_id', app(HiringScope::class)->applications($actor, 'recruitment.manage')->select('id'))->lockForUpdate()->firstOrFail();
            abort_unless($i->status === 'scheduled', 409, 'Only scheduled interviews can be cancelled.');
            $i->update(['status' => 'cancelled']);
            foreach ($i->panelists as $p) app(TaskService::class)->completeBySource('recruitment', 'scorecard:'.$i->uuid.':'.$p->user_id);
            Audit::record('interview.cancelled', 'recruitment', $i->uuid);
            return $i;
        });
    }

    /** Only a panelist of the interview submits, once. Feedback is private: see scorecardsFor(). */
    public function submitScorecard(User $actor, string $uuid, array $data): Scorecard
    {
        return DB::transaction(function () use ($actor, $uuid, $data) {
            $i = Interview::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(Panelist::where('interview_id', $i->id)->where('user_id', $actor->id)->exists(), 404);
            abort_unless($i->status !== 'cancelled', 409, 'This interview was cancelled.');
            abort_if($i->starts_at->isFuture(), 409, 'Feedback opens when the interview starts.');
            abort_if(Scorecard::where('interview_id', $i->id)->where('user_id', $actor->id)->exists(), 409, 'You already submitted feedback for this interview.');
            $s = Scorecard::create(['interview_id' => $i->id, 'user_id' => $actor->id, 'rating' => $data['rating'], 'recommendation' => $data['recommendation'], 'strengths' => $data['strengths'] ?? null, 'concerns' => $data['concerns'] ?? null, 'submitted_at' => now()]);
            app(TaskService::class)->completeBySource('recruitment', 'scorecard:'.$i->uuid.':'.$actor->id);
            if (Scorecard::where('interview_id', $i->id)->count() >= Panelist::where('interview_id', $i->id)->count()) $i->update(['status' => 'completed']);
            Audit::record('scorecard.submitted', 'recruitment', $i->uuid);
            return $s;
        });
    }

    /**
     * Feedback privacy: a panelist sees only their OWN scorecard. Hiring-team members with recruitment.manage (in scope) see all submitted ones.
     * There are no drafts: a scorecard exists only once submitted.
     */
    public function scorecardsFor(User $actor, Interview $i): array
    {
        $q = Scorecard::where('interview_id', $i->id);
        $all = $actor->allows('recruitment.manage') && app(HiringScope::class)->applications($actor, 'recruitment.manage')->whereKey($i->application_id)->exists();
        if (!$all) $q->where('user_id', $actor->id);
        $names = User::whereIn('id', Panelist::where('interview_id', $i->id)->select('user_id'))->pluck('name', 'id');
        return $q->get()->map(fn ($s) => ['panelist' => $names[$s->user_id] ?? null, 'mine' => $s->user_id === $actor->id, 'rating' => $s->rating, 'recommendation' => $s->recommendation, 'strengths' => $s->strengths, 'concerns' => $s->concerns, 'submitted_at' => $s->submitted_at])->all();
    }
}
