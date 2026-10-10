<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Survey extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_surveys';
    protected function casts(): array { return ['opens_at' => 'date:Y-m-d', 'closes_at' => 'date:Y-m-d']; }
    public function questions() { return $this->hasMany(SurveyQuestion::class, 'survey_id')->orderBy('position'); }
}
