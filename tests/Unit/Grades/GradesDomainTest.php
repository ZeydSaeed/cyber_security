<?php

use App\Modules\Grades\Domain\GradeBand;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Domain\SubjectScale;
use App\Modules\Grades\Domain\WindowStatus;

/*
| The Grades rules without the database (ADR-0034).
*/

describe('mark', function () {
    it('reads what a form sends', function (mixed $input, int|string|null $expected) {
        expect(Mark::fromInput($input)?->toScalar())->toBe($expected);
    })->with([
        'zero' => ['0', 0],
        'hundred' => ['100', 100],
        'with spaces' => [' 75 ', 75],
        'an integer' => [42, 42],
        'absent' => ['absent', 'absent'],
        'too high' => ['101', null],
        'negative' => ['-1', null],
        'a fraction' => ['12.5', null],
        'empty' => ['', null],
        'text' => ['abc', null],
        'four digits' => ['1000', null],
        'an array' => [[50], null],
        'null' => [null, null],
    ]);

    it('refuses a number outside 0 to 100', function (int $value) {
        Mark::of($value);
    })->with([-1, 101])->throws(InvalidArgumentException::class);

    it('counts absent as 0 and as not passed', function () {
        expect(Mark::absent()->points())->toBe(0)
            ->and(Mark::absent()->passes(50))->toBeFalse()
            ->and(Mark::of(50)->passes(50))->toBeTrue()
            ->and(Mark::of(49)->passes(50))->toBeFalse();
    });

    it('compares by value and absence', function () {
        expect(Mark::of(70)->equals(Mark::of(70)))->toBeTrue()
            ->and(Mark::of(70)->equals(Mark::of(71)))->toBeFalse()
            ->and(Mark::of(0)->equals(Mark::absent()))->toBeFalse()
            ->and(Mark::absent()->equals(Mark::absent()))->toBeTrue()
            ->and(Mark::of(70)->equals(null))->toBeFalse();
    });
});

describe('name key', function () {
    it('gives the same key to spellings of one name', function () {
        $with = "\u{0623}\u{062D}\u{0645}\u{062F}  \u{0639}\u{0644}\u{064A}\u{0651}"; // hamza, two spaces, shadda
        $without = "\u{0627}\u{062D}\u{0645}\u{062F} \u{0639}\u{0644}\u{064A}";

        expect(NameKey::of($with))->toBe(NameKey::of($without))
            ->and(NameKey::of("\u{0641}\u{0627}\u{0637}\u{0645}\u{0629}"))->toBe(NameKey::of("\u{0641}\u{0627}\u{0637}\u{0645}\u{0647}"))
            ->and(NameKey::of("Ali\u{200F} Hassan"))->toBe('ali hassan');
    });

    it('cleans a name for display without changing its letters', function () {
        expect(NameKey::clean("  \u{0623}\u{062D}\u{0645}\u{062F}\u{00A0}\u{00A0}Ali "))->toBe("\u{0623}\u{062D}\u{0645}\u{062F} Ali");
    });
});

/**
 * Subjects out of 100, passed at 50 (the defaults).
 *
 * @param  list<int>  $ids
 * @return array<int, SubjectScale>
 */
function scales(array $ids, int $max = 100, int $pass = 50, ?int $exempt = null): array
{
    return array_fill_keys($ids, new SubjectScale($max, $pass, $exempt));
}

function calculator(?int $exemptOverall = null): ResultCalculator
{
    return new ResultCalculator(GradeBands::defaults(), $exemptOverall);
}

