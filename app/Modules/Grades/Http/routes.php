<?php

use App\Modules\Grades\Http\AccessController;
use App\Modules\Grades\Http\AppealsController;
use App\Modules\Grades\Http\GradesAdminController;
use App\Modules\Grades\Http\ResultsController;
use App\Modules\Grades\Http\ResultSettingsController;
use App\Modules\Grades\Http\StudentPortalController;
use App\Modules\Grades\Http\StudentsAdminController;
use App\Modules\Grades\Http\StudentSession;
use App\Modules\Grades\Http\TeacherMarksController;
use App\Modules\Grades\Http\TeacherResultsController;
use App\Modules\Grades\Http\TeacherSession;
use App\Modules\Grades\Http\TeacherSessionController;
use Illuminate\Support\Facades\Route;

/*
| Routes of the Grades module (ADR-0034), loaded by its provider. Three
| links that differ by one word (owner's decision of 2026-11-15):
|
|   /admin    the owner (users on the `web` guard, second factor)
|   /teacher  teachers, with the code the owner sets
|   /student  students, with the code the application makes
|
| The three never share a guard. URLs carry UUIDs, never ids.
*/

/*
| The owner's pages: reading needs grades.view_results, every change
| grades.manage; the actions check the permission again inside their
| transactions. (/admin/users and /admin/reports are the platform's.)
*/
Route::middleware(['web', 'auth', 'verified'])->prefix('admin')->name('grades.admin.')->group(function () {
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
        Route::put('publish', [GradesAdminController::class, 'publish'])->name('publish');
        Route::put('appeals/{appeal}', [AppealsController::class, 'decide'])
            ->middleware('throttle:60,1')
            ->whereUuid('appeal')
            ->name('appeals.decide');
        Route::put('publish/section', [GradesAdminController::class, 'publishSection'])->middleware('throttle:60,1')->name('publish.section');

        // What a result says and shows (ADR-0039).
        Route::put('results/settings', [ResultSettingsController::class, 'settings'])->middleware('throttle:30,1')->name('results.settings');
        // What the student sees, and the appeals (ADR-0044).
        Route::put('results/student-view', [ResultSettingsController::class, 'studentView'])->middleware('throttle:30,1')->name('results.student_view');
        Route::put('results/teacher-view', [ResultSettingsController::class, 'teacherView'])->middleware('throttle:30,1')->name('results.teacher_view');
        Route::put('results/students/{student}/note', [ResultSettingsController::class, 'note'])
            ->middleware('throttle:120,1')
            ->whereUuid('student')
            ->name('results.note');

        // Who may sign in and change marks (ADR-0037).
        Route::put('access', [AccessController::class, 'settings'])->middleware('throttle:30,1')->name('access.settings');
        Route::put('access/teachers/{teacher}', [AccessController::class, 'teacher'])
            ->middleware('throttle:60,1')
            ->whereUuid('teacher')
            ->name('access.teacher');
        Route::post('access/teachers/{teacher}/reset', [AccessController::class, 'resetTeacherLogins'])
            ->middleware('throttle:30,1')
            ->whereUuid('teacher')
            ->name('access.teacher.reset');
        Route::delete('access/teachers/{teacher}/devices', [AccessController::class, 'forgetTeacherDevices'])
            ->middleware('throttle:30,1')
            ->whereUuid('teacher')
            ->name('access.teacher.devices');
        Route::delete('access/students/{student}/devices', [AccessController::class, 'forgetStudentDevices'])
            ->middleware('throttle:30,1')
            ->whereUuid('student')
            ->name('access.student.devices');
        Route::put('access/sections/{subject}/{section}', [AccessController::class, 'section'])
            ->middleware('throttle:60,1')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('access.section');
        Route::post('access/students/reset', [AccessController::class, 'resetStudentLogins'])->middleware('throttle:10,1')->name('access.students.reset');
        Route::put('access/students/{student}', [AccessController::class, 'student'])
            ->middleware('throttle:60,1')
            ->whereUuid('student')
            ->name('access.student');
        // One student's appeals, when they are for chosen students (ADR-0043).
        Route::put('access/students/{student}/appeal', [AccessController::class, 'studentAppeal'])
            ->middleware('throttle:60,1')
            ->whereUuid('student')
            ->name('access.student.appeal');
        Route::put('access/requests/{editRequest}', [AccessController::class, 'decide'])
            ->middleware('throttle:60,1')
            ->whereUuid('editRequest')
            ->name('access.request');

        Route::get('teachers', [GradesAdminController::class, 'teachers'])->name('teachers');
        Route::get('teachers/print', [GradesAdminController::class, 'printTeachers'])->name('teachers.print');
        Route::post('teachers', [GradesAdminController::class, 'storeTeacher'])->middleware('throttle:30,1')->name('teachers.store');
        Route::put('teachers/{teacher}', [GradesAdminController::class, 'updateTeacher'])
            ->middleware('throttle:60,1')
            ->whereUuid('teacher')
            ->name('teachers.update');
        Route::post('teachers/{teacher}/code', [GradesAdminController::class, 'regenerateTeacherCode'])
            ->middleware('throttle:30,1')
            ->whereUuid('teacher')
            ->name('teachers.code');
        Route::delete('teachers/{teacher}', [GradesAdminController::class, 'destroyTeacher'])
            ->middleware('throttle:30,1')
            ->whereUuid('teacher')
            ->name('teachers.destroy');

        Route::get('subjects', [GradesAdminController::class, 'subjects'])->name('subjects');
        Route::get('subjects/print', [GradesAdminController::class, 'printSubjects'])->name('subjects.print');
        Route::post('subjects', [GradesAdminController::class, 'storeSubject'])->middleware('throttle:60,1')->name('subjects.store');
        Route::put('subjects/{subject}', [GradesAdminController::class, 'updateSubject'])
            ->middleware('throttle:60,1')
            ->whereUuid('subject')
            ->name('subjects.update');
        Route::delete('subjects/{subject}', [GradesAdminController::class, 'destroySubject'])
            ->middleware('throttle:30,1')
            ->whereUuid('subject')
            ->name('subjects.destroy');

        // A teacher's sheet, corrected by the owner (at any time).
        Route::get('subjects/{subject}/{section}', [ResultsController::class, 'sheet'])
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('sheet');
        Route::delete('subjects/{subject}/{section}/approval', [ResultsController::class, 'withdrawApproval'])
            ->middleware('throttle:30,1')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('sheet.approval');
        Route::put('subjects/{subject}/{section}', [ResultsController::class, 'saveSheet'])
            ->middleware('throttle:60,1')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('sheet.save');

        // One student by hand.
        Route::post('students', [StudentsAdminController::class, 'store'])->middleware('throttle:60,1')->name('students.store');
        Route::put('students/{student}', [StudentsAdminController::class, 'update'])
            ->middleware('throttle:60,1')
            ->whereUuid('student')
            ->name('students.update');
        Route::delete('students/{student}', [StudentsAdminController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->whereUuid('student')
            ->name('students.destroy');

        // Students' codes: issue the missing ones, replace a lost one, print a section's.
        Route::post('students/codes', [StudentsAdminController::class, 'issueCodes'])->middleware('throttle:20,1')->name('students.codes.issue');
        Route::post('students/{student}/code', [StudentsAdminController::class, 'replaceCode'])
            ->middleware('throttle:30,1')
            ->whereUuid('student')
            ->name('students.code.replace');
        Route::get('students/codes', [StudentsAdminController::class, 'codes'])->name('students.codes');
        Route::get('students/print', [StudentsAdminController::class, 'printMenu'])->name('students.print');
    });
});

