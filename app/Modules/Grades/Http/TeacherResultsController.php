<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher reads their own students' results (ADR-0040): the sections
 * they teach (a section blocked for them is not theirs), only when the
 * owner allows it (never, once published, or always), and only what the
 * owner chose they see. Another section is a 404, as on the mark sheets.
 * Each reading is audited, with no actor (a teacher is not a user).
 */
class TeacherResultsController
{
    public function __construct(private readonly ResultSheets $sheets, private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $teacher = Auth::guard(Teacher::GUARD)->user();
        if (! $teacher instanceof Teacher) {
            throw new NotFoundHttpException;
        }

        $settings = TeacherSession::settings($request);
        $portal = ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')];

        // The teacher's sections, each with the subjects taught there.
        $rows = SubjectTeacher::query()
            ->with('subject:id,uuid,stage')
            ->where('teacher_id', $teacher->id)
            ->get()
            ->reject(fn (SubjectTeacher $row): bool => $row->subject === null || SectionAccess::hidden($teacher, $row, $settings));

        $places = [];
        foreach ($rows as $row) {
            $subject = $row->subject;
            if ($subject === null) {
                continue;
            }
            $key = $subject->stage->value.$row->section->value;
            $places[$key] ??= ['stage' => $subject->stage->value, 'section' => $row->section->value, 'divided' => $subject->stage->isDivided(), 'subjects' => []];
            $places[$key]['subjects'][] = $subject->uuid;
        }
        ksort($places);

        $window = GradePeriod::for(Period::Month1);
        // Allowed at all: always, or once any section is published (ADR-0042).
        $allowed = $settings->teacher_results->allows($window->anyPublished());

        if (! $allowed || $places === []) {
            return Inertia::render('modules/grades/pages/teacher/results', [
                'portal' => $portal,
                'allowed' => $allowed,
                'places' => array_values(array_map(fn (array $place): array => array_diff_key($place, ['subjects' => true]), $places)),
                'sheet' => null,
            ]);
        }

        // The place asked for, or the first; one that is not theirs is not found.
        $asked = $request->filled('stage') ? $request->integer('stage').$request->string('section') : array_key_first($places);
        $place = $places[$asked] ?? throw new NotFoundHttpException;

        // Once published: this section's results, not another's.
        if (! $settings->teacher_results->allows($window->publishedFor(Stage::from($place['stage']), Section::from($place['section'])))) {
            return Inertia::render('modules/grades/pages/teacher/results', [
                'portal' => $portal,
                'allowed' => false,
                'places' => array_values(array_map(fn (array $place): array => array_diff_key($place, ['subjects' => true]), $places)),
                'sheet' => null,
            ]);
        }

        $sheet = $this->sheets->forTeacher(Stage::from($place['stage']), Section::from($place['section']), $place['subjects'], $settings->teacherView());
        $this->audit->record('grades.teacher_results_viewed', $teacher, ['stage' => $place['stage'], 'section' => $place['section']], withoutActor: true);

        return Inertia::render('modules/grades/pages/teacher/results', [
            'portal' => $portal,
            'allowed' => true,
            'places' => array_values(array_map(fn (array $place): array => array_diff_key($place, ['subjects' => true]), $places)),
            'sheet' => $sheet,
        ]);
    }
}
