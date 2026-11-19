import { roleLabel } from '@/lib/access-labels';
import type { useTranslations } from '@/hooks/use-translations';

type Translate = ReturnType<typeof useTranslations>['t'];

export type NotificationData = Record<string, string>;

/**
 * A notification as a sentence in the reader's language (ADR-0029): the
 * server stores the facts, the interface writes the words. Literal t()
 * calls, so each has an Arabic translation (TranslationsTest).
 */
export function notificationText(t: Translate, data: NotificationData): string {
    switch (data.kind) {
        case 'role_granted':
            return t(':by granted you the role :role.', {
                by: data.by ?? '',
                role: roleLabel(t, data.role ?? ''),
            });
        case 'role_removed':
            return t(':by removed your role :role.', {
                by: data.by ?? '',
                role: roleLabel(t, data.role ?? ''),
            });
        case 'grades_section_completed':
            return t(
                ':teacher finished the marks of “:subject”, stage :stage, section :section.',
                {
                    teacher: data.teacher ?? '',
                    subject: data.subject ?? '',
                    // Stored as a number; shown by name (owner, 2026-11-16).
                    stage:
                        data.stage === '1'
                            ? t('First')
                            : data.stage === '2'
                              ? t('Second')
                              : data.stage === '3'
                                ? t('Third')
                                : (data.stage ?? ''),
                    section: data.section ?? '',
                },
            );
        case 'grades_edit_requested':
            return t(
                ':teacher asks to change the marks of “:subject”, section :section.',
                {
                    teacher: data.teacher ?? '',
                    subject: data.subject ?? '',
                    section: data.section ?? '',
                },
            );
        case 'grades_appeal_submitted':
            return t(':student appeals the mark of “:subject”.', {
                student: data.student ?? '',
                subject: data.subject ?? '',
            });
        default:
            return t('Something changed in your account.');
    }
}

/** Where a notification leads, if anywhere (none link anywhere yet). */
export function notificationHref(_data: NotificationData): string | null {
    return null;
}
