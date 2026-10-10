<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\AnnouncementService;
use App\Domain\Experience\Models\Announcement;
use Illuminate\Http\Request;

final class AnnouncementController
{
    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $r)
    {
        return $this->announcements->visible($r->user())->orderByDesc('publish_at')->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $r->query('per_page', 20))))
            ->through(fn ($announcement) => $this->announcements->present($announcement, $r->user()));
    }

    public function create(Request $r)
    {
        $data = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:50000',
            'acknowledgment_required' => 'sometimes|boolean', 'publish_at' => 'nullable|date_format:Y-m-d']);
        return response()->json($this->announcements->present($this->announcements->create($r->user(), $data), $r->user()), 201);
    }

    public function publish(Request $r, string $uuid)
    {
        $announcement = Announcement::where('uuid', $uuid)->firstOrFail();
        return $this->announcements->present($this->announcements->publish($announcement, $r->user()), $r->user());
    }

    public function read(Request $r, string $uuid)
    {
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $this->announcements->record($r->user(), $announcement);
        return $this->announcements->present($announcement, $r->user());
    }

    public function acknowledge(Request $r, string $uuid)
    {
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        abort_unless($announcement->status === 'published', 404);
        $this->announcements->record($r->user(), $announcement, true);
        return $this->announcements->present($announcement, $r->user());
    }
}
