import { History } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import { permissionLabel, roleLabel } from '@/lib/access-labels';
import { useTranslations } from '@/hooks/use-translations';
import type { TimelineEntry } from '@/types/table';

type Translate = ReturnType<typeof useTranslations>['t'];

/**
 * Audit actions as people read them; others show their code. Literal t()
 * calls, so TranslationsTest checks each has an Arabic translation.
 */
function actionLabel(t: Translate, entry: TimelineEntry): string {
    // One role, or several written "a, b" (user.created).
    const role = entry.details.role
        ? entry.details.role
              .split(', ')
              .map((value) => roleLabel(t, value))
              .join('، ')
        : '';
    const permission = entry.details.permission
        ? permissionLabel(t, entry.details.permission)
        : '';

    switch (entry.action) {
        case 'access.permission_granted':
            return t('Permission granted: :permission', { permission });
        case 'access.permission_revoked':
            return t('Permission removed: :permission', { permission });
        case 'user.updated':
            return t('Account details changed');
        case 'user.deleted':
            return t('Account deleted');
        case 'user.password_viewed':
            return t('Password shown to an administrator');
        case 'access.role_assigned':
            return t('Role granted: :role', { role });
        case 'access.role_revoked':
            return t('Role removed: :role', { role });
        case 'auth.login':
            return t('Signed in');
        case 'auth.logout':
            return t('Signed out');
        case 'auth.failed':
            return t('Failed sign-in');
        case 'auth.lockout':
            return t('Locked out after failed sign-ins');
        case 'auth.password_changed':
            return t('Password changed');
        case 'auth.password_reset':
            return t('Password reset');
        case 'auth.email_verified':
            return t('Email verified');
        case 'auth.two_factor_confirmed':
            return t('Two-factor authentication enabled');
        case 'auth.two_factor_disabled':
            return t('Two-factor authentication disabled');
        case 'auth.two_factor_failed':
            return t('Wrong two-factor code');
        case 'auth.recovery_code_used':
            return t('Recovery code used');
        case 'auth.recovery_codes_generated':
            return t('New recovery codes created');
        case 'file.uploaded':
            return t('File uploaded');
        case 'file.rejected':
            return t('File rejected');
        case 'grades.teacher_created':
            return t('Teacher added');
        case 'grades.teacher_renamed':
            return t('Teacher renamed');
        case 'grades.teacher_code_changed':
            return t('Teacher code changed');
        case 'grades.teacher_activated':
            return t('Teacher allowed to sign in');
        case 'grades.teacher_deactivated':
            return t('Teacher stopped from signing in');
        case 'grades.teacher_specialty_changed':
            return t('Teacher specialty changed');
        case 'grades.teacher_deleted':
            return t('Teacher deleted');
        case 'grades.teacher_codes_viewed':
            return t('Employee numbers of the teachers shown');
        case 'grades.subject_created':
            return t('Subject added');
        case 'grades.subject_renamed':
            return t('Subject renamed');
        case 'grades.subject_assigned':
            return t('Subject teacher changed');
        case 'grades.subject_teaching_changed':
            return t('Subject type or assistant changed');
        case 'grades.subject_stage_changed':
            return t('Subject stage changed');
        case 'grades.subject_deleted':
            return t('Subject deleted');
        case 'grades.student_created':
            return t('Student added');
        case 'grades.student_updated':
            return t('Student changed');
        case 'grades.student_deleted':
            return t('Student deleted');
        case 'grades.window_opened':
            return t('Mark entry opened');
        case 'grades.window_closed':
            return t('Mark entry closed');
        case 'grades.marks_saved':
            return t('Marks saved');
        case 'grades.students_imported':
            return t('Students imported');
        case 'grades.teacher_login':
            return t('Teacher signed in');
        case 'grades.teacher_logout':
            return t('Teacher signed out');
        case 'grades.teacher_login_failed':
            return t('Teacher code not recognised');
        case 'grades.results_exported':
            return t('Results exported');
        case 'grades.results_published':
            return t('Results published to the students');
        case 'grades.results_unpublished':
            return t('Results hidden from the students');
        case 'grades.student_codes_issued':
            return t('Student codes issued');
        case 'grades.student_code_replaced':
            return t('Student code replaced');
        case 'grades.student_codes_listed':
            return t('Student codes shown in the list');
        case 'grades.student_codes_viewed':
            return t('Student codes shown for printing');
        case 'grades.student_login':
            return t('Student signed in');
        case 'grades.student_login_failed':
            return t('Student code not recognised');
        case 'grades.student_lockout':
            return t('Student sign-in paused after too many attempts');
        case 'grades.teacher_lockout':
            return t('Teacher sign-in paused after too many attempts');
        case 'grades.access_settings_changed':
            return t('Access settings changed');
        case 'grades.teacher_access_changed':
            return t('Teacher access changed');
        case 'grades.section_access_changed':
            return t('Section access changed');
        case 'grades.teacher_logins_reset':
            return t('Teacher sign-ins counted from zero');
        case 'grades.student_logins_reset':
            return t('Student sign-ins counted from zero');
        case 'grades.student_blocked':
            return t('Student stopped from signing in');
        case 'grades.student_unblocked':
            return t('Student allowed to sign in');
        case 'grades.edit_requested':
            return t('Request to change marks sent');
        case 'grades.edit_request_decided':
            return t('Request to change marks answered');
        case 'grades.subject_scale_changed':
            return t('Subject maximum or pass mark changed');
        case 'grades.result_settings_changed':
            return t('Result settings changed');
        case 'grades.student_note_saved':
            return t('Behaviour or notes saved');
        case 'grades.teacher_view_changed':
            return t('What teachers see of results changed');
        case 'grades.teacher_results_viewed':
            return t("Teacher read their students' results");
        case 'grades.section_approved':
            return t("Teacher approved a section's marks");
        case 'grades.section_approval_withdrawn':
            return t('Approval of marks withdrawn');
        case 'grades.section_approval_cleared':
            return t('Approval cleared by a later change');
        case 'grades.devices_forgotten':
            return t('Remembered devices forgotten');
        case 'grades.device_token_mismatch':
            return t('Remembered device refused: its token was already used');
        case 'user.created':
            return role
                ? t('Added as :role', { role })
                : t('Added to the application');
        // Invitations were removed (2026-11-18); older entries still read.
        case 'user.invited':
            return role
                ? t('Invited as :role', { role })
                : t('Invited to the application');
        default:
            return entry.action;
    }
}

/**
 * A record's history from the audit trail (improvement #9), newest first:
 * what happened, who did it and when, in the reader's language and
 * calendar. A <time> element carries the exact moment.
 */
export default function ActivityTimeline({
    entries,
}: {
    entries: TimelineEntry[];
}) {
    const { t, locale } = useTranslations();
    const format = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    if (entries.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('No activity yet')}
                headingLevel={3}
                bordered={false}
            />
        );
    }

    return (
        <ol data-slot="timeline" className="relative space-y-4 border-s ps-5">
            {entries.map((entry) => (
                <li key={entry.id} className="relative">
                    <span
                        aria-hidden
                        className="absolute -start-[1.65rem] top-1.5 size-2.5 rounded-full border-2 border-background bg-primary"
                    />
                    <p className="text-sm font-medium">
                        {actionLabel(t, entry)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        <time dateTime={entry.occurred_at}>
                            {format.format(new Date(entry.occurred_at))}
                        </time>
                        {entry.actor &&
                            (entry.subject_is_record
                                ? ` · ${t('by :name', { name: entry.actor })}`
                                : ` · ${t('by this person')}`)}
                    </p>
                </li>
            ))}
        </ol>
    );
}
