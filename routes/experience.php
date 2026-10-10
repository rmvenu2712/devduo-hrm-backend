<?php

use App\Http\Controllers\Experience\HelpdeskController as Helpdesk;
use Illuminate\Support\Facades\Route;

/* Phase 10 helpdesk core; included under authenticated tenant routes. */
Route::prefix('v1/experience')->middleware('module:experience')->group(function () {
    Route::get('service-categories', [Helpdesk::class, 'categories'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('service-categories', [Helpdesk::class, 'createCategory'])->middleware('permission:experience.category.manage');
    Route::get('tickets', [Helpdesk::class, 'index'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('tickets', [Helpdesk::class, 'store'])->middleware('permission:experience.self');
    Route::get('tickets/{uuid}', [Helpdesk::class, 'show'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('tickets/{uuid}/comments', [Helpdesk::class, 'comment'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::patch('tickets/{uuid}', [Helpdesk::class, 'update'])->middleware('permission:experience.ticket.manage');
});
