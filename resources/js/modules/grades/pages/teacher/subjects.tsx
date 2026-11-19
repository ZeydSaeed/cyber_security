import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import TeacherResultsController from '@/actions/App/Modules/Grades/Http/TeacherResultsController';
import EmptyState from '@/components/empty-state';
import { useTranslations } from '@/hooks/use-translations';
import { useAccessLabel } from '../../components/access-notice';
import TeacherSummary from '../../components/teacher-summary';
import WindowNotice from '../../components/window-notice';
import { sectionLabel, stageLabel } from '../../place';
import type { TeacherSubject } from '../../types';

/**
 * A teacher's subjects (ADR-0034), each with its sections and how many
 * students already have a mark; a subject opens on all its students.
 */
export default function TeacherSubjects({
    open,
    subjects,
}: {
    open: boolean;
    subjects: TeacherSubject[];
}) {
    const { t } = useTranslations();
    const accessLabel = useAccessLabel();

    return (
        <>
            <Head title={t('Your subjects')} />
            <WindowNotice open={open} />
            {/* What needs doing, at a glance (ADR-0045). */}
            <TeacherSummary subjects={subjects} />
            {/* Their own students' results, when the department allows (ADR-0040). */}
            <Link
                prefetch
                href={TeacherResultsController.show.url()}
                className="mb-6 flex min-h-12 items-center gap-3 rounded-md border bg-card px-3 py-2 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="teacher-results-link"
            >
                <span className="flex-1 font-medium">
                    {t("Your students' results")}
                </span>
                <ChevronRight
                    className="size-4 text-muted-foreground rtl:-scale-x-100"
                    aria-hidden
                />
            </Link>

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
                        <li key={subject.uuid}>
                            {/* One link per subject: its page shows the
                                students straight away, section A then B
                                (owner's request, 2026-11-19). */}
                            <Link
                                prefetch
                                href={TeacherMarksController.subject.url(
                                    subject.uuid,
                                )}
                                // Soft highlight cards (owner's request, 2026-11-15).
                                className="flex items-center gap-3 rounded-lg border border-highlight-soft-border bg-highlight-soft bg-linear-to-b from-highlight-soft to-highlight-soft-end p-4 text-ink hover:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                data-test={`subject-${subject.uuid}`}
                            >
                                <span className="flex-1">
                                    <span className="block font-medium">
                                        <bdi>{subject.name}</bdi>
                                    </span>
                                    <span className="mb-2 block text-sm text-muted-foreground">
                                        {stageLabel(t, subject.stage)}
                                    </span>
                                    <span className="flex flex-col gap-1">
                                        {subject.sections.map((section) => {
                                            const done =
                                                section.students > 0 &&
                                                section.entered ===
                                                    section.students;

                                            return (
                                                <span
                                                    key={section.section}
                                                    className="block text-sm"
                                                    data-test={`section-${section.section}`}
                                                >
                                                    <span className="font-medium">
                                                        {sectionLabel(
                                                            t,
                                                            section.section,
                                                            subject.divided,
                                                        )}
                                                    </span>
                                                    {' · '}
                                                    <span
                                                        className={
                                                            done
                                                                ? 'text-success-text'
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {t(
                                                            ':entered of :students entered',
                                                            {
                                                                entered: String(
                                                                    section.entered,
                                                                ),
                                                                students:
                                                                    String(
                                                                        section.students,
                                                                    ),
                                                            },
                                                        )}
                                                    </span>
                                                    {/* Only what differs from
                                                        plain entry (ADR-0037);
                                                        a closed window has its
                                                        own notice above. */}
                                                    {open &&
                                                        section.access.state !==
                                                            'edit' && (
                                                            <span
                                                                className="block text-xs text-muted-foreground"
                                                                data-test="section-access"
                                                            >
                                                                {accessLabel(
                                                                    section.access,
                                                                )}
                                                            </span>
                                                        )}
                                                </span>
                                            );
                                        })}
                                    </span>
                                </span>
                                <ChevronRight
                                    className="size-4 text-muted-foreground rtl:-scale-x-100"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
