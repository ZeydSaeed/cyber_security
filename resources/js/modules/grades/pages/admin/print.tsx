import { Head, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import PageToolbar from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { stageName } from '../../place';

type Place = { stage: number; section: string; divided: boolean };

/**
 * The students' Print page (owner's request, 2026-11-17), reached from the
 * students' toolbar: a button for each group's code slips ("Codes of the
 * second stage · Section A"), each opening the printable page in a new
 * tab, and codes for the students who have none yet.
 */
export default function GradesPrint({
    places,
    withoutCode,
}: {
    places: Place[];
    withoutCode: number;
}) {
    const { t } = useTranslations();
    const label = (place: Place) => {
        const stage = t('Codes of the :stage stage', {
            stage: stageName(t, place.stage),
        });

        return place.divided
            ? `${stage} · ${t('Section :section', { section: place.section })}`
            : stage;
    };

    return (
        <>
            <Head title={t('Print')} />
            <PageToolbar title={t('Print')} />
            <div className="flex flex-col gap-6 p-4">
                <section
                    className="rounded-lg border bg-card p-4"
                    aria-labelledby="student-codes"
                    data-test="student-codes"
                >
                    <h2 id="student-codes" className="mb-3 font-semibold">
                        {t('Student codes')}
                    </h2>
                    {withoutCode > 0 && (
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <span className="text-sm">
                                {t(':count students have no code yet.', {
                                    count: String(withoutCode),
                                })}
                            </span>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        StudentsAdminController.issueCodes.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                data-test="issue-codes"
                            >
                                {t('Give them codes')}
                            </Button>
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {places.map((place) => (
                            <Button
                                key={`${place.stage}${place.section}`}
                                variant="outline"
                                asChild
                            >
                                <a
                                    href={`${StudentsAdminController.codes.url()}?stage=${place.stage}&section=${place.section}`}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Printer aria-hidden />
                                    {label(place)}
                                </a>
                            </Button>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
