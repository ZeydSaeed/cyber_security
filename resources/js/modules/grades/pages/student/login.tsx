import { Form, Head } from '@inertiajs/react';
import StudentPortalController from '@/actions/App/Modules/Grades/Http/StudentPortalController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

/**
 * The link the owner gives every student (ADR-0034): one field, the code
 * printed for them. The answer to a wrong code never says why.
 */
export default function StudentLogin() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Your result')} />

            <Form
                {...StudentPortalController.store.form()}
                resetOnError={['code']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="code">{t('Your code')}</Label>
                            <PasswordInput
                                id="code"
                                name="code"
                                required
                                autoFocus
                                autoComplete="off"
                                autoCapitalize="characters"
                                spellCheck={false}
                                placeholder="XXXX-XXXX"
                                dir="ltr"
                                data-test="student-code"
                            />
                            <InputError message={errors.code} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="student-login"
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

StudentLogin.layout = {
    title: 'Your result',
    description: 'Type the code the department gave you.',
};
