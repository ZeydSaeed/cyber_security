import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import CopyLink from '../../components/copy-link';

type Teacher = {
    uuid: string;
    name: string;
    active: boolean;
    last_seen_at: string | null;
    subjects: string[];
};

/** A name and a code: adding a teacher, or changing one. */
function TeacherFields({
    errors,
    codeLength,
    name,
    withName = true,
    withCode = true,
}: {
    errors: Record<string, string | undefined>;
    codeLength: { min: number; max: number };
    name?: string;
    withName?: boolean;
    withCode?: boolean;
}) {
    const { t } = useTranslations();
    const prefix = name === undefined ? 'new' : 'edit';

    return (
        <>
            {withName && (
                <div className="grid gap-2">
                    <Label htmlFor={`${prefix}-name`}>{t('Name')}</Label>
                    <Input
                        id={`${prefix}-name`}
                        name="name"
                        defaultValue={name}
                        required
                        maxLength={120}
                        dir="auto"
                    />
                    <InputError message={errors.name} />
                </div>
            )}
            {withCode && (
                <div className="grid gap-2">
                    <Label htmlFor={`${prefix}-code`}>{t('Code')}</Label>
                    <Input
                        id={`${prefix}-code`}
                        name="code"
                        required
                        minLength={codeLength.min}
                        maxLength={codeLength.max}
                        autoComplete="off"
                        spellCheck={false}
                        dir="ltr"
                    />
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'At least :min characters, no spaces. Choose one that is hard to guess, not 12345678.',
                            { min: String(codeLength.min) },
                        )}
                    </p>
                    <InputError message={errors.code} />
                </div>
            )}
        </>
    );
}

/**
 * Teachers and their codes (ADR-0034). The owner sets each code and gives
 * it to the teacher with the link; codes are never shown again, only
 * changed. Changing a code or stopping a teacher ends their open sessions.
 */
export default function GradesTeachers({
    teachers,
    teacherLink,
    codeLength,
}: {
    teachers: Teacher[];
    teacherLink: string;
    codeLength: { min: number; max: number };
}) {
    const { t, locale } = useTranslations();
    const [editing, setEditing] = useState<{
        uuid: string;
        what: 'name' | 'code';
    } | null>(null);
    const dates = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const list = new Intl.ListFormat(locale === 'ar' ? 'ar' : 'en', {
        type: 'conjunction',
    });

    return (
        <>
            <Head title={t('Teachers')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={t('Teachers')}
                    description={t(
                        'Each teacher signs in with the code you set. Give it to them with the link.',
                    )}
                />

                <section className="rounded-lg border bg-card p-4">
                    <CopyLink
                        id="teacher-link"
                        href={teacherLink}
                        label={t('Link for the teachers')}
                    />
                </section>

                <section className="rounded-lg border bg-card p-4">
                    <h2 className="mb-4 font-semibold">{t('Add a teacher')}</h2>
                    <Form
                        {...GradesAdminController.storeTeacher.form()}
                        resetOnSuccess
                        className="grid gap-4 md:grid-cols-2"
                        data-test="add-teacher"
                    >
                        {({ errors, processing }) => (
                            <>
                                <TeacherFields
                                    errors={errors}
                                    codeLength={codeLength}
                                />
                                <div>
                                    <Button disabled={processing}>
                                        {t('Add teacher')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                {teachers.length === 0 ? (
                    <EmptyState
                        title={t('No teachers yet')}
                        description={t(
                            'Add each teacher with a code of their own.',
                        )}
                    />
                ) : (
                    <ul
                        className="flex flex-col divide-y rounded-lg border bg-card"
                        data-test="teachers"
                    >
                        {teachers.map((teacher) => (
                            <li
                                key={teacher.uuid}
                                className="flex flex-col gap-3 p-4"
                            >
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span className="font-medium" dir="auto">
                                        {teacher.name}
                                    </span>
                                    {!teacher.active && (
                                        <Badge variant="secondary">
                                            {t('Stopped')}
                                        </Badge>
                                    )}
                                    <span className="text-sm text-muted-foreground">
                                        {teacher.subjects.length === 0
                                            ? t('No subject yet')
                                            : list.format(teacher.subjects)}
                                    </span>
                                    <span className="ms-auto text-sm text-muted-foreground">
                                        {teacher.last_seen_at
                                            ? t('Last seen :when', {
                                                  when: dates.format(
                                                      new Date(
                                                          teacher.last_seen_at,
                                                      ),
                                                  ),
                                              })
                                            : t('Not signed in yet')}
                                    </span>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({
                                                uuid: teacher.uuid,
                                                what: 'name',
                                            })
                                        }
                                    >
                                        {t('Rename')}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({
                                                uuid: teacher.uuid,
                                                what: 'code',
                                            })
                                        }
                                        data-test="change-code"
                                    >
                                        {t('Change code')}
                                    </Button>
                                    <Button
                                        variant={
                                            teacher.active
                                                ? 'outline'
                                                : 'default'
                                        }
                                        size="sm"
                                        onClick={() =>
                                            router.put(
                                                GradesAdminController.updateTeacher.url(
                                                    teacher.uuid,
                                                ),
                                                { active: !teacher.active },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {teacher.active
                                            ? t('Stop signing in')
                                            : t('Allow signing in')}
                                    </Button>
                                </div>

                                {editing?.uuid === teacher.uuid && (
                                    <Form
                                        {...GradesAdminController.updateTeacher.form(
                                            teacher.uuid,
                                        )}
                                        options={{ preserveScroll: true }}
                                        onSuccess={() => setEditing(null)}
                                        className="grid gap-4 rounded-md border p-3 md:grid-cols-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <TeacherFields
                                                    errors={errors}
                                                    codeLength={codeLength}
                                                    name={teacher.name}
                                                    withName={
                                                        editing.what === 'name'
                                                    }
                                                    withCode={
                                                        editing.what === 'code'
                                                    }
                                                />
                                                <div className="flex gap-2">
                                                    <Button
                                                        disabled={processing}
                                                    >
                                                        {t('Save')}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setEditing(null)
                                                        }
                                                    >
                                                        {t('Cancel')}
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
