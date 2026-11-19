import type { SceneEdge, SceneNode } from './lessonTypes';

/** Card size in scene units; node x and y are the card centres. */
export const cardWidth = 212;
export const cardHeight = 112;

/** Room under a card for its badge pill, and around the whole scene. */
const badgeRoom = 44;
const margin = 22;

/** Gap between a card side and the end of a connector, and between the two lanes of a two-way pair. */
const gap = 6;
const arrowRoom = 8;
const lane = 11;

/** The fitted scene keeps an aspect ratio between these, so it is neither a strip nor a tower. */
const minAspect = 1.45;
const maxAspect = 1.8;

/** A connector is one quadratic curve: straight when the control point is the midpoint, an elbow when it is the corner. */
export interface Curve {
    x1: number;
    y1: number;
    cx: number;
    cy: number;
    x2: number;
    y2: number;
}

export interface ViewBox {
    x: number;
    y: number;
    width: number;
    height: number;
}

/**
 * The part of the authoring grid the nodes use, with room for cards and badges, widened or heightened to a sensible
 * aspect ratio. It is fitted to every node of the lesson, not only the visible ones, so the scene never zooms between steps.
 */
export function fitViewBox(nodes: SceneNode[]): ViewBox {
    if (nodes.length === 0) {
        return { x: 0, y: 0, width: 1200, height: 600 };
    }

    const left = Math.min(...nodes.map((node) => node.x)) - cardWidth / 2 - margin;
    const right = Math.max(...nodes.map((node) => node.x)) + cardWidth / 2 + margin;
    const top = Math.min(...nodes.map((node) => node.y)) - cardHeight / 2 - margin;
    const bottom = Math.max(...nodes.map((node) => node.y)) + cardHeight / 2 + badgeRoom;

    let width = right - left;
    let height = bottom - top;
    let x = left;
    let y = top;

    if (width / height > maxAspect) {
        const grown = width / maxAspect;
        y -= (grown - height) / 2;
        height = grown;
    } else if (width / height < minAspect) {
        const grown = height * minAspect;
        x -= (grown - width) / 2;
        width = grown;
    }

    return { x: Math.round(x), y: Math.round(y), width: Math.round(width), height: Math.round(height) };
}

function sign(value: number): number {
    return value < 0 ? -1 : 1;
}

/**
 * Lays one connector out between two cards. Nodes on a row or a column get a straight connector between the facing sides;
 * anything else gets an elbow that leaves the higher card from its side and enters the lower card from its top or bottom.
 * When the edge has a partner going the other way, each takes its own lane so request and answer packets do not overlap.
 */
export function curveFor(edge: SceneEdge, from: SceneNode, to: SceneNode, twoWay: boolean): Curve {
    const offset = twoWay ? (edge.from < edge.to ? lane : -lane) : 0;
    const dx = to.x - from.x;
    const dy = to.y - from.y;

    if (Math.abs(dy) < 30) {
        const x1 = from.x + sign(dx) * (cardWidth / 2 + gap);
        const x2 = to.x - sign(dx) * (cardWidth / 2 + gap + arrowRoom);
        const y1 = from.y + offset;
        const y2 = to.y + offset;

        return { x1, y1, x2, y2, cx: (x1 + x2) / 2, cy: (y1 + y2) / 2 };
    }

    if (Math.abs(dx) < 30) {
        const y1 = from.y + sign(dy) * (cardHeight / 2 + gap);
        const y2 = to.y - sign(dy) * (cardHeight / 2 + gap + arrowRoom);
        const x1 = from.x + offset;
        const x2 = to.x + offset;

        return { x1, y1, x2, y2, cx: (x1 + x2) / 2, cy: (y1 + y2) / 2 };
    }

    const fromIsHigher = from.y < to.y;
    const higher = fromIsHigher ? from : to;
    const lower = fromIsHigher ? to : from;
    const inward = -sign(lower.x - higher.x);
    const shiftX = inward * offset;
    const shiftY = sign(lower.y - higher.y) * offset;

    const side = {
        x: higher.x + sign(lower.x - higher.x) * (cardWidth / 2 + gap),
        y: higher.y + shiftY,
    };
    const end = {
        x: lower.x + shiftX,
        y: lower.y - sign(lower.y - higher.y) * (cardHeight / 2 + gap),
    };
    const corner = { cx: lower.x + shiftX, cy: higher.y + shiftY };

    if (fromIsHigher) {
        return { x1: side.x, y1: side.y, ...corner, x2: end.x, y2: end.y - sign(lower.y - higher.y) * arrowRoom };
    }

    return { x1: end.x, y1: end.y, ...corner, x2: side.x + sign(lower.x - higher.x) * arrowRoom, y2: side.y };
}

/** A point on a curve, progress 0 at the start and 1 at the end. */
export function pointOn(curve: Curve, progress: number): { x: number; y: number } {
    const rest = 1 - progress;

    return {
        x: rest * rest * curve.x1 + 2 * rest * progress * curve.cx + progress * progress * curve.x2,
        y: rest * rest * curve.y1 + 2 * rest * progress * curve.cy + progress * progress * curve.y2,
    };
}

export function pathOf(curve: Curve): string {
    return `M ${curve.x1} ${curve.y1} Q ${curve.cx} ${curve.cy} ${curve.x2} ${curve.y2}`;
}

/**
 * Splits a card title into at most two lines of about `width` characters, at word boundaries.
 */
export function titleLines(label: string, width = 12): string[] {
    if (label.length <= width) {
        return [label];
    }

    const words = label.split(' ');
    let first = '';

    while (words.length > 0 && (first === '' || `${first} ${words[0]}`.length <= width)) {
        first = first === '' ? (words.shift() as string) : `${first} ${words.shift()}`;
    }

    return words.length > 0 ? [first, words.join(' ')] : [first];
}
