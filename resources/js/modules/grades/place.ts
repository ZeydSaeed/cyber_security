import type { useTranslations } from '@/hooks/use-translations';

type Translate = ReturnType<typeof useTranslations>['t'];

/**
 * A stage and section as people read them (ADR-0034): "Stage 2 · Section A",
 * or only "Stage 1" for a stage that is not divided into sections (the
 * server says which, from Stage::sections()).
 */
export function placeLabel(
    t: Translate,
    stage: number,
    section: string,
    divided: boolean,
): string {
    const stageText = t('Stage :stage', { stage: String(stage) });

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
