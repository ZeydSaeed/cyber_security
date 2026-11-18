/**
 * Finding a student by name as people type it (owner's request,
 * 2026-11-18), with the same folding as the server's NameKey: alef with or
 * without hamza or madda, teh marbuta or heh, alef maksura or yeh, hamza on
 * waw or yeh, diacritics, tatweel, invisible marks, letter case and extra
 * spaces make no difference. Each typed word must appear in the name, in
 * any order and as part of a word ("ahm sal" finds "Ahmed Saleh").
 */

// Written as code points: the formatter turns \u escapes into invisible
// characters.
const LETTERS: Record<string, string> = Object.fromEntries(
    [
        [0x0623, 0x0627], // alef with hamza above -> alef
        [0x0625, 0x0627], // alef with hamza below -> alef
        [0x0622, 0x0627], // alef with madda -> alef
        [0x0671, 0x0627], // alef wasla -> alef
        [0x0629, 0x0647], // teh marbuta -> heh
        [0x0649, 0x064a], // alef maksura -> yeh
        [0x0624, 0x0648], // waw with hamza -> waw
        [0x0626, 0x064a], // yeh with hamza -> yeh
    ].map(([from, to]) => [String.fromCharCode(from), String.fromCharCode(to)]),
);

/** Diacritics, superscript alef, tatweel, direction and zero-width marks. */
const DROPPED: [number, number][] = [
    [0x064b, 0x065f],
    [0x0670, 0x0670],
    [0x0640, 0x0640],
    [0x200b, 0x200f],
    [0x202a, 0x202e],
    [0x2066, 0x2069],
    [0xfeff, 0xfeff],
];

function dropped(char: string): boolean {
    const code = char.charCodeAt(0);

    return DROPPED.some(([low, high]) => code >= low && code <= high);
}

/**
 * The folded text, and for each of its characters the index of the
 * character it came from, so a match can be shown on the name as written.
 */
function fold(text: string): { folded: string; from: number[] } {
    let folded = '';
    const from: number[] = [];

    for (let i = 0; i < text.length; i++) {
        const char = text[i];

        if (dropped(char)) {
            continue;
        }

        if (/\s/.test(char)) {
            // A run of spaces is one space; none at the start.
            if (folded !== '' && !folded.endsWith(' ')) {
                folded += ' ';
                from.push(i);
            }

            continue;
        }

        const lower = char.toLowerCase();
        folded += LETTERS[lower] ?? lower;
        from.push(i);
    }

    return { folded, from };
}

/** The typed words, folded; none for an empty search. */
export function searchTerms(search: string): string[] {
    return fold(search)
        .folded.split(' ')
        .filter((term) => term !== '');
}

/**
 * Where the terms appear in the name, as [start, end) ranges of the name
 * as written, merged and in order; null when a term is missing (or there
 * are no terms).
 */
export function matchRanges(
    name: string,
    terms: string[],
): [number, number][] | null {
    if (terms.length === 0) {
        return null;
    }

    const spaced = fold(name);
    // The name without its spaces too, so "عبدالله" finds "عبد الله".
    const joined = { folded: '', from: [] as number[] };
    for (let i = 0; i < spaced.folded.length; i++) {
        if (spaced.folded[i] !== ' ') {
            joined.folded += spaced.folded[i];
            joined.from.push(spaced.from[i]);
        }
    }
    const ranges: [number, number][] = [];

    for (const term of terms) {
        const { folded, from } =
            spaced.folded.includes(term) || term.includes(' ')
                ? spaced
                : joined;
        let at = folded.indexOf(term);

        if (at === -1) {
            return null;
        }

        while (at !== -1) {
            const end = at + term.length - 1;
            ranges.push([from[at], from[end] + 1]);
            at = folded.indexOf(term, at + 1);
        }
    }

    ranges.sort((a, b) => a[0] - b[0]);
    const merged: [number, number][] = [];

    for (const range of ranges) {
        const last = merged[merged.length - 1];

        if (last && range[0] <= last[1]) {
            last[1] = Math.max(last[1], range[1]);
        } else {
            merged.push([range[0], range[1]]);
        }
    }

    // A diacritic right after a matched letter belongs to it.
    for (const range of merged) {
        while (range[1] < name.length && dropped(name[range[1]])) {
            range[1]++;
        }
    }

    return merged;
}
