<?php

namespace App\Domain\Recruitment;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Counts and time-to-fill, scoped by HiringScope. Decimals are strings; no personal data. */
final class PipelineAnalytics
{
    public function summary(User $actor): array
    {
        $scope = app(HiringScope::class);
        $reqs = $scope->requisitions($actor, 'recruitment.reports.view')->get(['id', 'status', 'openings', 'filled_count', 'opened_at', 'filled_at', 'target_date']);
        $apps = $scope->applications($actor, 'recruitment.reports.view')->get(['id', 'stage', 'status', 'source']);
        $filled = $reqs->filter(fn ($r) => $r->filled_at && $r->opened_at);
        $days = $filled->map(fn ($r) => $r->opened_at->diffInSeconds($r->filled_at) / 86400);
        $offers = DB::table('recruitment_offers')->whereIn('application_id', $apps->pluck('id'))->where('tenant_id', $actor->tenant_id)->pluck('status');
        $answered = $offers->filter(fn ($s) => in_array($s, ['accepted', 'declined'], true))->count();
        $today = now('UTC')->toDateString();
        return [
            'requisitions' => ['by_status' => $reqs->countBy('status')->all(), 'openings' => (int) $reqs->whereIn('status', ['open'])->sum('openings'), 'filled' => (int) $reqs->sum('filled_count'),
                'overdue' => $reqs->where('status', 'open')->filter(fn ($r) => (string) $r->target_date->toDateString() < $today)->count()],
            'pipeline' => ['by_stage' => $apps->where('status', 'active')->countBy('stage')->all(), 'by_status' => $apps->countBy('status')->all(), 'by_source' => $apps->countBy(fn ($a) => $a->source ?: 'unknown')->all()],
            'time_to_fill' => ['filled_requisitions' => $filled->count(), 'average_days' => $days->isEmpty() ? null : number_format($days->avg(), 1, '.', '')],
            'offers' => ['by_status' => $offers->countBy()->all(), 'acceptance_rate' => $answered ? number_format($offers->where(fn ($s) => $s === 'accepted')->count() / $answered * 100, 1, '.', '') : null],
        ];
    }
}
