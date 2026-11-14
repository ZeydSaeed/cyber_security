import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';

/** The one link the owner sends to every teacher, ready to copy. */
export default function CopyLink({ href }: { href: string }) {
    const { t } = useTranslations();
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor="teacher-link" className="text-sm font-medium">
                {t('Link for the teachers')}
            </label>
            <div className="flex gap-2">
                <Input
                    id="teacher-link"
                    readOnly
                    value={href}
                    dir="ltr"
                    onFocus={(event) => event.currentTarget.select()}
                    data-test="teacher-link"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        void navigator.clipboard?.writeText(href).then(() => {
                            setCopied(true);
                            window.setTimeout(() => setCopied(false), 2000);
                        });
                    }}
                >
                    {copied ? (
                        <Check className="size-4" aria-hidden />
                    ) : (
                        <Copy className="size-4" aria-hidden />
                    )}
                    {copied ? t('Copied') : t('Copy')}
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                {t('Every teacher opens this link and types their own code.')}
            </p>
        </div>
    );
}
