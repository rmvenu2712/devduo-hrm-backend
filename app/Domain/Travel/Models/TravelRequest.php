<?php
namespace App\Domain\Travel\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class TravelRequest extends TenantRecord {
    use HasPublicId;
    protected $attributes = ['status' => 'draft'];
    protected $table = 'travel_requests';
    protected function casts(): array { return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'minor_units' => 'integer', 'budget_minor' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    
}
