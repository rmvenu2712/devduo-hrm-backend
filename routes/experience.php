<?php

use App\Http\Controllers\Experience\HelpdeskController as Helpdesk;
use App\Http\Controllers\Experience\AnnouncementController as Announcements;
use App\Http\Controllers\Experience\SurveyController as Surveys;
use Illuminate\Support\Facades\Route;

/* Phase 10 helpdesk core; included under authenticated tenant routes. */
Route::prefix('v1/experience')->middleware('module:experience')->group(function () {
    Route::get('service-categories', [Helpdesk::class, 'categories'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('service-categories', [Helpdesk::class, 'createCategory'])->middleware('permission:experience.category.manage');
    Route::get('knowledge', [Helpdesk::class, 'knowledgeIndex'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.knowledge.manage');
    Route::post('knowledge', [Helpdesk::class, 'knowledgeCreate'])->middleware('permission:experience.knowledge.manage');
    Route::get('knowledge/{uuid}', [Helpdesk::class, 'knowledgeShow'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.knowledge.manage');
    Route::post('knowledge/{uuid}/publish', [Helpdesk::class, 'knowledgePublish'])->middleware('permission:experience.knowledge.manage');
    Route::get('announcements', [Announcements::class, 'index'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.announcement.manage');
    Route::post('announcements', [Announcements::class, 'create'])->middleware('permission:experience.announcement.manage');
    Route::post('announcements/{uuid}/publish', [Announcements::class, 'publish'])->middleware('permission:experience.announcement.manage');
    Route::post('announcements/{uuid}/read', [Announcements::class, 'read'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.announcement.manage');
    Route::post('announcements/{uuid}/acknowledge', [Announcements::class, 'acknowledge'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.announcement.manage');
    Route::get('surveys', [Surveys::class, 'index'])->middleware('permission:experience.self|experience.view|experience.ticket.manage|experience.survey.manage');
    Route::post('surveys', [Surveys::class, 'create'])->middleware('permission:experience.survey.manage');
    Route::post('surveys/{uuid}/publish', [Surveys::class, 'publish'])->middleware('permission:experience.survey.manage');
    Route::post('surveys/{uuid}/responses', [Surveys::class, 'submit'])->middleware('permission:experience.self');
    Route::get('surveys/{uuid}/results', [Surveys::class, 'results'])->middleware('permission:experience.survey.manage');
    Route::get('tickets', [Helpdesk::class, 'index'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('tickets', [Helpdesk::class, 'store'])->middleware('permission:experience.self');
    Route::get('tickets/{uuid}', [Helpdesk::class, 'show'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::post('tickets/{uuid}/comments', [Helpdesk::class, 'comment'])->middleware('permission:experience.self|experience.view|experience.ticket.manage');
    Route::patch('tickets/{uuid}', [Helpdesk::class, 'update'])->middleware('permission:experience.ticket.manage');
});
