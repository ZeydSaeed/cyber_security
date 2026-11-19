import logoUrl from '@/../images/logo.png';
import type { useTranslations } from '@/hooks/use-translations';
import { stageName } from './place';
import { bandLabel, behaviourLabel, markTone, toneToken } from './result-words';
import type { MarkValue, ResultCardData } from './types';

type Translate = ReturnType<typeof useTranslations>['t'];

export type CardData = ResultCardData & {
    /** The student's code, shown as their ID on the owner's card. */
    code?: string | null;
};

/**
 * Saves one student's result card as a PNG image (owner's request,
 * 2026-11-16: "download the result to the phone or the computer"). It is
 * drawn on a canvas from the same data the page shows, so it needs no
 * library and works on phones. Colours are read from the theme tokens of
 * the page (design system §11): no colour is written here.
 */
export async function downloadResultCard(
    t: Translate,
    card: CardData,
    appName: string,
): Promise<void> {
    const rtl = document.documentElement.dir === 'rtl';
    const font =
        getComputedStyle(document.body).fontFamily || 'system-ui, sans-serif';
    // A token resolved by the browser to a plain colour (a token may be a
    // color-mix(), which a canvas does not always accept).
    const probe = document.createElement('span');
    probe.hidden = true;
    document.body.appendChild(probe);
    const token = (name: string): string => {
        probe.style.color = `var(--${name})`;

        return getComputedStyle(probe).color;
    };
    // Each mark's tone, resolved while the probe is on the page (ADR-0039).
    const toneColors = card.subjects.map((_, i) => {
        const name = toneToken(
            markTone(
                card.student.marks[i] ?? null,
                card.student.cells[i] ?? null,
                card.tones,
            ),
        );

        return name === null ? null : token(name);
    });
    const colors = {
        page: token('background'),
        text: token('foreground'),
        muted: token('muted-foreground'),
        danger: token('destructive-text'),
        sheet: token('sheet'),
        line: token('border-strong'),
        head: token('table-head'),
        headText: token('table-head-foreground'),
    };
    probe.remove();

    await document.fonts.ready;
    const logo = new Image();
    logo.src = logoUrl;
    await logo.decode();

    const { student, subjects, options } = card;
    const showTotal =
        (options.total || options.percentage) && student.total !== null;
    const facts = [
        options.rank && student.rank !== null
            ? `${t('Rank in the section')}: ${student.rank}`
            : null,
        options.overall_grade && student.band !== null
            ? `${t('Overall grade')}: ${bandLabel(t, student.band)}`
            : null,
        options.exemption && student.exempt_all
            ? `${t('General exemption')}: ${t('Exempt from every subject')}`
            : null,
        options.behaviour && student.behaviour !== null
            ? `${t('Behaviour')}: ${behaviourLabel(t, student.behaviour)}`
            : null,
    ].filter((line): line is string => line !== null);
    const width = 1240;
    const pad = 70;
    const rowHeight = 64;
    const tableTop = 400;
    const rows = subjects.length + (showTotal ? 2 : 1); // head, subjects, total
    const height = tableTop + rows * rowHeight + 220 + facts.length * 44;
    const scale = 2;

    const canvas = document.createElement('canvas');
    canvas.width = width * scale;
    canvas.height = height * scale;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        throw new Error('Canvas is not available.');
    }
    ctx.scale(scale, scale);
    ctx.direction = rtl ? 'rtl' : 'ltr';
    ctx.textBaseline = 'middle';

    // Positions measured from the start edge (right in Arabic).
    const x = (fromStart: number) => (rtl ? width - fromStart : fromStart);
    const alignStart: CanvasTextAlign = rtl ? 'right' : 'left';
    const text = (
        value: string,
        at: number,
        y: number,
        size: number,
        options: {
            weight?: number;
            color?: string;
            align?: CanvasTextAlign;
        } = {},
    ) => {
        ctx.font = `${options.weight ?? 400} ${size}px ${font}`;
        ctx.fillStyle = options.color ?? colors.text;
        ctx.textAlign = options.align ?? alignStart;
        ctx.fillText(value, at, y);
    };

    ctx.fillStyle = colors.page;
    ctx.fillRect(0, 0, width, height);

    // Header: seal and name at the start, title in the middle.
    const logoSize = 130;
    const logoCentre = pad + logoSize / 2;
    ctx.drawImage(logo, x(logoCentre) - logoSize / 2, 40, logoSize, logoSize);
    text(appName, x(logoCentre), 195, 24, { weight: 600, align: 'center' });
    text(t('First-month result card'), width / 2, 110, 44, {
        weight: 700,
        align: 'center',
    });
    ctx.strokeStyle = colors.line;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(pad, 240);
    ctx.lineTo(width - pad, 240);
    ctx.stroke();

    // Details on one line, spread from edge to edge (owner, 2026-11-16).
    const details = [
        [t('Name'), student.name],
        [t('Stage'), stageName(t, card.stage)],
        [t('Section'), card.divided ? card.section : '—'],
        [t('Student ID'), card.code ?? student.number ?? '—'],
    ].map(([label, value]) => `${label}: ${value}`);
    ctx.font = `600 26px ${font}`;
    const widths = details.map((item) => ctx.measureText(item).width);
    const used = widths.reduce((sum, w) => sum + w, 0);
    const gap = Math.max(32, (width - pad * 2 - used) / (details.length - 1));
    let cursor = pad;
    details.forEach((item, i) => {
        text(item, x(cursor), 310, 26, { weight: 600 });
        cursor += widths[i] + gap;
    });

    // Table: subject, mark, status.
    const tableWidth = width - pad * 2;
    const columns = [tableWidth * 0.5, tableWidth * 0.25, tableWidth * 0.25];
    ctx.fillStyle = colors.sheet;
    ctx.fillRect(pad, tableTop, tableWidth, rows * rowHeight);
    // The head row in the table-head colour, as on the page.
    ctx.fillStyle = colors.head;
    ctx.fillRect(pad, tableTop, tableWidth, rowHeight);

    const markText = (mark: MarkValue, max: number) =>
        mark === null
            ? '—'
            : (mark === 'absent' ? t('Absent') : String(mark)) +
              (options.max_mark ? ` / ${max}` : '');
    const statusOf = (i: number): string => {
        const mark = student.marks[i] ?? null;
        const cell = student.cells[i] ?? null;
        if (mark === null || cell === null) {
            return t('Not entered yet');
        }
        const words = [
            mark === 'absent'
                ? t('Absent')
                : cell.passed
                  ? t('Passed')
                  : t('Below the pass mark'),
        ];
        if (options.mark_grade && cell.band && mark !== 'absent') {
            words.push(bandLabel(t, cell.band));
        }
        if (options.exemption && cell.exempt) {
            words.push(t('Exempt'));
        }

        return words.join(' · ');
    };

    const lines: {
        cells: [string, string, string];
        bold: boolean;
        color: string | null;
    }[] = [
        {
            cells: [t('Subject'), t('Mark'), t('Status')],
            bold: true,
            color: null,
        },
        ...subjects.map((subject, i) => ({
            cells: [
                subject.name,
                markText(student.marks[i] ?? null, subject.max),
                statusOf(i),
            ] as [string, string, string],
            bold: false,
            color: toneColors[i] ?? null,
        })),
        ...(showTotal
            ? [
                  {
                      cells: [
                          t('Total'),
                          options.total
                              ? options.max_mark
                                  ? `${student.total} / ${student.maximum}`
                                  : String(student.total)
                              : '—',
                          !student.complete
                              ? t('Incomplete (:missing missing)', {
                                    missing: String(student.missing),
                                })
                              : options.percentage &&
                                  student.percentage !== null
                                ? `${student.percentage.toFixed(2)}%`
                                : '—',
                      ] as [string, string, string],
                      bold: true,
                      color: null,
                  },
              ]
            : []),
    ];

    lines.forEach((line, row) => {
        const y = tableTop + row * rowHeight + rowHeight / 2;
        let start = pad;
        line.cells.forEach((cell, col) => {
            const first = col === 0;
            text(
                cell,
                first ? x(start + 18) : x(start + columns[col] / 2),
                y,
                24,
                {
                    weight: line.bold ? 700 : 400,
                    color:
                        row === 0
                            ? colors.headText
                            : col === 2 && line.color
                              ? line.color
                              : colors.text,
                    align: first ? alignStart : 'center',
                },
            );
            start += columns[col];
        });
    });

    // Grid lines.
    ctx.strokeStyle = colors.line;
    for (let row = 0; row <= rows; row++) {
        ctx.lineWidth = row === 0 || row === rows ? 2 : 1;
        ctx.beginPath();
        ctx.moveTo(pad, tableTop + row * rowHeight);
        ctx.lineTo(width - pad, tableTop + row * rowHeight);
        ctx.stroke();
    }
    let edge = pad;
    [0, ...columns].forEach((column, i) => {
        edge += column;
        ctx.lineWidth = i === 0 || i === columns.length ? 2 : 1;
        ctx.beginPath();
        ctx.moveTo(x(edge), tableTop);
        ctx.lineTo(x(edge), tableTop + rows * rowHeight);
        ctx.stroke();
    });

    // Outcome and note.
    const after = tableTop + rows * rowHeight;
    facts.forEach((line, i) => {
        text(line, x(pad), after + 50 + i * 44, 26, { weight: 600 });
    });
    const below = after + facts.length * 44;
    const outcome = !student.complete
        ? t('Some marks are not entered yet.')
        : student.passed
          ? t('Passed every subject.')
          : student.below === null
            ? t('Not every subject is passed.')
            : t('Below the pass mark in :count subjects.', {
                  count: String(student.below),
              });
    text(outcome, x(pad), below + 70, 28, { weight: 600 });
    text(
        t(
            'Each subject is passed at :percent% of its maximum unless set otherwise. An absence counts as 0.',
            { percent: String(card.pass_percent) },
        ),
        x(pad),
        below + 130,
        22,
        { color: colors.muted },
    );

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/png'),
    );
    if (!blob) {
        throw new Error('The image could not be made.');
    }
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `result-${student.number ?? student.uuid}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
