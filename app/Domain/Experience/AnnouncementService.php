<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Access\DataScope;
use App\Domain\Experience\Models\{Announcement, AnnouncementRead, AnnouncementRecipient};
use App\Domain\People\Models\{Assignment, Employee};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnnouncementService
{
    public function __construct(private DataScope $scope) {}

    public function visible(User $viewer)
    {
        $q = Announcement::query();
        if ($viewer->allows('experience.announcement.manage')) return $q;
        $ids = $this->scope->ownEmployeeIds($viewer);
        if (! $ids) return $q->whereRaw('1 = 0');
        return $q->where('status', 'published')->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', TenantClock::today()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', TenantClock::today()))
            ->where(fn ($q) => $q->where('audience_all', true)->orWhereExists(fn ($r) => $r->selectRaw('1')->from('experience_announcement_recipients as ar')
                ->whereColumn('ar.announcement_id', 'experience_announcements.id')->whereIn('ar.employee_id', $ids)));
    }

    public function present(Announcement $announcement, User $viewer): array
    {
        $tracking = AnnouncementRead::where('announcement_id', $announcement->id)->where('user_id', $viewer->id)->first();
        $status = $announcement->expires_at && $announcement->expires_at->lt(TenantClock::today()) ? 'expired' : $announcement->status;
        return ['uuid' => $announcement->uuid, 'title' => $announcement->title, 'body' => $announcement->body,
            'acknowledgment_required' => $announcement->acknowledgment_required, 'status' => $status, 'pinned' => (bool) $announcement->pinned,
            'publish_at' => $announcement->publish_at?->format('Y-m-d'), 'read_at' => $tracking?->read_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->format('Y-m-d'), 'audience' => $viewer->allows('experience.announcement.manage') ? ($announcement->audience_filters ?? []) : null,
            'acknowledged_at' => $tracking?->acknowledged_at?->toIso8601String(), 'created_at' => $announcement->created_at?->toIso8601String()];
    }

    public function create(User $actor, array $data): Announcement
    {
        $filters = array_filter($data['audience'] ?? [], fn ($values) => ! empty($values));
        $publishAt = $data['publish_at'] ?? TenantClock::today();
        if (! empty($data['expires_at']) && $data['expires_at'] < $publishAt) {
            throw ValidationException::withMessages(['expires_at' => 'The expiration date must be on or after the publish date.']);
        }
        return Announcement::create(['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'body' => $data['body'],
            'acknowledgment_required' => $data['acknowledgment_required'] ?? false, 'publish_at' => $data['publish_at'] ?? TenantClock::today(),
            'audience_filters' => $filters, 'audience_all' => $filters === [], 'pinned' => $data['pinned'] ?? false, 'expires_at' => $data['expires_at'] ?? null,
            'status' => 'draft', 'created_by' => $actor->id]);
    }

    public function audienceOptions(): array
    {
        return ['legal_entities' => $this->orgOptions('legal_entities'), 'locations' => $this->orgOptions('locations'), 'designations' => $this->orgOptions('designations')];
    }

    private function orgOptions(string $table): array
    {
        return DB::table($table)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('active', true)
            ->orderBy('name')->limit(200)->get(['id', 'name'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all();
    }

    public function publish(Announcement $announcement, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor) {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ($announcement->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft announcements can be published.']);
            $filters = $announcement->audience_filters ?? [];
            if ($filters) {
                $employeeIds = $this->audienceEmployeeIds($filters, $announcement->publish_at?->format('Y-m-d') ?? TenantClock::today());
                if (! $employeeIds) throw ValidationException::withMessages(['audience' => 'No working employees match the selected audience.']);
                $rows = array_map(fn ($id) => ['tenant_id' => $announcement->tenant_id, 'announcement_id' => $announcement->id, 'employee_id' => $id], $employeeIds);
                foreach (array_chunk($rows, 500) as $chunk) AnnouncementRecipient::insert($chunk);
            }
            $announcement->update(['status' => 'published', 'publish_at' => $announcement->publish_at ?? TenantClock::today(), 'published_by' => $actor->id]);
            Audit::record('announcement.published', 'experience', 'announcement:'.$announcement->uuid);
            return $announcement->refresh();
        });
    }

    private function audienceEmployeeIds(array $filters, string $date): array
    {
        $q = Assignment::where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
        foreach (['legal_entity_ids' => ['legal_entity_id', 'legal_entities'], 'location_ids' => ['location_id', 'locations'], 'designation_ids' => ['designation_id', 'designations']] as $key => [$column, $table]) {
            $ids = $filters[$key] ?? [];
            if (! $ids) continue;
            $validIds = DB::table($table)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->whereIn('id', $ids)->pluck('id')->all();
            if (count($validIds) !== count(array_unique($ids))) throw ValidationException::withMessages(['audience' => 'One or more audience organization values are invalid.']);
            $q->whereIn($column, $validIds);
        }
        $assignmentIds = $q->pluck('employee_id')->unique()->all();
        return Employee::whereIn('id', $assignmentIds)->whereIn('status', Employee::WORKING)->whereHas('person', fn ($q) => $q->whereNotNull('user_id'))->pluck('id')->all();
    }

    public function metrics(Announcement $announcement): array
    {
        $employeeIds = $announcement->audience_all
            ? Employee::whereIn('status', Employee::WORKING)->whereHas('person', fn ($q) => $q->whereNotNull('user_id'))->pluck('id')->all()
            : AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('employee_id')->all();
        if (count($employeeIds) < 5) return ['suppressed' => true, 'minimum_responses' => 5];
        $userIds = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $employeeIds)->whereNotNull('people.user_id')->pluck('people.user_id')->all();
        $tracking = AnnouncementRead::where('announcement_id', $announcement->id)->whereIn('user_id', $userIds);
        $read = (clone $tracking)->whereNotNull('read_at')->count();
        $ack = (clone $tracking)->whereNotNull('acknowledged_at')->count();
        return ['suppressed' => false, 'audience_count' => count($employeeIds), 'read_count' => $read >= 5 ? $read : null,
            'read_suppressed' => $read < 5, 'acknowledged_count' => $ack >= 5 ? $ack : null, 'acknowledged_suppressed' => $ack < 5];
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
