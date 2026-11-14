<?php

use App\Modules\Grades\Http\GradesAdminController;
use App\Modules\Grades\Http\ResultsController;
use App\Modules\Grades\Http\StudentsAdminController;
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

/*
| The owner's pages (users on the `web` guard, with their second factor):
| reading needs grades.view_results, every change grades.manage. The
| actions check the permission again inside their transactions.
*/
Route::middleware(['web', 'auth', 'verified'])->prefix('grades')->name('grades.admin.')->group(function () {
    Route::middleware('can:grades.view_results')->group(function () {
        Route::get('/', [GradesAdminController::class, 'overview'])->name('overview');
        Route::get('students', [StudentsAdminController::class, 'index'])->name('students');
        Route::get('results', [ResultsController::class, 'index'])->name('results');
        Route::get('results/print', [ResultsController::class, 'printSection'])->name('results.print');
        Route::get('results/export', [ResultsController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('results.export');
        Route::get('results/students/{student}', [ResultsController::class, 'printStudent'])
            ->whereUuid('student')
            ->name('results.student');
    });

    Route::middleware('can:grades.manage')->group(function () {
        Route::put('window', [GradesAdminController::class, 'window'])->name('window');

        Route::get('teachers', [GradesAdminController::class, 'teachers'])->name('teachers');
        Route::post('teachers', [GradesAdminController::class, 'storeTeacher'])->middleware('throttle:30,1')->name('teachers.store');
        Route::put('teachers/{teacher}', [GradesAdminController::class, 'updateTeacher'])
            ->middleware('throttle:60,1')
            ->whereUuid('teacher')
            ->name('teachers.update');

        Route::get('subjects', [GradesAdminController::class, 'subjects'])->name('subjects');
        Route::post('subjects', [GradesAdminController::class, 'storeSubject'])->middleware('throttle:60,1')->name('subjects.store');
        Route::put('subjects/{subject}', [GradesAdminController::class, 'updateSubject'])
            ->middleware('throttle:60,1')
            ->whereUuid('subject')
            ->name('subjects.update');

        // A teacher's sheet, corrected by the owner (at any time).
        Route::get('subjects/{subject}/{section}', [ResultsController::class, 'sheet'])
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('sheet');
        Route::put('subjects/{subject}/{section}', [ResultsController::class, 'saveSheet'])
            ->middleware('throttle:60,1')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('sheet.save');

        Route::post('students/import', [StudentsAdminController::class, 'upload'])->middleware('throttle:20,1')->name('students.upload');
        Route::get('students/import', [StudentsAdminController::class, 'preview'])->name('students.preview');
        Route::post('students/import/apply', [StudentsAdminController::class, 'apply'])->middleware('throttle:20,1')->name('students.apply');
        Route::delete('students/import', [StudentsAdminController::class, 'cancel'])->name('students.cancel');
    });
});

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
