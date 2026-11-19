import { Form, Head } from '@inertiajs/react';
import StudentPortalController from '@/actions/App/Modules/Grades/Http/StudentPortalController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

/**
 * The link the owner gives every student (ADR-0034): one field, the code
 * printed for them. The answer to a wrong code never says why.
 */
export default function StudentLogin({
    rememberDays = 0,
}: {
    /** Days a device stays remembered (ADR-0038); 0: not offered. */
    rememberDays?: number;
}) {
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

                        {/* Opt-in, never on by default: a shared
                            computer must not keep anyone signed in. */}
                        {rememberDays > 0 && (
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    value="1"
                                    className="mt-0.5"
                                    aria-describedby="remember-hint"
                                    data-test="student-remember"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="remember">
                                        {t(
                                            'Remember this device for :days days',
                                            {
                                                days: String(rememberDays),
                                            },
                                        )}
                                    </Label>
                                    <p
                                        id="remember-hint"
                                        className="text-xs text-muted-foreground"
                                    >
                                        {t(
                                            'Next time you go straight in, until your code changes. Do not use it on a shared computer.',
                                        )}
                                    </p>
                                </div>
                            </div>
                        )}

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
