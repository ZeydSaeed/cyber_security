import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Eye, EyeOff } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { placeLabel, sectionLabel, stageLabel } from '../place';

type Translate = ReturnType<typeof useTranslations>['t'];

export type PublicationStage = {
    stage: number;
    divided: boolean;
    students: Record<string, number>;
    subjects: {
        uuid: string;
        name: string;
        /** Each section's teacher's name (null: none yet). */
        teachers: Record<string, string | null>;
        sections: Record<string, number>;
        /** Whether each section's teacher approved the marks (ADR-0041). */
        approved: Record<string, boolean>;
    }[];
};

export type PublicationWindow = {
    open: boolean;
    published: boolean;
    /** Sections published on their own (ADR-0042): "stage|section". */
    sections_published: string[];
};

/** One subject section that is not ready to publish. */
type Shortage = {
    place: string;
    stage: number;
    section: string;
    subject: string;
    teacher: string | null;
    missing: number;
    approved: boolean;
};

/** "stage|section", as the server names a place. */
const placeKey = (stage: number, section: string) => `${stage}|${section}`;

/**
 * What is not ready in the chosen places (owner's request, 2026-11-20):
 * each subject section with no teacher, with marks still missing, or not
 * approved by its teacher, with the teacher's name.
 */
function shortagesIn(
    t: Translate,
    stages: PublicationStage[],
    chosen: Set<string>,
): Shortage[] {
    const rows: Shortage[] = [];

    for (const stage of stages) {
        for (const [section, students] of Object.entries(stage.students)) {
            if (!chosen.has(placeKey(stage.stage, section))) {
                continue;
            }
            for (const subject of stage.subjects) {
                const teacher = subject.teachers[section] ?? null;
                const missing = Math.max(
                    0,
                    students - (subject.sections[section] ?? 0),
                );
                const approved = subject.approved[section] ?? false;

                if (teacher === null || missing > 0 || !approved) {
                    rows.push({
                        place: placeLabel(
                            t,
                            stage.stage,
                            section,
                            stage.divided,
                        ),
                        stage: stage.stage,
                        section,
                        subject: subject.name,
                        teacher,
                        missing,
                        approved,
                    });
                }
            }
        }
    }

    // Sections with no teacher first: they cannot be completed at all.
    return rows.sort(
        (a, b) =>
            Number(a.teacher !== null) - Number(b.teacher !== null) ||
            a.stage - b.stage ||
            a.section.localeCompare(b.section),
    );
}

/** What is wrong with one subject section, in words. */
function problems(t: Translate, row: Shortage): string[] {
    return [
        row.teacher === null ? t('No teacher assigned') : null,
        row.missing > 0
            ? t(':count marks missing', { count: String(row.missing) })
            : null,
        row.teacher !== null && !row.approved
            ? t('Not approved by the teacher')
            : null,
    ].filter((text): text is string => text !== null);
}

