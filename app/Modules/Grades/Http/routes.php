<?php

use App\Modules\Grades\Http\TeacherMarksController;
use App\Modules\Grades\Http\TeacherSession;
use App\Modules\Grades\Http\TeacherSessionController;
use Illuminate\Support\Facades\Route;

/*
| Routes of the Grades module (ADR-0034), loaded by its provider.
|
| /teacher is the one link the owner sends to every teacher: a code opens
| the teacher's own subjects on the `teacher` guard. These routes never use
| the users' `auth` middleware, and the users' pages never accept a teacher.
| URLs carry the subject's UUID, never its id.
*/

Route::middleware('web')->prefix('teacher')->name('grades.teacher.')->group(function () {
    Route::get('/', [TeacherSessionController::class, 'create'])->name('login');
    // Failed attempts are limited per address inside the controller (5 a
    // minute, 20 an hour); this outer limit stops floods of any kind.
    Route::post('/', [TeacherSessionController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('login.store');

    Route::middleware(TeacherSession::class)->group(function () {
        Route::post('logout', [TeacherSessionController::class, 'destroy'])->name('logout');

        Route::get('subjects', [TeacherMarksController::class, 'subjects'])->name('subjects');
        Route::get('subjects/{subject}/{section}', [TeacherMarksController::class, 'section'])
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('section');
        // Per teacher, not per address: a school's teachers may share one.
        Route::put('subjects/{subject}/{section}', [TeacherMarksController::class, 'save'])
            ->middleware('throttle:grades-teacher-save')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('section.save');
    });
});
