import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, History } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import { useTranslations } from '@/hooks/use-translations';
import MarkHistory from '../../components/mark-history';
import type { HistoryRow } from '../../components/mark-history';
import TeacherSectionWork from '../../components/teacher-section-work';
import type { TeacherSectionData } from '../../components/teacher-section-work';
import { sectionLabel, stageLabel } from '../../place';

/**
 * One subject with its students straight away (owner's request,
 * 2026-11-19): each section the teacher teaches, in order, section A's
 * sheet then section B's, each saved on its own.
 */
export default function TeacherSubject({
    subject,
    sections,
    history,
}: {
    subject: {
        uuid: string;
        name: string;
        stage: number;
        divided: boolean;
        max: number;
    };
    /** Whether mark entry is open; each section's notice says it. */
    open: boolean;
    sections: TeacherSectionData[];
    /** Each section's mark journeys (ADR-0043): sent only once asked for. */
    history?: Record<string, HistoryRow[]>;
}) {
    const { t } = useTranslations();
    const [loading, setLoading] = useState(false);
    const loadHistory = () =>
        router.reload({
            only: ['history'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    return (
        <>
            <Head title={subject.name} />
            <Link
                prefetch
                href={TeacherMarksController.subjects.url()}
                className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ChevronLeft className="size-4 rtl:-scale-x-100" aria-hidden />
                {t('Your subjects')}
            </Link>
            <header className="mb-6">
                <h1 className="text-xl font-semibold">
                    <bdi>{subject.name}</bdi>
                </h1>
                <p className="text-sm text-muted-foreground">
                    {stageLabel(t, subject.stage)}
                </p>
            </header>

            <div className="flex flex-col gap-10">
                {sections.map((data) => (
                    <section
                        key={data.section}
                        aria-label={sectionLabel(
                            t,
                            data.section,
                            subject.divided,
                        )}
                        data-test={`subject-section-${data.section}`}
                    >
                        <TeacherSectionWork
                            {...data}
                            subject={subject}
                            within
                        />
                        {history?.[data.section] && (
                            <MarkHistory rows={history[data.section]} />
                        )}
                    </section>
                ))}
            </div>

            {!history && (
                <div className="mt-8">
                    <Button
                        variant="outline"
                        onClick={loadHistory}
                        disabled={loading}
                        data-test="show-history"
                    >
                        <History aria-hidden />
                        {t("Show the marks' history")}
                    </Button>
                </div>
            )}
        </>
    );
}
