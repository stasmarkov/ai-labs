export type LessonAction = 'previous' | 'next' | 'replay' | 'pause';

const typingTags = ['INPUT', 'TEXTAREA', 'SELECT'];
const nativeSpaceTags = ['BUTTON', 'A'];

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return typingTags.includes(target.tagName) || target.isContentEditable;
}

function keepsNativeSpace(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return nativeSpaceTags.includes(target.tagName) || target.getAttribute('role') === 'button';
}

/**
 * Maps a key press to a player action, or null when the key must be left alone: a modifier is held, focus is in a
 * field, or Space is pressed on a button or link where it keeps its native action.
 */
export function lessonActionFor(event: KeyboardEvent): LessonAction | null {
    if (event.ctrlKey || event.metaKey || event.altKey || isTypingTarget(event.target)) {
        return null;
    }

    switch (event.key) {
        case 'ArrowRight':
            return 'next';
        case 'ArrowLeft':
            return 'previous';
        case 'r':
        case 'R':
            return 'replay';
        case ' ':
            return keepsNativeSpace(event.target) ? null : 'pause';
        default:
            return null;
    }
}
