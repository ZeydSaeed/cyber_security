import type { useTranslations } from '@/hooks/use-translations';

type Translate = ReturnType<typeof useTranslations>['t'];

/**
 * A stage and section as people read them (ADR-0034): "Second stage ·
 * Section A", or only "First stage" for a stage that is not divided into
 * sections (the server says which, from Stage::sections()). A stage is
 * never shown as a number (owner's request, 2026-11-16).
 */
export function placeLabel(
    t: Translate,
    stage: number,
    section: string,
    divided: boolean,
): string {
    const stageText = stageLabel(t, stage);

    return divided
        ? `${stageText} · ${t('Section :section', { section })}`
        : stageText;
}

/** A section within its stage: "Section A", or "All students" when undivided. */
export function sectionLabel(
    t: Translate,
    section: string,
    divided: boolean,
): string {
    return divided ? t('Section :section', { section }) : t('All students');
}

/**
 * The long form for a student's own page (owner's request, 2026-11-15):
 * "Stage: First · Section: A", or only "Stage: First" when undivided.
 */
export function placeLabelFull(
    t: Translate,
    stage: number,
    section: string,
    divided: boolean,
): string {
    const stageText = t('Stage: :name', { name: stageName(t, stage) });

    return divided
        ? `${stageText} · ${t('Section: :section', { section })}`
        : stageText;
}

/** A stage by its name: "First stage", "Second stage" or "Third stage". */
export function stageLabel(t: Translate, stage: number): string {
    switch (stage) {
        case 1:
            return t('First stage');
        case 2:
            return t('Second stage');
        default:
            return t('Third stage');
    }
}

/** A stage by its ordinal name: "First", "Second" or "Third". */
export function stageName(t: Translate, stage: number): string {
    switch (stage) {
        case 1:
            return t('First');
        case 2:
            return t('Second');
        default:
            return t('Third');
    }
}
