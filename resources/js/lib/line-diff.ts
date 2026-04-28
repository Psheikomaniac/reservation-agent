/**
 * Line-level diff between an original string and an edited one. Walks
 * both arrays once with two cursors and a small look-ahead so that
 * inserted-only / deleted-only / replaced lines render correctly without
 * the cost of pulling in a full LCS dependency.
 *
 * Surfaced in the dashboard drawer (PRD-005 / issue #82) so the
 * operator can see what they changed against the AI draft before
 * approving — the placebo-click mitigation called out in PRD-005's
 * "Risiken" section.
 */
export type DiffLineKind = 'added' | 'removed' | 'context';

export interface DiffLine {
    kind: DiffLineKind;
    text: string;
}

export function lineDiff(original: string, edited: string): DiffLine[] {
    const oldLines = original.split('\n');
    const newLines = edited.split('\n');
    const out: DiffLine[] = [];

    let i = 0;
    let j = 0;
    while (i < oldLines.length || j < newLines.length) {
        if (i < oldLines.length && j < newLines.length && oldLines[i] === newLines[j]) {
            out.push({ kind: 'context', text: oldLines[i] });
            i++;
            j++;
            continue;
        }

        // Look ahead in newLines for the next reappearance of the
        // current oldLine — everything in between is an insertion.
        const nextMatchInNew = i < oldLines.length ? newLines.indexOf(oldLines[i], j) : -1;
        if (nextMatchInNew !== -1) {
            while (j < nextMatchInNew) {
                out.push({ kind: 'added', text: newLines[j] });
                j++;
            }
            continue;
        }

        // Otherwise the current oldLine has no future match — removal.
        // Mirror remaining newLines as additions when oldLines is exhausted.
        if (i < oldLines.length) {
            out.push({ kind: 'removed', text: oldLines[i] });
            i++;
        } else if (j < newLines.length) {
            out.push({ kind: 'added', text: newLines[j] });
            j++;
        }
    }

    return out;
}