Route::middleware('web')->prefix('teacher')->name('grades.teacher.')->group(function () {
    Route::get('/', [TeacherSessionController::class, 'create'])->name('login');
    // Failed attempts are limited per address inside the controller (5 a
    // minute, 20 an hour); this outer limit stops floods of any kind. It
    // counts successful sign-ins too, and a school's teachers may share one
    // address: 30 a minute refused the 31st teacher when an entry window
    // opened (review load test, 2026-11-18), so it allows a whole staff.
    Route::post('/', [TeacherSessionController::class, 'store'])
        ->middleware('throttle:300,1')
        ->name('login.store');

    Route::middleware(TeacherSession::class)->group(function () {
        Route::post('logout', [TeacherSessionController::class, 'destroy'])->name('logout');

        Route::get('subjects', [TeacherMarksController::class, 'subjects'])->name('subjects');
        // Their own students' results, as the owner allows (ADR-0040).
        Route::get('results', [TeacherResultsController::class, 'show'])->name('results');
        // A whole subject: every section's sheet, one after another.
        Route::get('subjects/{subject}', [TeacherMarksController::class, 'subject'])
            ->whereUuid('subject')
            ->name('subject');
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
        Route::post('subjects/{subject}/{section}/approve', [TeacherMarksController::class, 'approve'])
            ->middleware('throttle:grades-teacher-action')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('section.approve');
        // Answering an appeal, when the owner allows it (ADR-0044).
        Route::put('appeals/{appeal}', [TeacherMarksController::class, 'answerAppeal'])
            ->middleware('throttle:grades-teacher-appeal')
            ->whereUuid('appeal')
            ->name('appeals.answer');
        Route::post('subjects/{subject}/{section}/request', [TeacherMarksController::class, 'requestEdit'])
            ->middleware('throttle:grades-teacher-action')
            ->whereUuid('subject')
            ->whereIn('section', ['A', 'B'])
            ->name('section.request');
    });
});

Route::middleware('web')->prefix('student')->name('grades.student.')->group(function () {
    Route::get('/', [StudentPortalController::class, 'create'])->name('login');
    // Failed attempts are limited per address inside the controller. This
    // flood limit counts successful sign-ins too; a class checking results
    // from the school's one address must not be refused (as for teachers).
    Route::post('/', [StudentPortalController::class, 'store'])
        ->middleware('throttle:300,1')
        ->name('login.store');

    Route::middleware(StudentSession::class)->group(function () {
        Route::post('logout', [StudentPortalController::class, 'destroy'])->name('logout');
        Route::get('result', [StudentPortalController::class, 'result'])->name('result');
        // Appealing a mark (ADR-0042).
        // Per student, not per address: a class may share the school's one.
        Route::post('appeals', [AppealsController::class, 'store'])->middleware('throttle:grades-student-appeal')->name('appeals.store');
    });
});
