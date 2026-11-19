export interface TextPart {
    kind: 'plain' | 'bold' | 'code';
    text: string;
}

/**
 * Splits lesson text into plain, **bold** and `code` parts. The parts are rendered as text, never as HTML.
 */
export function textParts(text: string): TextPart[] {
    const parts: TextPart[] = [];
    const pattern = /\*\*(.+?)\*\*|`(.+?)`/g;
    let last = 0;

    for (const match of text.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > last) {
            parts.push({ kind: 'plain', text: text.slice(last, index) });
        }

        parts.push(match[1] !== undefined ? { kind: 'bold', text: match[1] } : { kind: 'code', text: match[2] });
        last = index + match[0].length;
    }

    if (last < text.length) {
        parts.push({ kind: 'plain', text: text.slice(last) });
    }

    return parts;
}
