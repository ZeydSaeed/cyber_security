import type { useTranslations } from '@/hooks/use-translations';
import type { Band, MarkTones, MarkValue, ResultCell, Tone } from './types';

type Translate = ReturnType<typeof useTranslations>['t'];

/** A grade band in words (ADR-0039). */
export function bandLabel(t: Translate, band: Band): string {
    return {
        excellent: t('Excellent'),
        very_good: t('Very good'),
        good: t('Good'),
        fair: t('Fair'),
        pass: t('Pass'),
        fail: t('Fail'),
    }[band];
}

export function behaviourLabel(t: Translate, behaviour: string): string {
    return (
        {
            excellent: t('Excellent'),
            very_good: t('Very good'),
            good: t('Good'),
            acceptable: t('Acceptable'),
            poor: t('Poor'),
        }[behaviour] ?? behaviour
    );
}

export function kindLabel(t: Translate, kind: string): string {
    return kind === 'practical' ? t('Practical') : t('Theoretical');
}

/**
 * The status tone's text class (design system §6: status `-text` tokens);
 * never a colour value. A tone always goes with words.
 */
export function toneClass(tone: Tone): string {
    return {
        none: '',
        success: 'font-semibold text-success-text',
        warning: 'font-semibold text-warning-text',
        info: 'font-semibold text-info-text',
        danger: 'font-semibold text-destructive-text',
    }[tone];
}

/** The theme token of a tone, for the downloaded image (canvas). */
export function toneToken(tone: Tone): string | null {
    return {
        none: null,
        success: 'success-text',
        warning: 'warning-text',
        info: 'info-text',
        danger: 'destructive-text',
    }[tone];
}

/**
 * The tone a mark takes: failed or absent, exempt, excellent, or fair, as
 * the owner chose (ADR-0039).
 */
export function markTone(
    mark: MarkValue,
    cell: ResultCell,
    tones: MarkTones,
): Tone {
    if (mark === null || cell === null) {
        return 'none';
    }
    if (mark === 'absent' || !cell.passed) {
        return tones.fail;
    }
    if (cell.exempt) {
        return tones.exempt;
    }
    if (cell.band === 'excellent') {
        return tones.excellent;
    }
    if (cell.band === 'fair') {
        return tones.fair;
    }

    return 'none';
}
