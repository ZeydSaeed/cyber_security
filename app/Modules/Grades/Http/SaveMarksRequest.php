<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Models\Subject;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A section's marks as the page sends them (ADR-0034): student UUID =>
 * a whole number from 0 to the subject's maximum (ADR-0039), "absent", or
 * empty (no mark). Each wrong value is reported beside its student
 * (marks.<uuid>).
 */
class SaveMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's TeacherSession middleware and SaveMarks decide.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'max:500'],
            'marks.*' => [
                'nullable',
                'string',
                'max:6',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && trim((string) $value) !== '' && Mark::fromInput($value, $this->maximum()) === null) {
                        $fail(__('Enter a whole number from 0 to :max, or mark the student absent.', ['max' => $this->maximum()]));
                    }
                },
            ],
        ];
    }

    /** The subject's maximum (the route's subject). */
    private function maximum(): int
    {
        $subject = $this->route('subject');

        return $subject instanceof Subject ? $subject->max_mark : Mark::MAX;
    }

    /**
     * @return array<string, Mark|null> student UUID => mark (null: no mark)
     */
    public function marks(): array
    {
        $marks = [];

        foreach ((array) $this->input('marks') as $uuid => $value) {
            $marks[(string) $uuid] = $value === null || trim((string) $value) === '' ? null : Mark::fromInput($value, $this->maximum());
        }

        return $marks;
    }
}
