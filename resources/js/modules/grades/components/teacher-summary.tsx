import { Clock, ListTodo, MessageSquareWarning } from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';
import { placeLabel } from '../place';
import type { TeacherSubject } from '../types';

/**
 * A teacher's to-do at a glance (ADR-0045): sections not yet complete,
 * marks that lock within a day, and students' appeals waiting for an
 * answer, each naming the subject and section. Nothing to do: nothing
 * shown.
 */
export default function TeacherSummary({
    subjects,
}: {
    subjects: TeacherSubject[];
}) {
    const { t } = useTranslations();
    const items: {
        key: string;
        icon: typeof Clock;
        tone: string;
        text: string;
        test: string;
    }[] = [];

    for (const subject of subjects) {
        for (const section of subject.sections) {
            const place = `${subject.name} · ${placeLabel(t, subject.stage, section.section, subject.divided)}`;
            const missing = section.students - section.entered;

            if (section.locking_soon) {
                items.push({
                    key: `${subject.uuid}${section.section}-lock`,
                    icon: Clock,
                    tone: 'text-warning-text',
                    text: t(':place: :count marks lock within a day.', {
                        place,
                        count: String(section.locking_soon),
                    }),
                    test: 'summary-locking',
                });
            }
            if (section.appeals) {
                items.push({
                    key: `${subject.uuid}${section.section}-appeals`,
                    icon: MessageSquareWarning,
                    tone: 'text-warning-text',
                    text: t(':place: :count appeals wait for an answer.', {
                        place,
                        count: String(section.appeals),
                    }),
                    test: 'summary-appeals',
                });
            }
            if (missing > 0 && section.access.can_write) {
                items.push({
                    key: `${subject.uuid}${section.section}-missing`,
                    icon: ListTodo,
                    tone: 'text-muted-foreground',
                    text: t(':place: :count students have no mark yet.', {
                        place,
                        count: String(missing),
                    }),
                    test: 'summary-missing',
                });
            }
        }
    }

    if (items.length === 0) {
        return null;
    }

    return (
        <section
            className="mb-6 rounded-md border bg-card p-3"
            aria-labelledby="teacher-summary"
            data-test="teacher-summary"
        >
            <h2 id="teacher-summary" className="mb-2 text-sm font-semibold">
                {t('To do')}
            </h2>
            <ul className="grid gap-1.5 text-sm">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex items-start gap-2"
                        data-test={item.test}
                    >
                        <item.icon
                            className={`mt-0.5 size-4 shrink-0 ${item.tone}`}
                            aria-hidden
                        />
                        <bdi>{item.text}</bdi>
                    </li>
                ))}
            </ul>
        </section>
    );
}
