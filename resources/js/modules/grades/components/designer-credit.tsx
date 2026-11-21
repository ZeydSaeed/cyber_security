import { useTranslations } from '@/hooks/use-translations';

const DESIGNER_EMAIL = 'zeydsaeed@gmail.com';

/**
 * Who designed the system, under the sign-in button of the teachers' and
 * students' pages (owner's request, 2026-11-21), in the same type as the
 * form's labels ("Your code"): text-sm, medium weight, the text colour.
 */
export default function DesignerCredit() {
    const { t } = useTranslations();

    return (
        <footer
            className="flex flex-col items-center gap-2 border-t pt-4 text-center text-sm font-medium text-foreground"
            data-test="designer-credit"
        >
            <p>
                {t(
                    'This system was designed and developed by Dr. Zeyd Saeed Rahm',
                )}
            </p>
            <p>
                {t('Contact')}:{' '}
                <a
                    href={`mailto:${DESIGNER_EMAIL}`}
                    dir="ltr"
                    className="underline underline-offset-2 hover:text-primary"
                >
                    {DESIGNER_EMAIL}
                </a>
            </p>
        </footer>
    );
}
