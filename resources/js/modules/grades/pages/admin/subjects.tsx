import { Form, Head } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import { useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ConfirmAction from '@/components/confirm-action';
import InputError from '@/components/input-error';
import PageToolbar, { toolButton } from '@/components/page-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { stageLabel } from '../../place';

type Teacher = { uuid: string; name: string; active: boolean };
type Subject = { uuid: string; name: string; teacher: string | null };

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

function TeacherSelect({
    id,
    teachers,
    value,
}: {
    id: string;
    teachers: Teacher[];
    value?: string | null;
}) {
    const { t } = useTranslations();

    return (
        <select
            id={id}
            name="teacher"
            defaultValue={value ?? ''}
            className={selectClass}
        >
            <option value="">{t('No teacher yet')}</option>
            {teachers.map((teacher) => (
                <option key={teacher.uuid} value={teacher.uuid}>
                    {teacher.active
                        ? teacher.name
                        : t(':name (stopped)', { name: teacher.name })}
                </option>
            ))}
        </select>
    );
}

/** The stage by its name: "First" or "Second". */
function StageSelect({
    id,
    stages,
    value,
}: {
    id: string;
    stages: number[];
    value?: number;
}) {
    const { t } = useTranslations();

    return (
        <select
            id={id}
            name="stage"
            defaultValue={value}
            className={selectClass}
        >
            {stages.map((stage) => (
                <option key={stage} value={stage}>
                    {stageLabel(t, stage)}
                </option>
            ))}
        </select>
    );
}

/** A subject's name, teacher and stage, changed together. */
function EditSubject({
    subject,
    stage,
    stages,
    teachers,
    onDone,
}: {
    subject: Subject;
    stage: number;
    stages: number[];
    teachers: Teacher[];
    onDone: () => void;
}) {
    const { t } = useTranslations();

    return (
        <Form
            {...GradesAdminController.updateSubject.form(subject.uuid)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid items-start gap-3 md:grid-cols-3"
            data-test="edit-subject"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`name-${subject.uuid}`}>
                            {t('Name')}
                        </Label>
                        <Input
                            id={`name-${subject.uuid}`}
                            name="name"
                            defaultValue={subject.name}
                            required
                            maxLength={120}
                            dir="auto"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`teacher-${subject.uuid}`}>
                            {t('Teacher')}
                        </Label>
                        <TeacherSelect
                            id={`teacher-${subject.uuid}`}
                            teachers={teachers}
                            value={subject.teacher}
                        />
                        <InputError message={errors.teacher} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`stage-${subject.uuid}`}>
                            {t('Stage')}
                        </Label>
                        <StageSelect
                            id={`stage-${subject.uuid}`}
                            stages={stages}
                            value={stage}
                        />
                        <InputError message={errors.stage} />
                    </div>
                    <div className="flex gap-2 md:col-span-3">
                        <Button disabled={processing}>{t('Save')}</Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            {t('Cancel')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Subjects of each stage and who teaches them (ADR-0034). Each subject is
 * shown with its teacher; "Edit" changes its name, teacher or stage (the
 * stage only while it has no marks), and "Delete" removes one with no
 * marks. The title and "Add a subject" (a dialog) are in the top toolbar
 * (owner's layout, 2026-11-16).
 */
export default function GradesSubjects({
    stages,
    teachers,
    expected,
}: {
    stages: { stage: number; subjects: Subject[] }[];
    teachers: Teacher[];
    expected: number;
}) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const stageNumbers = stages.map((stage) => stage.stage);
    const teacherName = (uuid: string | null) =>
        teachers.find((teacher) => teacher.uuid === uuid)?.name ?? null;

    return (
        <>
            <Head title={t('Subjects')} />
            <PageToolbar title={t('Subjects')}>
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-7 ${toolButton}`}
                    onClick={() => setAdding(true)}
                    data-test="add-subject-button"
                >
                    <Plus aria-hidden />
                    {t('Add a subject')}
                </Button>
            </PageToolbar>
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="bg-card" data-test="subject-dialog">
                    <DialogTitle>{t('Add a subject')}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('Stage, name and teacher.')}
                    </DialogDescription>
                    <Form
                        {...GradesAdminController.storeSubject.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setAdding(false)}
                        className="grid gap-4"
                        data-test="add-subject"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-stage">
                                        {t('Stage')}
                                    </Label>
                                    <StageSelect
                                        id="new-stage"
                                        stages={stageNumbers}
                                    />
                                    <InputError message={errors.stage} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-name">
                                        {t('Name')}
                                    </Label>
                                    <Input
                                        id="new-name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        dir="auto"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-teacher">
                                        {t('Teacher')}
                                    </Label>
                                    <TeacherSelect
                                        id="new-teacher"
                                        teachers={teachers}
                                    />
                                    <InputError message={errors.teacher} />
                                </div>
                                <div className="flex gap-2">
                                    <Button disabled={processing}>
                                        {t('Add subject')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setAdding(false)}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                {stages.map((stage) => (
                    <section
                        key={stage.stage}
                        className="rounded-lg border bg-card"
                        aria-labelledby={`subjects-stage-${stage.stage}`}
                    >
                        <h2
                            id={`subjects-stage-${stage.stage}`}
                            className="border-b p-4 font-semibold"
                        >
                            {stageLabel(t, stage.stage)}{' '}
                            <span className="text-sm font-normal text-muted-foreground">
                                (
                                {t(':count of :expected subjects', {
                                    count: String(stage.subjects.length),
                                    expected: String(expected),
                                })}
                                )
                            </span>
                        </h2>
                        {stage.subjects.length !== expected && (
                            <p className="m-4 flex items-start gap-2 rounded-md border border-warning bg-warning/15 p-3 text-sm">
                                <AlertTriangle
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden
                                />
                                {t(
                                    'This stage has :count subjects; :expected are expected.',
                                    {
                                        count: String(stage.subjects.length),
                                        expected: String(expected),
                                    },
                                )}
                            </p>
                        )}
                        <ul className="divide-y">
                            {stage.subjects.map((subject) =>
                                editing === subject.uuid ? (
                                    <li key={subject.uuid} className="p-4">
                                        <EditSubject
                                            subject={subject}
                                            stage={stage.stage}
                                            stages={stageNumbers}
                                            teachers={teachers}
                                            onDone={() => setEditing(null)}
                                        />
                                    </li>
                                ) : (
                                    <li
                                        key={subject.uuid}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4"
                                        data-test="subject-row"
                                    >
                                        <span
                                            className="min-w-40 font-medium"
                                            dir="auto"
                                        >
                                            {subject.name}
                                        </span>
                                        <span className="text-sm">
                                            {teacherName(subject.teacher) ?? (
                                                <Badge variant="destructive">
                                                    {t('No teacher')}
                                                </Badge>
                                            )}
                                        </span>
                                        <div className="ms-auto flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(subject.uuid)
                                                }
                                                data-test="edit-subject-button"
                                            >
                                                {t('Edit')}
                                            </Button>
                                            <ConfirmAction
                                                trigger={
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="text-destructive-text"
                                                    >
                                                        {t('Delete')}
                                                    </Button>
                                                }
                                                title={t('Delete :name?', {
                                                    name: subject.name,
                                                })}
                                                description={t(
                                                    'A subject that already has marks cannot be deleted.',
                                                )}
                                                confirmLabel={t('Delete')}
                                                form={GradesAdminController.destroySubject.form(
                                                    subject.uuid,
                                                )}
                                            />
                                        </div>
                                    </li>
                                ),
                            )}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}
