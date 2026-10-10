<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Interview extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_interviews';
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function application() { return $this->belongsTo(Application::class); }
    public function panelists() { return $this->hasMany(Panelist::class, 'interview_id'); }
    public function scorecards() { return $this->hasMany(Scorecard::class, 'interview_id'); }
}
