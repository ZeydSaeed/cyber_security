import { Form } from '@inertiajs/react';
import { Clock, Eye, Lock, LockOpen } from 'lucide-react';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { EditRequest, SectionAccess } from '../types';

/** A moment in the reader's language, date and time. */
export function useMoment() {
    const { locale } = useTranslations();
    const format = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return (iso: string) => format.format(new Date(iso));
}

/** A length of time in minutes, as days, hours and minutes in words. */
export function useDuration() {
    const { t } = useTranslations();

    return (total: number): string => {
        const days = Math.floor(total / 1440);
        const hours = Math.floor((total % 1440) / 60);
        const minutes = total % 60;
        const parts: string[] = [];
        if (days > 0) {
            parts.push(t(':count days', { count: String(days) }));
        }
        if (hours > 0) {
            parts.push(t(':count hours', { count: String(hours) }));
        }
        if (minutes > 0) {
            parts.push(t(':count minutes', { count: String(minutes) }));
        }

        return parts.join(' ');
    };
}

/** One line for a section's access, as the teacher's pages show it. */
export function useAccessLabel() {
    const { t } = useTranslations();
    const moment = useMoment();
    const duration = useDuration();

    return (access: SectionAccess): string => {
        switch (access.state) {
            case 'published':
                return t(
                    'The results are published; marks can no longer change.',
                );
            case 'approved':
                return t(
                    'You approved these marks. Ask the department to change them.',
                );
            case 'granted':
                return t('You may change the marks until :time.', {
                    time: access.until ? moment(access.until) : '',
                });
            case 'closed':
                return t(
                    'Mark entry is closed. You can read the marks but not change them.',
                );
            case 'view_only':
                return t('You can read these marks but not change them.');
            case 'timed': {
                // ADR-0043: a time after entry, a date, or both.
                const parts: string[] = [];
                if (access.minutes !== null) {
                    parts.push(
                        t(
                            'Each mark can be changed for :time after it is entered.',
                            {
                                time: duration(access.minutes),
                            },
                        ),
                    );
                }
                if (access.deadline !== null) {
                    parts.push(
                        t('Marks can be changed until :time.', {
                            time: moment(access.deadline),
                        }),
                    );
                }

                return parts.join(' ');
            }
            default:
                return t('You can enter and change the marks.');
        }
    };
}

/**
 * What the teacher may do in this section (ADR-0037), and a way to ask the
 * department to change locked marks: shown when the sheet cannot be
 * written, or when some marks are past their editing time.
 */
export default function AccessNotice({
    access,
    hasLocked,
    request,
    subject,
    section,
}: {
    access: SectionAccess;
    hasLocked: boolean;
    request: EditRequest | null;
    subject: string;
    section: string;
}) {
    const { t } = useTranslations();
    const label = useAccessLabel();
    const moment = useMoment();
    const limited = !access.can_write || hasLocked;
    const Icon =
        access.state === 'granted'
            ? LockOpen
            : access.state === 'timed'
              ? Clock
              : access.state === 'view_only'
                ? Eye
                : access.can_write
                  ? LockOpen
                  : Lock;
    const pending = request?.status === 'pending';

    return (
        <div className="mb-6 flex flex-col gap-3" data-test="access-notice">
            <p
                role="status"
                className={
                    limited
                        ? 'flex items-start gap-2 rounded-md border border-warning bg-warning/15 p-3 text-sm'
                        : 'flex items-start gap-2 rounded-md border bg-card p-3 text-sm'
                }
                data-state={access.state}
            >
                <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>
                    {label(access)}
                    {hasLocked && access.can_write && (
                        <span className="block text-muted-foreground">
                            {t('Marks past their editing time are locked.')}
                        </span>
                    )}
                </span>
            </p>

            {request && (
                <p
                    className="text-sm text-muted-foreground"
                    data-test="edit-request-status"
                >
                    {request.status === 'pending'
                        ? t('Your request of :time is waiting for an answer.', {
                              time: moment(request.created_at),
                          })
                        : request.status === 'approved'
                          ? t(
                                'Your last request was approved for :hours hours.',
                                { hours: String(request.granted_hours ?? 0) },
                            )
                          : t('Your last request was rejected.')}
                </p>
            )}

            {limited && !pending && access.state !== 'published' && (
                <Form
                    {...TeacherMarksController.requestEdit.form({
                        subject,
                        section,
                    })}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-2 rounded-md border bg-card p-3"
                    data-test="edit-request-form"
                >
                    {({ errors, processing }) => (
                        <>
                            <Label htmlFor={`edit-reason-${section}`}>
                                {t('Ask the department to change marks')}
                            </Label>
                            <Textarea
                                id={`edit-reason-${section}`}
                                name="reason"
                                required
                                minLength={5}
                                maxLength={500}
                                dir="auto"
                                placeholder={t(
                                    'Which marks, and why they need changing.',
                                )}
                                aria-invalid={errors.reason ? true : undefined}
                                className="min-h-20 bg-background"
                            />
                            <InputError message={errors.reason} />
                            <Button
                                variant="outline"
                                className="self-start"
                                disabled={processing}
                                data-test="send-edit-request"
                            >
                                {t('Send the request')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
