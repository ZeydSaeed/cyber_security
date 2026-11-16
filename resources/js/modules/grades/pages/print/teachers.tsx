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
 * On paper (owner's requests, 2026-11-17): as many slips as fit on each A4
 * sheet, in two columns, with roomy margins and no page header. The list
 * uses the `slips` page (app.css), whose zero browser margin leaves no room
 * for the browser's own date, title, address and page number; the margins
 * are the list's own padding, repeated on every sheet.
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

    return (
        <>
            <Head title={t('Teachers')} />
            <PrintPage title={t('Teachers')} paper printHeader={false}>
                <ul
                    className="print-slips grid gap-3 sm:grid-cols-2 print:grid-cols-2 print:gap-[5mm]"
                    data-test="teacher-slips"
                >
                    {teachers.map((teacher) => (
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
                                <Field label={t('Degree')} />
                                <Field label={t('Specialty')}>
                                    <bdi>{teacher.specialty ?? '—'}</bdi>
                                </Field>
                                {(teacher.subjects.length === 0
                                    ? [null]
                                    : teacher.subjects
                                ).map((subject) => (
                                    <div
                                        key={
                                            subject
                                                ? `${subject.name}-${subject.stage}`
                                                : 'none'
                                        }
                                        className="mt-1 grid gap-1 border-t border-dashed border-print-ink pt-1"
                                        data-test="slip-subject"
                                    >
                                        <Field label={t('Subject taught')} />
                                        <div className="flex flex-wrap items-center gap-x-6 gap-y-1">
                                            <Tick label={t('Practical')} />
                                            <Tick label={t('Theoretical')} />
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
                                            label={t('Assistant teacher name')}
                                        />
                                    </div>
                                ))}
                            </div>
                            <p className="mt-2 text-sm print:mt-1 print:text-[11px]">
                                {t('Open :link and type your code:', {
                                    link: teacherLink,
                                })}
                            </p>
                            {teacher.code ? (
                                <p
                                    className="mt-1 font-mono text-lg tracking-widest print:mt-0.5 print:text-base"
                                    dir="ltr"
                                    data-test="slip-code"
                                >
                                    {teacher.code}
                                </p>
                            ) : (
                                <p className="mt-1">{t('No code yet')}</p>
                            )}
                        </li>
                    ))}
                </ul>
            </PrintPage>
        </>
    );
}

/** A label and its value; with no value, the space is filled in by hand. */
function Field({ label, children }: { label: string; children?: ReactNode }) {
    return (
        <span className="inline-flex items-baseline gap-1">
            <span className="font-semibold">{label}:</span>
            {children}
        </span>
    );
}

/** An empty box, ticked by hand on paper. */
function Tick({ label }: { label: string }) {
    return (
        <span className="inline-flex items-center gap-1">
            <span
                className="inline-block size-4 rounded-sm border-2 border-print-ink"
                aria-hidden
            />
            {label}
        </span>
    );
}
