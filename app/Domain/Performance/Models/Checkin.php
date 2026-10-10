<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Checkin extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_checkins';

    protected function casts(): array
    {
        return [];
    }
}
