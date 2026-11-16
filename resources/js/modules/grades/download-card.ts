import logoUrl from '@/../images/logo.png';
import type { useTranslations } from '@/hooks/use-translations';
import { stageName } from './place';
import type { MarkValue, ResultRow } from './types';

type Translate = ReturnType<typeof useTranslations>['t'];

export type CardData = {
    stage: number;
    section: string;
    divided: boolean;
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    student: ResultRow;
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

    const { student, subjects, pass_mark } = card;
    const width = 1240;
    const pad = 70;
    const rowHeight = 64;
    const tableTop = 400;
    const rows = subjects.length + 2; // head, subjects, total
    const height = tableTop + rows * rowHeight + 220;
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

    const markText = (mark: MarkValue) =>
        mark === null ? '—' : mark === 'absent' ? t('Absent') : String(mark);
    const statusOf = (mark: MarkValue): [string, boolean] => {
        if (mark === null) {
            return [t('Not entered yet'), false];
        }
        if (mark === 'absent') {
            return [t('Absent'), true];
        }

        return mark >= pass_mark
            ? [t('Passed'), false]
            : [t('Below the pass mark'), true];
    };

    const lines: {
        cells: [string, string, string];
        bold: boolean;
        danger: boolean;
    }[] = [
        {
            cells: [t('Subject'), t('Mark out of 100'), t('Status')],
            bold: true,
            danger: false,
        },
        ...subjects.map((subject, i) => {
            const mark = student.marks[i] ?? null;
            const [status, danger] = statusOf(mark);

            return {
                cells: [subject.name, markText(mark), status] as [
                    string,
                    string,
                    string,
                ],
                bold: false,
                danger,
            };
        }),
        {
            cells: [
                t('Total'),
                `${student.total} / ${student.maximum}`,
                student.complete
                    ? `${student.percentage.toFixed(2)}%`
                    : t('Incomplete (:missing missing)', {
                          missing: String(student.missing),
                      }),
            ],
            bold: true,
            danger: false,
        },
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
                            : col === 2 && line.danger
                              ? colors.danger
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
    const outcome = !student.complete
        ? t('Some marks are not entered yet.')
        : student.passed
          ? t('Passed every subject.')
          : t('Below the pass mark in :count subjects.', {
                count: String(student.below),
            });
    text(outcome, x(pad), after + 70, 28, { weight: 600 });
    text(
        t(
            'Each subject is out of 100; :mark is the pass mark. An absence counts as 0.',
            { mark: String(pass_mark) },
        ),
        x(pad),
        after + 130,
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
