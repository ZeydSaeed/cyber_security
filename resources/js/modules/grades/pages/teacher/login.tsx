import { Form, Head } from '@inertiajs/react';
import TeacherSessionController from '@/actions/App/Modules/Grades/Http/TeacherSessionController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

/**
 * The link the owner sends to every teacher (ADR-0034): one field, the
 * teacher's code. The answer to a wrong code never says why.
 */
export default function TeacherLogin() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Enter marks')} />

            <Form
                {...TeacherSessionController.store.form()}
                resetOnError={['code']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="code">
                                {t('Your employee number')}
                            </Label>
                            <PasswordInput
                                id="code"
                                name="code"
                                required
                                autoFocus
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                dir="ltr"
                                data-test="teacher-code"
                            />
                            <InputError message={errors.code} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="teacher-login"
                        >
                            {processing && <Spinner />}
                            {t('Continue')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

TeacherLogin.layout = {
    title: 'Enter marks',
    description: 'Type the code the department gave you.',
};
