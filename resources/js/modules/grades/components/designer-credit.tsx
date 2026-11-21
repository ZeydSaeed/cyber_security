import { useTranslations } from '@/hooks/use-translations';

const DESIGNER_EMAIL = 'zeydsaeed@gmail.com';

/**
 * Who designed the system, under the sign-in button of the teachers' and
 * students' pages (owner's request, 2026-11-21).
 */
export default function DesignerCredit() {
    const { t } = useTranslations();

    return (
        <footer
            className="flex flex-col items-center gap-1 border-t pt-4 text-center text-xs text-muted-foreground"
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
                    className="underline underline-offset-2 hover:text-foreground"
                >
                    {DESIGNER_EMAIL}
                </a>
            </p>
        </footer>
    );
}
