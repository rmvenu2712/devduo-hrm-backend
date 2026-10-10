<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{Announcement, AnnouncementRead};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnnouncementService
{
    public function visible(User $viewer)
    {
        $q = Announcement::query();
        if ($viewer->allows('experience.announcement.manage')) return $q;
        return $q->where('status', 'published')->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', TenantClock::today()));
    }

    public function present(Announcement $announcement, User $viewer): array
    {
        $tracking = AnnouncementRead::where('announcement_id', $announcement->id)->where('user_id', $viewer->id)->first();
        return ['uuid' => $announcement->uuid, 'title' => $announcement->title, 'body' => $announcement->body,
            'acknowledgment_required' => $announcement->acknowledgment_required, 'status' => $announcement->status,
            'publish_at' => $announcement->publish_at?->format('Y-m-d'), 'read_at' => $tracking?->read_at?->toIso8601String(),
            'acknowledged_at' => $tracking?->acknowledged_at?->toIso8601String(), 'created_at' => $announcement->created_at?->toIso8601String()];
    }

    public function create(User $actor, array $data): Announcement
    {
        return Announcement::create(['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'body' => $data['body'],
            'acknowledgment_required' => $data['acknowledgment_required'] ?? false, 'publish_at' => $data['publish_at'] ?? TenantClock::today(),
            'status' => 'draft', 'created_by' => $actor->id]);
    }

    public function publish(Announcement $announcement, User $actor): Announcement
    {
        if ($announcement->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft announcements can be published.']);
        $announcement->update(['status' => 'published', 'publish_at' => $announcement->publish_at ?? TenantClock::today(), 'published_by' => $actor->id]);
        Audit::record('announcement.published', 'experience', 'announcement:'.$announcement->uuid);
        return $announcement->refresh();
    }

    public function record(User $viewer, Announcement $announcement, bool $acknowledge = false): AnnouncementRead
    {
        return DB::transaction(function () use ($viewer, $announcement, $acknowledge) {
            $tracking = AnnouncementRead::firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $viewer->id], ['uuid' => (string) Str::uuid()]);
            $tracking->read_at ??= now();
            if ($acknowledge) {
                if (! $announcement->acknowledgment_required) throw ValidationException::withMessages(['announcement' => 'This announcement does not require acknowledgment.']);
                $tracking->acknowledged_at ??= now();
            }
            $tracking->save();
            Audit::record($acknowledge ? 'announcement.acknowledged' : 'announcement.read', 'experience', 'announcement:'.$announcement->uuid);
            return $tracking;
        });
    }
}
