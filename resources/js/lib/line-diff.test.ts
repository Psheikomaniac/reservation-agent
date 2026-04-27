import { describe, expect, it } from 'vitest';
import { lineDiff } from './line-diff';

describe('lineDiff', () => {
    it('returns all-context when the two strings are identical', () => {
        const lines = lineDiff('A\nB\nC', 'A\nB\nC');
        expect(lines.map((l) => l.kind)).toEqual(['context', 'context', 'context']);
    });

    it('marks an inserted middle line as added', () => {
        const lines = lineDiff('A\nC', 'A\nB\nC');
        expect(lines).toEqual([
            { kind: 'context', text: 'A' },
            { kind: 'added', text: 'B' },
            { kind: 'context', text: 'C' },
        ]);
    });

    it('marks a removed middle line', () => {
        const lines = lineDiff('A\nB\nC', 'A\nC');
        expect(lines).toEqual([
            { kind: 'context', text: 'A' },
            { kind: 'removed', text: 'B' },
            { kind: 'context', text: 'C' },
        ]);
    });

    it('handles a replacement as removal-then-addition', () => {
        const lines = lineDiff('A\nB\nC', 'A\nX\nC');
        expect(lines).toEqual([
            { kind: 'context', text: 'A' },
            { kind: 'removed', text: 'B' },
            { kind: 'added', text: 'X' },
            { kind: 'context', text: 'C' },
        ]);
    });

    it('treats a fully rewritten body as removal of all old + addition of all new', () => {
        const lines = lineDiff('A\nB', 'X\nY');
        expect(lines.filter((l) => l.kind === 'removed').map((l) => l.text)).toEqual(['A', 'B']);
        expect(lines.filter((l) => l.kind === 'added').map((l) => l.text)).toEqual(['X', 'Y']);
    });

    it('preserves blank lines as context', () => {
        const lines = lineDiff('A\n\nB', 'A\n\nB');
        expect(lines).toEqual([
            { kind: 'context', text: 'A' },
            { kind: 'context', text: '' },
            { kind: 'context', text: 'B' },
        ]);
    });

    it('handles an empty original (everything is added)', () => {
        const lines = lineDiff('', 'X');
        expect(lines).toEqual([
            { kind: 'removed', text: '' },
            { kind: 'added', text: 'X' },
        ]);
    });

    it('handles an empty edit (everything is removed)', () => {
        const lines = lineDiff('A\nB', '');
        const removals = lines.filter((l) => l.kind === 'removed').map((l) => l.text);
        expect(removals).toEqual(['A', 'B']);
    });
});
