<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\Interview;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Recruitment\Models\Panelist;
use App\Domain\Recruitment\Models\Posting;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Local state expiry and once-per-stage/panel in-app reminders; no external delivery is claimed. */
final class RecruitmentHousekeeping
{
    public function run(): array
    {
        // Short-lived encrypted staging data is never retained past its review window.
        \App\Domain\Recruitment\Models\CandidateImport::where('expires_at', '<', now())->delete();
        $counts = ['offers_expired' => 0, 'postings_closed' => 0, 'stage_reminders' => 0, 'feedback_reminders' => 0];
        $today = now('UTC')->toDateString();
        Offer::whereIn('status', OfferService::OPEN)->where('expires_on', '<', $today)->orderBy('id')->chunkById(100, function ($offers) use (&$counts, $today) {
            foreach ($offers as $offer) {
                DB::transaction(function () use ($offer, &$counts, $today) {
                    $current = Offer::whereKey($offer->id)->lockForUpdate()->first();
                    if (! $current || ! in_array($current->status, OfferService::OPEN, true) || ! $current->expires_on || $current->expires_on->toDateString() >= $today) {
                        return;
                    }
                    $current->update(['status' => 'expired']);
                    app(TaskService::class)->completeBySource('recruitment', 'offer:'.$current->uuid);
                    Audit::record('offer.expired', 'recruitment', $current->uuid);
                    $counts['offers_expired']++;
                });
            }
        });
        Posting::where('status', 'published')->where('closes_on', '<', $today)->orderBy('id')->chunkById(100, function ($postings) use (&$counts, $today) {
            foreach ($postings as $posting) {
                DB::transaction(function () use ($posting, &$counts, $today) {
                    $current = Posting::whereKey($posting->id)->lockForUpdate()->first();
                    if (! $current || $current->status !== 'published' || ! $current->closes_on || substr((string) $current->closes_on, 0, 10) >= $today) {
                        return;
                    }
                    $current->update(['status' => 'closed']);
                    Audit::record('posting.expired', 'recruitment', $current->uuid);
                    $counts['postings_closed']++;
                });
            }
        });
        Application::where('status', 'active')->whereNull('stage_reminded_at')->where('stage_entered_at', '<=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->orderBy('id')->chunkById(100, function ($rows) use (&$counts, $today) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$counts, $today) {
                        $current = Application::whereKey($row->id)->lockForUpdate()->first();
                        if (! $current || $current->status !== 'active' || $current->stage_reminded_at || $current->stage_entered_at > now()->subDays(30)->format('Y-m-d H:i:s')) {
                            return;
                        }
                        if ($current->candidate->anonymized_at || $current->candidate->retention_until < $today || $current->requisition->status !== 'open') {
                            return;
                        }
                        $actor = $this->recipient($current->requisition->recruiter_id, 'recruitment.manage');
                        if (! $actor || ! app(HiringScope::class)->applications($actor, 'recruitment.manage')->whereKey($current->id)->exists()) {
                            return;
                        }
                        RecruitmentNotify::tell($actor->id, 'Hiring stage needs attention', 'An active application has waited in its current stage for at least 30 days.', '/recruitment/pipeline/'.$current->requisition->uuid);
                        $current->update(['stage_reminded_at' => now()]);
                        Audit::record('application.stage_reminded', 'recruitment', $current->uuid);
                        $counts['stage_reminders']++;
                    });
                }
            });
        Panelist::whereNull('feedback_reminded_at')->whereIn('interview_id', Interview::where('status', 'scheduled')->where('ends_at', '<=', now('UTC')->subDay()->format('Y-m-d H:i:s'))->select('id'))
            ->orderBy('id')->chunkById(100, function ($rows) use (&$counts, $today) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$counts, $today) {
                        // Same lock order as scorecard submission: interview before panelist.
                        $interview = Interview::whereKey($row->interview_id)->lockForUpdate()->first();
                        $panel = Panelist::whereKey($row->id)->lockForUpdate()->first();
                        if (! $interview || ! $panel || $panel->feedback_reminded_at || $interview->status !== 'scheduled' || $interview->ends_at > now('UTC')->subDay() || $interview->scorecards()->where('user_id', $panel->user_id)->exists()) {
                            return;
                        }
                        $candidate = $interview->application->candidate;
                        if ($interview->application->status !== 'active' || $candidate->anonymized_at || $candidate->retention_until < $today) {
                            return;
                        }
                        if (! $this->recipient($panel->user_id, 'recruitment.view')) {
                            return;
                        }
                        RecruitmentNotify::tell($panel->user_id, 'Interview feedback overdue', 'Please submit your outstanding interview scorecard.', '/recruitment/interviews');
                        $panel->update(['feedback_reminded_at' => now()]);
                        Audit::record('interview.feedback_reminded', 'recruitment', $interview->uuid, ['panelist_id' => $panel->user_id]);
                        $counts['feedback_reminders']++;
                    });
                }
            });

        return $counts;
    }

    private function recipient(int $id, string $permission): ?User
    {
        $user = User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->find($id);

        return $user?->allows($permission) ? $user : null;
    }
}
