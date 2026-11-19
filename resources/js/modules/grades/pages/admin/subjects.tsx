import { Form, Head } from '@inertiajs/react';
import { Plus, Printer } from 'lucide-react';
import { useCallback, useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ConfirmAction from '@/components/confirm-action';
import TableToolbar from '@/components/data-table/table-toolbar';
import InputError from '@/components/input-error';
import PageToolbar, { toolButton } from '@/components/page-toolbar';
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

type Subject = {
    uuid: string;
    name: string;
    /** Out of what, and its own pass and exemption marks (ADR-0039). */
    max_mark: number;
    pass_mark: number | null;
    exempt_mark: number | null;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/** The stage by its name: "First stage", "Second stage", "Third stage". */
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

/** A subject's name and stage, changed together. */
function EditSubject({
    subject,
    stage,
    stages,
    onDone,
}: {
    subject: Subject;
    stage: number;
    stages: number[];
    onDone: () => void;
}) {
    const { t } = useTranslations();

    return (
        <Form
            {...GradesAdminController.updateSubject.form(subject.uuid)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid items-start gap-3 md:grid-cols-2"
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
                    <fieldset className="grid gap-3 sm:grid-cols-3 md:col-span-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('Marking')}
                        </legend>
                        {(
                            [
                                [
                                    'max_mark',
                                    t('Out of'),
                                    subject.max_mark,
                                    true,
                                ],
                                [
                                    'pass_mark',
                                    t('Pass mark'),
                                    subject.pass_mark,
                                    false,
                                ],
                                [
                                    'exempt_mark',
                                    t('Exemption mark'),
                                    subject.exempt_mark,
                                    false,
                                ],
                            ] as const
                        ).map(([field, label, value, required]) => (
                            <div key={field} className="grid gap-1">
                                <Label htmlFor={`${field}-${subject.uuid}`}>
                                    {label}
                                </Label>
                                <Input
                                    id={`${field}-${subject.uuid}`}
                                    name={field}
                                    type="number"
                                    inputMode="numeric"
                                    min={field === 'max_mark' ? 1 : 0}
                                    max={1000}
                                    defaultValue={value ?? ''}
                                    required={required}
                                    placeholder={
                                        required
                                            ? undefined
                                            : t('As the department')
                                    }
                                    dir="ltr"
                                    data-test={`subject-${field}`}
                                />
                                <InputError message={errors[field]} />
                            </div>
                        ))}
                        <p className="text-xs text-muted-foreground sm:col-span-3">
                            {t(
                                "Empty pass or exemption marks follow the department's percentages of the maximum.",
                            )}
                        </p>
                    </fieldset>
                    <div className="flex gap-2 md:col-span-2">
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
 * Subjects of each stage (ADR-0034). Each stage has as many subjects as it
 * needs, and the subjects show no teacher: a teacher's subjects are chosen
 * on the teachers' page (owner's requests, 2026-11-17). "Edit" changes a
 * subject's name or stage (the stage only while it has no marks), "Delete"
 * removes one with no marks. The title, search, stage filter and "Add a
 * subject" (a dialog) are in the top toolbar, as on the other pages.
 */
export default function GradesSubjects({
    stages,
}: {
    stages: { stage: number; subjects: Subject[] }[];
}) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const stageNumbers = stages.map((stage) => stage.stage);
    // Search and filter on this page (all subjects are on it).
    const [search, setSearch] = useState('');
    const [stageFilter, setStageFilter] = useState<string | null>(null);
    const onSearch = useCallback((text: string) => setSearch(text), []);
    const needle = search.trim().toLowerCase();
    const matches = (subject: Subject) =>
        needle === '' || subject.name.toLowerCase().includes(needle);
    const shownStages = stages.filter(
        (stage) => stageFilter === null || String(stage.stage) === stageFilter,
    );

    return (
        <>
            <Head title={t('Subjects')} />
            {/* The same toolbar as the students' and teachers' pages:
                title, search and filter, then the actions. */}
            <PageToolbar title={t('Subjects')}>
                <TableToolbar
                    compact
                    search={search}
                    filters={[
                        {
                            key: 'stage',
                            label: t('Stage'),
                            options: stageNumbers.map((value) => ({
                                value: String(value),
                                label: stageLabel(t, value),
                            })),
                        },
                    ]}
                    values={stageFilter === null ? {} : { stage: stageFilter }}
                    onSearch={onSearch}
                    onFilter={(_key, value) => setStageFilter(value)}
                    onClear={() => {
                        setSearch('');
                        setStageFilter(null);
                    }}
                />
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-8 ${toolButton}`}
                    onClick={() => setAdding(true)}
                    data-test="add-subject-button"
                >
                    <Plus aria-hidden />
                    {t('Add a subject')}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-8 ${toolButton}`}
                    asChild
                >
                    <a
                        href={GradesAdminController.printSubjects.url()}
                        target="_blank"
                        rel="noopener"
                        data-test="print-subjects"
                    >
                        <Printer aria-hidden />
                        {t('Print')}
                    </a>
                </Button>
            </PageToolbar>
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="bg-card" data-test="subject-dialog">
                    <DialogTitle>{t('Add a subject')}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('Stage and name.')}
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
                {shownStages.map((stage) => (
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
                                {t(':count subjects', {
                                    count: String(stage.subjects.length),
                                })}
                                )
                            </span>
                        </h2>
                        <ul className="divide-y">
                            {stage.subjects.filter(matches).map((subject) =>
                                editing === subject.uuid ? (
                                    <li key={subject.uuid} className="p-4">
                                        <EditSubject
                                            subject={subject}
                                            stage={stage.stage}
                                            stages={stageNumbers}
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
                                        <span
                                            className="text-sm text-muted-foreground"
                                            data-test="subject-scale"
                                        >
                                            {t('Out of :max', {
                                                max: String(subject.max_mark),
                                            })}
                                            {subject.pass_mark !== null &&
                                                ` · ${t('Pass mark')}: ${subject.pass_mark}`}
                                            {subject.exempt_mark !== null &&
                                                ` · ${t('Exemption mark')}: ${subject.exempt_mark}`}
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
