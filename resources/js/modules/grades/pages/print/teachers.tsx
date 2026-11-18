import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import { stageLabel } from '../../place';

type TeacherSlip = {
    uuid: string;
    name: string;
    specialty: string | null;
    code: string | null;
    subjects: {
        name: string;
        stage: number;
        divided: boolean;
        sections: string[];
        /** "theory", "practical", or null: ticked by hand. */
        kind: 'theory' | 'practical' | null;
        /** The assistant's name, or null: written by hand. */
        assistant: string | null;
    }[];
};

/**
 * Every teacher on a slip, to print and cut (owner's request, 2026-11-16),
 * like the students' code slips: black text in a black frame, then the link
 * and the code. Opening this page is recorded in the audit trail.
 *
 * Layout (owner's requests, 2026-11-17): the teacher's name, the degree
 * (left blank, written by hand), the specialty, then for each subject:
 * "Subject taught" left blank, the Practical and Theoretical boxes under
 * it, the stage and the section on one line, far apart, and under them
 * "Assistant teacher name" left blank.
 *
 * Filled in from the teacher's form (owner's request, 2026-11-18): the box
 * of the type chosen is ticked and the assistant's name written; what was
 * not chosen stays blank for the pen. Sections taught the same way share
 * one block, so a block may name one section of a subject.
 *
 * On paper (owner's requests, 2026-11-17): as many slips as fit on each A4
 * sheet, in two columns, with roomy margins and no page header. The list
 * uses the `slips` page (app.css), whose zero browser margin leaves no room
 * for the browser's own date, title, address and page number; the margins
 * are the list's own padding, repeated on every sheet.
 *
 * The link and the code sit in their own dashed frame, to be cut off the
 * slip after printing, with the code labelled "Teacher ID:" (owner's
 * request, 2026-11-17).
 *
 * Eight slips on each A4 sheet, two columns by four rows, and the degree
 * and the specialty on one line, far apart (owner's requests, 2026-11-17).
 * Each sheet is its own list of at most eight, one A4 page high.
 */
