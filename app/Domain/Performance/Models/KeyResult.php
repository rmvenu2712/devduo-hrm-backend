<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class KeyResult extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_key_results';

    protected function casts(): array
    {
        return [];
    }
}
