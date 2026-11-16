import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import EmptyState from '@/components/empty-state';
import { useTranslations } from '@/hooks/use-translations';
import WindowNotice from '../../components/window-notice';
import { sectionLabel, stageLabel } from '../../place';
import type { TeacherSubject } from '../../types';

/**
 * A teacher's subjects (ADR-0034), each with its two sections and how
 * many students already have a mark.
 */
export default function TeacherSubjects({
    open,
    subjects,
}: {
    open: boolean;
    subjects: TeacherSubject[];
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Your subjects')} />
            <WindowNotice open={open} />

            {subjects.length === 0 ? (
                <EmptyState
                    title={t('No subjects yet')}
                    description={t(
                        'The department has not given you a subject yet.',
                    )}
                />
            ) : (
                <ul
                    className="flex flex-col gap-4"
                    data-test="teacher-subjects"
                >
                    {subjects.map((subject) => (
                        <li
                            key={subject.uuid}
                            // Soft highlight cards (owner's request, 2026-11-15).
                            className="rounded-lg border border-highlight-soft-border bg-highlight-soft bg-linear-to-b from-highlight-soft to-highlight-soft-end p-4 text-ink"
                        >
                            <h3 className="font-medium">
                                <bdi>{subject.name}</bdi>
                            </h3>
                            <p className="mb-3 text-sm text-muted-foreground">
                                {stageLabel(t, subject.stage)}
                            </p>
                            <ul className="flex flex-col gap-2">
                                {subject.sections.map((section) => {
                                    const done =
                                        section.students > 0 &&
                                        section.entered === section.students;

                                    return (
                                        <li key={section.section}>
                                            <Link
                                                href={TeacherMarksController.section.url(
                                                    {
                                                        subject: subject.uuid,
                                                        section:
                                                            section.section,
                                                    },
                                                )}
                                                className="flex min-h-12 items-center gap-3 rounded-md border px-3 py-2 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                data-test={`section-${section.section}`}
                                            >
                                                <span className="flex-1 font-medium">
                                                    {sectionLabel(
                                                        t,
                                                        section.section,
                                                        subject.divided,
                                                    )}
                                                </span>
                                                <span
                                                    className={
                                                        done
                                                            ? 'text-sm text-success-text'
                                                            : 'text-sm text-muted-foreground'
                                                    }
                                                >
                                                    {t(
                                                        ':entered of :students entered',
                                                        {
                                                            entered: String(
                                                                section.entered,
                                                            ),
                                                            students: String(
                                                                section.students,
                                                            ),
                                                        },
                                                    )}
                                                </span>
                                                <ChevronRight
                                                    className="size-4 text-muted-foreground rtl:-scale-x-100"
                                                    aria-hidden
                                                />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
