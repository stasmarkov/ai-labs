/**
 * Reads the `q` query parameter a guide experiment links with, so a live page can prefill its field.
 * It never submits anything: the learner presses the button themselves.
 */
export function presetQuery(): string {
    if (typeof window === 'undefined') {
        return '';
    }

    return new URLSearchParams(window.location.search).get('q') ?? '';
}