/** The shortages as a table: place, subject, teacher, what is missing. */
function ShortageTable({ rows }: { rows: Shortage[] }) {
    const { t } = useTranslations();

    return (
        <div className="overflow-x-auto rounded-md border bg-card">
            <table className="w-full text-sm" data-test="shortage-table">
                <thead className="bg-muted text-start">
                    <tr>
                        <th className="px-3 py-2 text-start font-semibold">
                            {t('Stage and section')}
                        </th>
                        <th className="px-3 py-2 text-start font-semibold">
                            {t('Subject')}
                        </th>
                        <th className="px-3 py-2 text-start font-semibold">
                            {t('Teacher')}
                        </th>
                        <th className="px-3 py-2 text-start font-semibold">
                            {t('What is missing')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={`${row.stage}${row.section}${row.subject}`}
                            className="border-t align-top"
                            data-test="shortage-row"
                        >
                            <td className="px-3 py-2 whitespace-nowrap">
                                {row.place}
                            </td>
                            <td className="px-3 py-2">{row.subject}</td>
                            <td className="px-3 py-2">
                                {row.teacher ?? (
                                    <span className="font-medium text-destructive-text">
                                        {t('None yet')}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 text-muted-foreground">
                                {problems(t, row).join(' · ')}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** A checkbox that can show "some" (a stage with part of its sections). */
function Tick({
    checked,
    some = false,
    disabled = false,
    onChange,
    label,
    strong = false,
    test,
}: {
    checked: boolean;
    some?: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    strong?: boolean;
    test: string;
}) {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = some && !checked;
        }
    }, [some, checked]);

    return (
        <label
            className={cn(
                'flex items-center gap-2 text-sm',
                strong && 'font-semibold',
                disabled && 'opacity-60',
            )}
        >
            <input
                ref={ref}
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="size-4 accent-primary"
                data-test={test}
            />
            {label}
        </label>
    );
}

/**
 * Announcing the results (owner's request, 2026-11-20): the stages and
 * sections are ticked (several at once, or all), what is not ready in
 * them is listed under a button (no teacher, marks missing, not
 * approved), and publishing asks first with that list. Published with
 * shortages only when the owner confirms; otherwise nothing changes.
 */
export default function PublicationPanel({
    window,
    stages,
    canManage,
}: {
    window: PublicationWindow;
    stages: PublicationStage[];
    canManage: boolean;
}) {
    const { t } = useTranslations();
    const [chosen, setChosen] = useState<Set<string>>(() => new Set());
    const [showShortages, setShowShortages] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const published = useMemo(
        () => new Set(window.sections_published),
        [window.sections_published],
    );
    const places = useMemo(
        () =>
            stages.flatMap((stage) =>
                Object.keys(stage.students).map((section) =>
                    placeKey(stage.stage, section),
                ),
            ),
        [stages],
    );
    // Only what is not published yet can be chosen.
    const open = places.filter((place) => !published.has(place));
    const all = open.length > 0 && open.every((place) => chosen.has(place));
    const shortages = useMemo(
        () => shortagesIn(t, stages, chosen),
        [t, stages, chosen],
    );
    const noTeacher = shortages.filter((row) => row.teacher === null).length;

    const toggle = (keys: string[], on: boolean) =>
        setChosen((before) => {
            const next = new Set(before);
            for (const key of keys) {
                if (on) {
                    next.add(key);
                } else {
                    next.delete(key);
                }
            }

            return next;
        });

    const hideSection = (stage: number, section: string) =>
        router.put(
            GradesAdminController.publishSection.url(),
            { stage: String(stage), section, published: false },
            { preserveScroll: true },
        );

    const publish = () => {
        const keys = open.filter((place) => chosen.has(place));
        // Every section chosen or already out: publish the whole period.
        const everything = places.every(
            (place) => chosen.has(place) || published.has(place),
        );
        const done = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setConfirming(false);
                setChosen(new Set());
                setShowShortages(false);
            },
        };

        if (everything) {
            router.put(
                GradesAdminController.publish.url(),
                { published: true },
                done,
            );
        } else {
            router.put(
                GradesAdminController.publishSections.url(),
                { places: keys },
                done,
            );
        }
    };

    const chosenLabels = stages.flatMap((stage) =>
        Object.keys(stage.students)
            .filter((section) => chosen.has(placeKey(stage.stage, section)))
            .map((section) =>
                placeLabel(t, stage.stage, section, stage.divided),
            ),
    );

    return (
        <section
            className="flex flex-col gap-4 rounded-lg border bg-card p-5"
            data-test="publication"
        >
            <header className="flex flex-wrap items-center gap-3">
                {window.published ? (
                    <Eye className="size-5 text-success-text" aria-hidden />
                ) : (
                    <EyeOff
                        className="size-5 text-muted-foreground"
                        aria-hidden
                    />
                )}
                <div className="flex-1">
                    <h2 className="font-semibold">
                        {t('Announce the results to students')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {window.published
                            ? t('Results are published to the students.')
                            : window.open
                              ? t('Close mark entry before publishing.')
                              : t(
                                    'Tick the stages and sections to publish, check what is missing, then publish.',
                                )}
                    </p>
                </div>
                {canManage && window.published && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.put(
                                GradesAdminController.publish.url(),
                                { published: false },
                                { preserveScroll: true },
                            )
                        }
                        data-test="unpublish"
                    >
                        {t('Hide the results')}
                    </Button>
                )}
            </header>

            {canManage && !window.published && (
                <>
                    <fieldset
                        className="flex flex-col gap-3"
                        disabled={window.open}
                        data-test="publish-places"
                    >
                        <legend className="mb-2 text-sm font-medium">
                            {t('Stages and sections')}
                        </legend>
                        <Tick
                            checked={all}
                            some={chosen.size > 0}
                            disabled={window.open || open.length === 0}
                            onChange={(on) => toggle(open, on)}
                            label={t('All stages and sections')}
                            strong
                            test="publish-all-places"
                        />
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {stages.map((stage) => {
                                const sections = Object.keys(stage.students);
                                const free = sections
                                    .map((section) =>
                                        placeKey(stage.stage, section),
                                    )
                                    .filter((key) => !published.has(key));
                                const every =
                                    free.length > 0 &&
                                    free.every((key) => chosen.has(key));
                                const some = free.some((key) =>
                                    chosen.has(key),
                                );

                                return (
                                    <div
                                        key={stage.stage}
                                        className="flex flex-col gap-2 rounded-md bg-muted p-3"
                                        data-test="publish-stage"
                                    >
                                        <Tick
                                            checked={every}
                                            some={some}
                                            disabled={
                                                window.open || free.length === 0
                                            }
                                            onChange={(on) => toggle(free, on)}
                                            label={stageLabel(t, stage.stage)}
                                            strong
                                            test="publish-stage-box"
                                        />
                                        <div
                                            className={cn(
                                                'flex flex-col gap-2',
                                                stage.divided && 'ps-6',
                                            )}
                                        >
                                            {sections.map((section) => {
                                                const key = placeKey(
                                                    stage.stage,
                                                    section,
                                                );
                                                const out = published.has(key);

                                                if (!out && !stage.divided) {
                                                    return null;
                                                }

                                                return (
                                                    <div
                                                        key={key}
                                                        className="flex flex-wrap items-center gap-2"
                                                    >
                                                        {stage.divided && (
                                                            <Tick
                                                                checked={
                                                                    out ||
                                                                    chosen.has(
                                                                        key,
                                                                    )
                                                                }
                                                                disabled={
                                                                    out ||
                                                                    window.open
                                                                }
                                                                onChange={(
                                                                    on,
                                                                ) =>
                                                                    toggle(
                                                                        [key],
                                                                        on,
                                                                    )
                                                                }
                                                                label={sectionLabel(
                                                                    t,
                                                                    section,
                                                                    true,
                                                                )}
                                                                test="publish-section-box"
                                                            />
                                                        )}
                                                        {out && (
                                                            <>
                                                                <Badge data-test="section-published">
                                                                    {t(
                                                                        'Published',
                                                                    )}
                                                                </Badge>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-6 px-2 text-xs"
                                                                    onClick={() =>
                                                                        hideSection(
                                                                            stage.stage,
                                                                            section,
                                                                        )
                                                                    }
                                                                    data-test="hide-section"
                                                                >
                                                                    {t('Hide')}
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </fieldset>

                    {showShortages && chosen.size > 0 && (
                        <div className="flex flex-col gap-2">
                            {shortages.length > 0 ? (
                                <ShortageTable rows={shortages} />
                            ) : (
                                <p className="flex items-center gap-2 text-sm text-success-text">
                                    <CheckCircle2
                                        className="size-4"
                                        aria-hidden
                                    />
                                    {t(
                                        'Every subject has a teacher, and every mark is entered and approved.',
                                    )}
                                </p>
                            )}
                        </div>
                    )}

                    <footer className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={chosen.size === 0}
                            aria-expanded={showShortages}
                            onClick={() => setShowShortages((on) => !on)}
                            data-test="show-shortages"
                        >
                            {shortages.length > 0 ? (
                                <AlertTriangle
                                    className="size-4 text-warning-text"
                                    aria-hidden
                                />
                            ) : (
                                <CheckCircle2
                                    className="size-4 text-success-text"
                                    aria-hidden
                                />
                            )}
                            {showShortages
                                ? t('Hide what is missing')
                                : t('What is missing (:count)', {
                                      count: String(shortages.length),
                                  })}
                        </Button>
                        <Button
                            type="button"
                            disabled={window.open || chosen.size === 0}
                            onClick={() => setConfirming(true)}
                            data-test="publish"
                        >
                            {all && published.size === 0
                                ? t('Publish the results')
                                : t('Publish the chosen results')}
                        </Button>
                    </footer>

                    <Dialog open={confirming} onOpenChange={setConfirming}>
                        <DialogContent
                            className="sm:max-w-3xl"
                            data-test="publish-dialog"
                        >
                            <DialogTitle>
                                {t('Publish the results?')}
                            </DialogTitle>
                            <DialogDescription>
                                {chosenLabels.join('، ')}
                            </DialogDescription>
                            {shortages.length > 0 ? (
                                <div className="flex max-h-[55vh] flex-col gap-3 overflow-y-auto">
                                    <p
                                        className="flex items-start gap-2 rounded-md border bg-card p-3 text-sm font-medium text-warning-text"
                                        data-test="publish-warning"
                                    >
                                        <AlertTriangle
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden
                                        />
                                        {noTeacher > 0
                                            ? t(
                                                  ':count subject sections are not ready; :none of them have no teacher. Students will see these results as they are.',
                                                  {
                                                      count: String(
                                                          shortages.length,
                                                      ),
                                                      none: String(noTeacher),
                                                  },
                                              )
                                            : t(
                                                  ':count subject sections are not ready. Students will see these results as they are.',
                                                  {
                                                      count: String(
                                                          shortages.length,
                                                      ),
                                                  },
                                              )}
                                    </p>
                                    <ShortageTable rows={shortages} />
                                </div>
                            ) : (
                                <p className="flex items-center gap-2 text-sm">
                                    <CheckCircle2
                                        className="size-4 text-success-text"
                                        aria-hidden
                                    />
                                    {t(
                                        'Every mark is entered and approved. Each student will see their own result with their code.',
                                    )}
                                </p>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        variant="outline"
                                        data-test="publish-cancel"
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant={
                                        shortages.length > 0
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={processing}
                                    onClick={publish}
                                    data-test="publish-confirm"
                                >
                                    {shortages.length > 0
                                        ? t('Publish anyway')
                                        : t('Publish')}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </>
            )}
        </section>
    );
}
