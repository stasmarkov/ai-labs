export type DiffKind = 'same' | 'added' | 'removed';

export interface DiffLine {
    kind: DiffKind;
    text: string;
}

/**
 * A line diff of two texts from the longest common subsequence of their lines. It is meant for prompts, which are at
 * most a few hundred lines, so the quadratic table is small.
 */
export function diffLines(before: string, after: string): DiffLine[] {
    const a = before.split('\n');
    const b = after.split('\n');
    const table: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i][j] = a[i] === b[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    const lines: DiffLine[] = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            lines.push({ kind: 'same', text: a[i] });
            i++;
            j++;
        } else if (table[i + 1][j] >= table[i][j + 1]) {
            lines.push({ kind: 'removed', text: a[i] });
            i++;
        } else {
            lines.push({ kind: 'added', text: b[j] });
            j++;
        }
    }

    for (; i < a.length; i++) {
        lines.push({ kind: 'removed', text: a[i] });
    }

    for (; j < b.length; j++) {
        lines.push({ kind: 'added', text: b[j] });
    }

    return lines;
}
