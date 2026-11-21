import { Head } from '@inertiajs/react';
import { BadgeCheck } from 'lucide-react';
import ConfirmAction from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import { useTranslations } from '@/hooks/use-translations';
import { useMoment } from '../../components/access-notice';
import MarkHistory from '../../components/mark-history';
import type { HistoryRow } from '../../components/mark-history';
import MarkSheet from '../../components/mark-sheet';
import { placeLabel } from '../../place';
import type { SheetStudent } from '../../types';

/**
 * The owner corrects a section's marks (ADR-0034): the teachers' sheet,
 * open at any time, each change recorded under the owner's name.
 */
export default function GradesSheet({
    open,
    subject,
    section,
    students,
    history,
    approval,
}: {
    open: boolean;
    subject: {
        uuid: string;
        name: string;
        stage: number;
        divided: boolean;
        max: number;
    };
    section: string;
    students: SheetStudent[];
    /** Every change of these marks (ADR-0041). */
    history: HistoryRow[];
    /** The teacher's approval, if any. */
    approval: { at: string; by: string | null } | null;
}) {
    const { t } = useTranslations();
    const moment = useMoment();

    return (
        <>
            <Head
                title={`${subject.name} · ${placeLabel(t, subject.stage, section, subject.divided)}`}
            />
            <div className="max-w-3xl p-4">
                <MarkSheet
                    subject={subject}
                    section={section}
                    students={students}
                    editable
                    saveUrl={ResultsController.saveSheet.url({
                        subject: subject.uuid,
                        section,
                    })}
                    back={{
                        href: GradesAdminController.overview.url(),
                        label: t('Control panel'),
                    }}
                    notice={
                        <>
                            {approval && (
                                <div
                                    className="mb-4 flex flex-wrap items-center gap-3 rounded-md border bg-card p-3 text-sm"
                                    data-test="approval"
                                >
                                    <BadgeCheck
                                        className="size-4 text-success-text"
                                        aria-hidden
                                    />
                                    <span className="flex-1">
                                        {t('Approved by :name on :time.', {
                                            name: approval.by ?? t('A teacher'),
                                            time: moment(approval.at),
                                        })}
                                    </span>
                                    <ConfirmAction
                                        trigger={
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                data-test="withdraw-approval"
                                            >
                                                {t('Withdraw the approval')}
                                            </Button>
                                        }
                                        title={t('Withdraw the approval?')}
                                        description={t(
                                            'The teacher can change these marks again, as their access allows.',
                                        )}
                                        confirmLabel={t('Withdraw')}
                                        tone="default"
                                        form={ResultsController.withdrawApproval.form(
                                            { subject: subject.uuid, section },
                                        )}
                                    />
                                </div>
                            )}
                            <p
                                className="mb-6 rounded-md border bg-muted p-3 text-sm"
                                role="note"
                            >
                                {open
                                    ? t(
                                          'You are correcting a teacher’s marks. Your changes are recorded under your name.',
                                      )
                                    : t(
                                          'Mark entry is closed for teachers. You can still correct marks; your changes are recorded under your name.',
                                      )}
                            </p>
                        </>
                    }
                />
                <MarkHistory rows={history} />
            </div>
        </>
    );
}
