<?php

use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
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

describe('stage and section', function () {
    it('reads a stage as people write it', function (string|int $text, ?Stage $stage) {
        expect(Stage::fromText($text))->toBe($stage);
    })->with([
        [1, Stage::First],
        ['2', Stage::Second],
        ['first', Stage::First],
        ['Second', Stage::Second],
        ["\u{0627}\u{0644}\u{0623}\u{0648}\u{0644}", Stage::First], // the first
        ["\u{0627}\u{0644}\u{0635}\u{0641} \u{0627}\u{0644}\u{062B}\u{0627}\u{0646}\u{064A}", Stage::Second], // the second year
        ["\u{0627}\u{0644}\u{0645}\u{0631}\u{062D}\u{0644}\u{0629} \u{0627}\u{0644}\u{0623}\u{0648}\u{0644}\u{0649}", Stage::First], // the first stage
        ['3', null],
        ['', null],
    ]);

    it('reads a section as people write it', function (string $text, ?Section $section) {
        expect(Section::fromText($text))->toBe($section);
    })->with([
        ['A', Section::A],
        ['b', Section::B],
        ['Section A', Section::A],
        ["\u{0623}", Section::A], // alef with hamza
        ["\u{0634}\u{0639}\u{0628}\u{0629} \u{0628}", Section::B], // section beh
        ['C', null],
        ['', null],
    ]);
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

describe('result', function () {
    it('totals, counts below the pass mark and absences, and knows what is missing', function () {
        $result = (new ResultCalculator(50))->for([1, 2, 3, 4], [
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
            ->and($result->marks[4])->toBeNull();
    });

    it('passes a complete result with every subject at or above the pass mark', function () {
        $result = (new ResultCalculator(50))->for([1, 2], [1 => Mark::of(50), 2 => Mark::of(100)]);

        expect($result->passedAll())->toBeTrue()
            ->and($result->percentage)->toBe(75.0);
    });

    it('ignores marks of subjects outside the stage', function () {
        $result = (new ResultCalculator(50))->for([1], [1 => Mark::of(60), 9 => Mark::of(100)]);

        expect($result->total)->toBe(60)->and($result->maximum)->toBe(100);
    });
});

it('lets the entry window move only to the other state', function () {
    expect(WindowStatus::Open->canBecome(WindowStatus::Closed))->toBeTrue()
        ->and(WindowStatus::Closed->canBecome(WindowStatus::Open))->toBeTrue()
        ->and(WindowStatus::Open->canBecome(WindowStatus::Open))->toBeFalse();
});
