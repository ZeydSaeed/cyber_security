import { Form, Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';

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

/**
 * Subjects of each stage and who teaches them (ADR-0034). A subject keeps
 * its stage; its name and teacher can change.
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

    return (
        <>
            <Head title={t('Subjects')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={t('Subjects')}
                    description={t(
                        'Each stage has its own subjects, each with one teacher.',
                    )}
                />

                <section className="rounded-lg border bg-card p-4">
                    <h2 className="mb-4 font-semibold">{t('Add a subject')}</h2>
                    <Form
                        {...GradesAdminController.storeSubject.form()}
                        resetOnSuccess={['name']}
                        className="grid gap-4 md:grid-cols-3"
                        data-test="add-subject"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-stage">
                                        {t('Stage')}
                                    </Label>
                                    <select
                                        id="new-stage"
                                        name="stage"
                                        className={selectClass}
                                    >
                                        {stages.map((stage) => (
                                            <option
                                                key={stage.stage}
                                                value={stage.stage}
                                            >
                                                {t('Stage :stage', {
                                                    stage: String(stage.stage),
                                                })}
                                            </option>
                                        ))}
                                    </select>
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
                                <div>
                                    <Button disabled={processing}>
                                        {t('Add subject')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

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
                            {t('Stage :stage', { stage: String(stage.stage) })}{' '}
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
                            {stage.subjects.map((subject) => (
                                <li key={subject.uuid} className="p-4">
                                    <Form
                                        {...GradesAdminController.updateSubject.form(
                                            subject.uuid,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="grid items-end gap-3 md:grid-cols-[1fr_1fr_auto]"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`name-${subject.uuid}`}
                                                    >
                                                        {t('Name')}
                                                    </Label>
                                                    <Input
                                                        id={`name-${subject.uuid}`}
                                                        name="name"
                                                        defaultValue={
                                                            subject.name
                                                        }
                                                        required
                                                        maxLength={120}
                                                        dir="auto"
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`teacher-${subject.uuid}`}
                                                    >
                                                        {t('Teacher')}
                                                    </Label>
                                                    <TeacherSelect
                                                        id={`teacher-${subject.uuid}`}
                                                        teachers={teachers}
                                                        value={subject.teacher}
                                                    />
                                                    <InputError
                                                        message={errors.teacher}
                                                    />
                                                </div>
                                                <Button
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {t('Save')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}