export default function PrintTeachers({
    teacherLink,
    teachers,
}: {
    teacherLink: string;
    teachers: TeacherSlip[];
}) {
    const { t, locale } = useTranslations();
    const list = new Intl.ListFormat(locale === 'ar' ? 'ar' : 'en', {
        type: 'conjunction',
    });
    const sheets: TeacherSlip[][] = [];

    for (let i = 0; i < teachers.length; i += SLIPS_PER_SHEET) {
        sheets.push(teachers.slice(i, i + SLIPS_PER_SHEET));
    }

    return (
        <>
            <Head title={t('Teachers')} />
            <PrintPage title={t('Teachers')} paper printHeader={false}>
                <div
                    className="grid gap-3 print:block"
                    data-test="teacher-slips"
                >
                    {sheets.map((sheet, index) => (
                        <ul
                            key={sheet[0].uuid}
                            className={`print-slips grid gap-3 sm:grid-cols-2 print:h-[297mm] print:grid-cols-2 print:grid-rows-4 print:gap-[5mm] ${index < sheets.length - 1 ? 'print:break-after-page' : ''}`}
                            data-test="slip-sheet"
                        >
                            {sheet.map((teacher) => (
                                <li
                                    key={teacher.uuid}
                                    className="break-inside-avoid rounded-md border-2 border-print-ink p-3 text-print-ink print:p-[3mm]"
                                    data-test="teacher-slip"
                                >
                                    <div className="grid gap-1 text-sm print:gap-0.5 print:text-[11px]">
                                        <Field label={t('Teacher name')}>
                                            <bdi className="text-base font-medium print:text-[13px]">
                                                {teacher.name}
                                            </bdi>
                                        </Field>
                                        <div className="flex flex-wrap gap-x-32 gap-y-1">
                                            <Field label={t('Degree')} />
                                            <Field label={t('Specialty')}>
                                                <bdi>
                                                    {teacher.specialty ?? '—'}
                                                </bdi>
                                            </Field>
                                        </div>
                                        {(teacher.subjects.length === 0
                                            ? [null]
                                            : teacher.subjects
                                        ).map((subject) => (
                                            <div
                                                key={
                                                    subject
                                                        ? `${subject.name}-${subject.stage}-${subject.sections.join('')}`
                                                        : 'none'
                                                }
                                                className="mt-1 grid gap-1 border-t border-dashed border-print-ink pt-1"
                                                data-test="slip-subject"
                                            >
                                                <Field
                                                    label={t('Subject taught')}
                                                />
                                                <div className="flex flex-wrap items-center gap-x-6 gap-y-1">
                                                    <Tick
                                                        label={t('Practical')}
                                                        ticked={
                                                            subject?.kind ===
                                                            'practical'
                                                        }
                                                    />
                                                    <Tick
                                                        label={t('Theoretical')}
                                                        ticked={
                                                            subject?.kind ===
                                                            'theory'
                                                        }
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-x-32 gap-y-1">
                                                    <Field label={t('Stage')}>
                                                        {subject
                                                            ? stageLabel(
                                                                  t,
                                                                  subject.stage,
                                                              )
                                                            : '—'}
                                                    </Field>
                                                    <Field label={t('Section')}>
                                                        {subject?.divided
                                                            ? list.format(
                                                                  subject.sections,
                                                              )
                                                            : '—'}
                                                    </Field>
                                                </div>
                                                <Field
                                                    label={t(
                                                        'Assistant teacher name',
                                                    )}
                                                >
                                                    {subject?.assistant && (
                                                        <bdi data-test="slip-assistant">
                                                            {subject.assistant}
                                                        </bdi>
                                                    )}
                                                </Field>
                                            </div>
                                        ))}
                                    </div>
                                    <div
                                        className="mt-2 border-2 border-dashed border-print-ink p-2 text-sm print:mt-1.5 print:p-[2mm] print:text-[11px]"
                                        data-test="slip-cut"
                                    >
                                        <p>
                                            {t(
                                                'Open :link and type your code:',
                                                {
                                                    link: teacherLink,
                                                },
                                            )}
                                        </p>
                                        <p className="mt-1 flex flex-wrap items-baseline gap-x-2 print:mt-0.5">
                                            <span className="font-semibold">
                                                {t('Teacher ID:')}
                                            </span>
                                            {teacher.code ? (
                                                <span
                                                    className="font-mono text-lg tracking-widest print:text-base"
                                                    dir="ltr"
                                                    data-test="slip-code"
                                                >
                                                    {teacher.code}
                                                </span>
                                            ) : (
                                                <span>{t('No code yet')}</span>
                                            )}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ))}
                </div>
            </PrintPage>
        </>
    );
}

/** Two columns by four rows on each A4 sheet (owner, 2026-11-17). */
const SLIPS_PER_SHEET = 8;

/** A label and its value; with no value, the space is filled in by hand. */
function Field({ label, children }: { label: string; children?: ReactNode }) {
    return (
        <span className="inline-flex items-baseline gap-1">
            <span className="font-semibold">{label}:</span>
            {children}
        </span>
    );
}

/**
 * A box, ticked when the type was chosen in the teacher's form, otherwise
 * left empty to tick by hand on paper.
 */
function Tick({ label, ticked }: { label: string; ticked: boolean }) {
    return (
        <span
            className="inline-flex items-center gap-1"
            data-test={ticked ? 'slip-ticked' : undefined}
        >
            <span
                className="inline-flex size-4 items-center justify-center rounded-sm border-2 border-print-ink text-xs leading-none font-bold"
                aria-hidden
            >
                {ticked ? '✓' : ''}
            </span>
            {label}
            {ticked && <span className="sr-only">(✓)</span>}
        </span>
    );
}
