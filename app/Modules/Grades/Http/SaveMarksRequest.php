<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Domain\Mark;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A section's marks as the page sends them (ADR-0034): student UUID =>
 * a whole number from 0 to 100, "absent", or empty (no mark). Each wrong
 * value is reported beside its student (marks.<uuid>).
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
                    if ($value !== null && trim((string) $value) !== '' && Mark::fromInput($value) === null) {
                        $fail(__('Enter a whole number from 0 to 100, or mark the student absent.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, Mark|null> student UUID => mark (null: no mark)
     */
    public function marks(): array
    {
        $marks = [];

        foreach ((array) $this->input('marks') as $uuid => $value) {
            $marks[(string) $uuid] = $value === null || trim((string) $value) === '' ? null : Mark::fromInput($value);
        }

        return $marks;
    }
}
