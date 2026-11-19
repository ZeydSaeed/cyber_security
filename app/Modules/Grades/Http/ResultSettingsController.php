<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\GradeBand;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Models\Student;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * What a result says and shows (ADR-0039): the department's settings on
 * the overview, and a student's behaviour and notes on the results page.
 * The routes check grades.manage; the action checks it again.
 */
class ResultSettingsController
{
    public function __construct(private readonly ManageResults $results) {}

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $percent = ['integer', 'min:1', 'max:100'];
        $bands = array_map(fn (GradeBand $band): string => $band->value, GradeBand::ranked());
        $request->validate([
            'pass_percent' => ['required', ...$percent],
            'exempt_subject_percent' => ['nullable', ...$percent],
            'exempt_overall_percent' => ['nullable', ...$percent],
            'bands' => ['required', 'array:'.implode(',', $bands)],
            'bands.*' => ['required', 'integer', 'min:0', 'max:100'],
            // Absent when none is ticked.
            'options' => ['sometimes', 'array'],
            'options.*' => [Rule::in(array_keys(ResultDisplay::OPTIONS))],
            'tones' => ['required', 'array:'.implode(',', array_keys(ResultDisplay::TONED))],
            'tones.*' => ['required', Rule::in(ResultDisplay::TONES)],
        ]);

        // The options come as the list of those ticked.
        $shown = array_map('strval', (array) $request->input('options', []));
        $options = [];
        foreach (array_keys(ResultDisplay::OPTIONS) as $option) {
            $options[$option] = in_array($option, $shown, true);
        }

        $this->results->settings(
            $user,
            $request->integer('pass_percent'),
            $request->filled('exempt_subject_percent') ? $request->integer('exempt_subject_percent') : null,
            $request->filled('exempt_overall_percent') ? $request->integer('exempt_overall_percent') : null,
            array_map('intval', (array) $request->input('bands')),
            $options,
            array_map('strval', (array) $request->input('tones')),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Result settings saved.')]);

        return back();
    }

    public function note(Request $request, Student $student, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'behaviour' => ['nullable', Rule::enum(Behaviour::class)],
            'notes' => ['nullable', 'string', 'max:'.ManageResults::MAX_NOTES],
        ]);

        $this->results->note(
            $user,
            $student,
            Period::Month1,
            $request->filled('behaviour') ? Behaviour::from((string) $request->string('behaviour')) : null,
            $request->filled('notes') ? (string) $request->string('notes') : null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Behaviour and notes saved.')]);

        return back();
    }
}
