<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SurveyQuestion extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_survey_questions';
}
