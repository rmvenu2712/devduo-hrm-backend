<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Announcement extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_announcements';
    protected function casts(): array { return ['acknowledgment_required' => 'boolean', 'publish_at' => 'date:Y-m-d', 'expires_at' => 'date:Y-m-d', 'audience_filters' => 'array', 'audience_all' => 'boolean', 'pinned' => 'boolean']; }
}
