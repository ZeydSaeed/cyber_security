import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import { placeLabel } from '../../place';

/**
 * A group's codes, to print and cut into slips (ADR-0034): each slip has
 * the student's name, the link and their code, in black text inside a
 * black frame (print-ink, owner's request 2026-11-16). Opening this page
 * is recorded in the audit trail.
 */
export default function PrintCodes({
    stage,
    section,
    divided,
    studentLink,
    students,
}: {
    stage: number;
    section: string;
    divided: boolean;
    studentLink: string;
    students: { name: string; number: string | null; code: string | null }[];
}) {
    const { t } = useTranslations();
    const place = placeLabel(t, stage, section, divided);

    return (
        <>
            <Head title={`${t('Student codes')} · ${place}`} />
            <PrintPage title={t('Student codes')} subtitle={place} paper>
                <p className="mb-4 text-sm print:hidden">
                    {t(
                        'Cut along the lines and give each student their own slip. Keep the codes private.',
                    )}
                </p>
                <ul
                    className="grid gap-3 sm:grid-cols-2 print:grid-cols-2"
                    data-test="code-slips"
                >
                    {students.map((student) => (
                        <li
                            key={`${student.name}-${student.code}`}
                            className="break-inside-avoid rounded-md border-2 border-print-ink p-3 text-print-ink"
                            data-test="code-slip"
                        >
                            <p className="font-medium">
                                <bdi>{student.name}</bdi>
                            </p>
                            <p className="text-sm">
                                {t('Open :link and type your code:', {
                                    link: studentLink,
                                })}
                            </p>
                            <p
                                className="mt-1 font-mono text-lg tracking-widest"
                                dir="ltr"
                                data-test="slip-code"
                            >
                                {student.code ?? t('No code yet')}
                            </p>
                        </li>
                    ))}
                </ul>
            </PrintPage>
        </>
    );
}
