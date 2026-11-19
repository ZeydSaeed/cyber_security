import { useTranslations } from '@/hooks/use-translations';
import { stageName } from '../place';
import {
    bandLabel,
    behaviourLabel,
    kindLabel,
    markTone,
    toneClass,
} from '../result-words';
import type { ResultCardData, ResultRow } from '../types';

/**
 * One student's result (ADR-0034, ADR-0039): each subject with its mark
 * (out of the subject's maximum), whether it is passed, and, as the owner
 * chose, its grade, exemption, teacher and type; then the total, the
 * percentage, the rank in the section, the overall grade, the general
 * exemption, behaviour and notes. On the owner's printable card and on the
 * student's own page alike. A coloured mark always has words beside it.
 */
export default function ResultCard({
    card,
    showName = true,
    code,
}: {
    card: ResultCardData;
    /** The student's own page shows the name at the top already. */
    showName?: boolean;
    /**
     * On the owner's printable card: the name, stage, section and code
     * spread across one line (owner's request, 2026-11-16).
     */
    code?: string | null;
}) {
    const { t } = useTranslations();
    const { student, subjects, options, tones } = card;
    const printable = code !== undefined;

    const status = (i: number) => {
        const mark = student.marks[i] ?? null;
        const cell = student.cells[i] ?? null;
        if (mark === null || cell === null) {
            return (
                <span className="text-muted-foreground">
                    {t('Not entered yet')}
                </span>
            );
        }
        const tone = toneClass(markTone(mark, cell, tones));
        const words: string[] = [
            mark === 'absent'
                ? t('Absent')
                : cell.passed
                  ? t('Passed')
                  : t('Below the pass mark'),
        ];
        if (options.mark_grade && cell.band && mark !== 'absent') {
            words.push(bandLabel(t, cell.band));
        }
        if (options.exemption && cell.exempt) {
            words.push(t('Exempt'));
        }

        return <span className={tone}>{words.join(' · ')}</span>;
    };

    const facts: { label: string; value: string; test: string }[] = [];
    if (options.absences && student.absences !== null) {
        facts.push({
            label: t('Absences'),
            value: t(':count subjects', { count: String(student.absences) }),
            test: 'card-absences',
        });
    }
    if (options.rank && student.rank !== null) {
        facts.push({
            label: t('Rank in the section'),
            value: String(student.rank),
            test: 'card-rank',
        });
    }
    if (options.overall_grade && student.band !== null) {
        facts.push({
            label: t('Overall grade'),
            value: bandLabel(t, student.band),
            test: 'card-grade',
        });
    }
    if (options.failed_count && student.below !== null && student.complete) {
        facts.push({
            label: t('Failed subjects'),
            value: String(student.below),
            test: 'card-failed',
        });
    }
    if (options.exemption && student.exempt_all) {
        facts.push({
            label: t('General exemption'),
            value: t('Exempt from every subject'),
            test: 'card-exempt',
        });
    }
    if (options.student_status) {
        facts.push({
            label: t('Student status'),
            value: student.status === 'external' ? t('External') : t('Regular'),
            test: 'card-status',
        });
    }
    if (options.behaviour && student.behaviour !== null) {
        facts.push({
            label: t('Behaviour'),
            value: behaviourLabel(t, student.behaviour),
            test: 'card-behaviour',
        });
    }

    const markText = (row: ResultRow, i: number) => {
        const mark = row.marks[i] ?? null;

        if (mark === null) {
            // The printable card leaves an unentered mark blank; its status
            // says so (owner's request, 2026-11-19).
            return printable ? '' : '—';
        }

        return mark === 'absent' ? t('Absent') : mark;
    };

    return (
        <>
            {printable ? (
                <dl
                    className="mb-6 flex flex-wrap items-baseline justify-between gap-x-8 gap-y-2"
                    data-test="card-details"
                >
                    {[
                        {
                            label: t('Name'),
                            value: student.name,
                            test: 'card-name',
                        },
                        { label: t('Stage'), value: stageName(t, card.stage) },
                        // An undivided stage has no section to name
                        // (owner's request, 2026-11-19).
                        ...(card.divided
                            ? [{ label: t('Section'), value: card.section }]
                            : []),
                        {
                            label: t('Student ID'),
                            value: code ?? student.number ?? '—',
                        },
                    ].map((item) => (
                        <div key={item.label} className="flex gap-2">
                            <dt className="font-semibold">{item.label}:</dt>
                            <dd data-test={item.test}>
                                <bdi>{item.value}</bdi>
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <dl className="mb-6 grid gap-x-6 gap-y-2 empty:hidden sm:grid-cols-2">
                    {showName && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Name')}
                            </dt>
                            <dd
                                className="text-lg font-semibold"
                                data-test="card-name"
                            >
                                <bdi>{student.name}</bdi>
                            </dd>
                        </div>
                    )}
                    {student.number && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Number')}
                            </dt>
                            <dd className="text-lg">
                                <bdi>{student.number}</bdi>
                            </dd>
                        </div>
                    )}
                </dl>
            )}

            <div
                className="overflow-x-auto"
                tabIndex={0}
                role="region"
                aria-label={t('Result card')}
            >
                <table
                    className="w-full border-collapse border-2 border-grid-line bg-sheet text-sm"
                    data-test="card-table"
                >
                    <caption className="sr-only">{t('Result card')}</caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-start font-medium"
                            >
                                {t('Subject')}
                            </th>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-center font-medium"
                            >
                                {t('Mark')}
                            </th>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-center font-medium"
                            >
                                {t('Status')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {subjects.map((subject, i) => {
                            const details = [
                                options.kind && subject.kind
                                    ? kindLabel(t, subject.kind)
                                    : null,
                                options.teachers && subject.teacher
                                    ? subject.assistant
                                        ? t(
                                              ':teacher, assisted by :assistant',
                                              {
                                                  teacher: subject.teacher,
                                                  assistant: subject.assistant,
                                              },
                                          )
                                        : subject.teacher
                                    : null,
                            ].filter((item): item is string => item !== null);

                            return (
                                <tr key={subject.uuid} data-test="card-row">
                                    <th
                                        scope="row"
                                        className="border border-grid-line p-2 text-start font-normal"
                                    >
                                        <bdi>{subject.name}</bdi>
                                        {details.length > 0 && (
                                            <span
                                                className="block text-xs text-muted-foreground"
                                                data-test="card-subject-details"
                                            >
                                                <bdi>{details.join(' · ')}</bdi>
                                            </span>
                                        )}
                                    </th>
                                    <td className="border border-grid-line p-2 text-center tabular-nums">
                                        {markText(student, i)}
                                        {/* The printable card shows the mark
                                            alone (owner's request, 2026-11-19). */}
                                        {options.max_mark && !printable && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                / {subject.max}
                                            </span>
                                        )}
                                    </td>
                                    <td className="border border-grid-line p-2 text-center">
                                        {status(i)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    {(options.total || options.percentage) &&
                        student.total !== null && (
                            <tfoot>
                                <tr className="font-semibold">
                                    <th
                                        scope="row"
                                        className="border border-grid-line p-2 text-start"
                                    >
                                        {t('Total')}
                                    </th>
                                    <td
                                        className="border border-grid-line p-2 text-center tabular-nums"
                                        data-test="card-total"
                                    >
                                        {/* The maximum only when the owner
                                            shows it, as on each subject. */}
                                        {options.total
                                            ? options.max_mark
                                                ? `${student.total} / ${student.maximum}`
                                                : student.total
                                            : '—'}
                                    </td>
                                    <td
                                        className="border border-grid-line p-2 text-center"
                                        data-test="card-percentage"
                                    >
                                        {!student.complete
                                            ? t(
                                                  'Incomplete (:missing missing)',
                                                  {
                                                      missing: String(
                                                          student.missing,
                                                      ),
                                                  },
                                              )
                                            : options.percentage &&
                                                student.percentage !== null
                                              ? `${student.percentage.toFixed(2)}%`
                                              : '—'}
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                </table>
            </div>

            {facts.length > 0 && (
                <dl
                    className="mt-6 grid gap-x-6 gap-y-2 sm:grid-cols-2"
                    data-test="card-facts"
                >
                    {facts.map((fact) => (
                        <div key={fact.test} className="flex gap-2">
                            <dt className="font-semibold">{fact.label}:</dt>
                            <dd data-test={fact.test}>{fact.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {options.notes && student.notes && (
                <div className="mt-4" data-test="card-notes">
                    <p className="font-semibold">{t('Notes')}</p>
                    <p className="text-sm whitespace-pre-line" dir="auto">
                        {student.notes}
                    </p>
                </div>
            )}

            <p className="mt-6 text-base font-medium" data-test="card-outcome">
                {!student.complete
                    ? t('Some marks are not entered yet.')
                    : student.passed
                      ? t('Passed every subject.')
                      : // The count only when the owner shows it.
                        student.below === null
                        ? t('Not every subject is passed.')
                        : t('Below the pass mark in :count subjects.', {
                              count: String(student.below),
                          })}
            </p>
            <p className="mt-2 text-sm text-muted-foreground">
                {t(
                    'Each subject is passed at :percent% of its maximum unless set otherwise. An absence counts as 0.',
                    { percent: String(card.pass_percent) },
                )}
            </p>
        </>
    );
}
