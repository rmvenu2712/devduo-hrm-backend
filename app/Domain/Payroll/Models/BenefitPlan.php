<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class BenefitPlan extends TenantRecord
{
    use HasPublicId;

    protected $table = 'benefit_plans';

    protected function casts(): array
    {
        return ['provider' => 'array', 'eligibility' => 'array', 'contributions' => 'array'];
    }
}