describe('result', function () {
    it('totals, counts below the pass mark and absences, and knows what is missing', function () {
        $result = calculator()->for(scales([1, 2, 3, 4]), [
            1 => Mark::of(90),
            2 => Mark::of(49),
            3 => Mark::absent(),
        ]);

        expect($result->total)->toBe(139)
            ->and($result->maximum)->toBe(400)
            ->and($result->percentage)->toBe(34.75)
            ->and($result->belowPassMark)->toBe(2)
            ->and($result->absences)->toBe(1)
            ->and($result->missing)->toBe(1)
            ->and($result->isComplete())->toBeFalse()
            ->and($result->passedAll())->toBeFalse()
            ->and($result->marks[4])->toBeNull()
            ->and($result->band)->toBeNull();
    });

    it('passes a complete result with every subject at or above the pass mark', function () {
        $result = calculator()->for(scales([1, 2]), [1 => Mark::of(50), 2 => Mark::of(100)]);

        expect($result->passedAll())->toBeTrue()
            ->and($result->percentage)->toBe(75.0)
            ->and($result->band)->toBe(GradeBand::Good);
    });

    it('ignores marks of subjects outside the stage', function () {
        $result = calculator()->for(scales([1]), [1 => Mark::of(60), 9 => Mark::of(100)]);

        expect($result->total)->toBe(60)->and($result->maximum)->toBe(100);
    });

    it('marks each subject out of its own maximum, with its own pass mark', function () {
        $result = calculator()->for([1 => new SubjectScale(80, 40), 2 => new SubjectScale(100, 50)], [
            1 => Mark::of(72, 80),
            2 => Mark::of(45),
        ]);

        expect($result->maximum)->toBe(180)
            ->and($result->total)->toBe(117)
            ->and($result->percentage)->toBe(65.0)
            ->and($result->cells[1])->toBe(['percent' => 90.0, 'passed' => true, 'band' => GradeBand::Excellent, 'exempt' => false])
            ->and($result->cells[2]['band'])->toBe(GradeBand::Fail)
            // Failing a subject makes the overall grade Fail too.
            ->and($result->band)->toBe(GradeBand::Fail);
    });

    it('exempts a subject at its exemption mark, and a student at the general percentage', function () {
        $result = calculator(85)->for(scales([1, 2], 100, 50, 90), [1 => Mark::of(95), 2 => Mark::of(80)]);

        expect($result->cells[1]['exempt'])->toBeTrue()
            ->and($result->cells[2]['exempt'])->toBeFalse()
            ->and($result->percentage)->toBe(87.5)
            ->and($result->exemptAll)->toBeTrue();

        expect(calculator(90)->for(scales([1, 2]), [1 => Mark::of(95), 2 => Mark::of(80)])->exemptAll)->toBeFalse();
    });

    it('takes the department percentages for a subject without its own marks', function () {
        $scale = SubjectScale::from(80, null, null, 50, 85);

        expect($scale->pass)->toBe(40)->and($scale->exempt)->toBe(68);
    });

    it('refuses grade bands that do not fall', function () {
        GradeBands::of(['excellent' => 90, 'very_good' => 90, 'good' => 70, 'fair' => 60, 'pass' => 50]);
    })->throws(InvalidArgumentException::class);

    it('ranks complete results, equal percentages sharing a rank (1, 1, 3)', function () {
        $calc = calculator();
        $results = [
            'a' => $calc->for(scales([1]), [1 => Mark::of(80)]),
            'b' => $calc->for(scales([1]), [1 => Mark::of(90)]),
            'c' => $calc->for(scales([1]), [1 => Mark::of(80)]),
            'd' => $calc->for(scales([1]), [1 => Mark::of(70)]),
            'e' => $calc->for(scales([1]), []),
        ];

        expect(ResultCalculator::ranks($results))->toBe(['a' => 2, 'b' => 1, 'c' => 2, 'd' => 4, 'e' => null]);
    });
});

it('lets the entry window move only to the other state', function () {
    expect(WindowStatus::Open->canBecome(WindowStatus::Closed))->toBeTrue()
        ->and(WindowStatus::Closed->canBecome(WindowStatus::Open))->toBeTrue()
        ->and(WindowStatus::Open->canBecome(WindowStatus::Open))->toBeFalse();
});
