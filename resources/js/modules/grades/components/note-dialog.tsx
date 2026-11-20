import { Form } from '@inertiajs/react';
import ResultSettingsController from '@/actions/App/Modules/Grades/Http/ResultSettingsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import { behaviourLabel } from '../result-words';
import type { ResultRow } from '../types';

const BEHAVIOURS = ['excellent', 'very_good', 'good', 'acceptable', 'poor'];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

/**
 * A student's behaviour and the owner's notes for the month (ADR-0039),
 * shown on the result card when the owner chooses.
 */
export default function NoteDialog({
    student,
    onClose,
}: {
    student: ResultRow;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="bg-card" data-test="note-dialog">
                <DialogTitle>
                    {t('Behaviour and notes: :name', { name: student.name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'Shown on the result card when the result settings say so.',
                    )}
                </DialogDescription>
                <Form
                    {...ResultSettingsController.note.form(student.uuid)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="note-behaviour">
                                    {t('Behaviour')}
                                </Label>
                                <select
                                    id="note-behaviour"
                                    name="behaviour"
                                    defaultValue={student.behaviour ?? ''}
                                    className={selectClass}
                                    data-test="note-behaviour"
                                >
                                    <option value="">{t('Not stated')}</option>
                                    {BEHAVIOURS.map((behaviour) => (
                                        <option
                                            key={behaviour}
                                            value={behaviour}
                                        >
                                            {behaviourLabel(t, behaviour)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.behaviour} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="note-text">{t('Notes')}</Label>
                                <Textarea
                                    id="note-text"
                                    name="notes"
                                    maxLength={1000}
                                    defaultValue={student.notes ?? ''}
                                    dir="auto"
                                    className="bg-background"
                                    data-test="note-text"
                                />
                                <InputError message={errors.notes} />
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    disabled={processing}
                                    data-test="save-note"
                                >
                                    {t('Save')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('Cancel')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
