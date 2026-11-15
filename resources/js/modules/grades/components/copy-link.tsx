import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';

/** A link the owner sends (to the teachers or the students), ready to copy. */
export default function CopyLink({
    id,
    href,
    label,
    hint,
}: {
    id: string;
    href: string;
    label: string;
    hint: string;
}) {
    const { t } = useTranslations();
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <div className="flex gap-2">
                <Input
                    id={id}
                    readOnly
                    value={href}
                    dir="ltr"
                    onFocus={(event) => event.currentTarget.select()}
                    data-test={id}
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
            <p className="text-sm text-muted-foreground">{hint}</p>
        </div>
    );
}
