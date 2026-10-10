<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Ticket extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_tickets';
    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function comments() { return $this->hasMany(TicketComment::class, 'ticket_id')->orderBy('id'); }
}
