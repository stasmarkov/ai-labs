export interface GuideStep {
    title: string;
    html: string;
    event: string | null;
}

export type GuideRunState = 'idle' | 'streaming' | 'done' | 'stopped' | 'error';

export interface ActiveStep {
    /** Index of the highlighted step, or null before anything happened. */
    index: number | null;
    /** Steps up to and including this index are marked done; -1 marks none. */
    completedThrough: number;
    /** True once the run finished, normally or by an early end such as a refusal. */
    done: boolean;
    /** True when the run ended in an error or a failure event arrived: the highlighted step failed. */
    failed: boolean;
    /** True when the learner pressed Stop: the highlighted step is where the run stopped. */
    stopped: boolean;
    /** The event that ended the run before its last step (refused, no_context), or null. */
    endedBy: string | null;
}

const FAILURE_EVENTS = ['error', 'request_failed', 'validation_failed'];

/** Events after which the run answers without reaching its last step. */
const EARLY_END_EVENTS = ['refused', 'no_context'];

/**
 * Finds the step to highlight from the event types seen so far (oldest first).
 * Each event moves the highlight to the first step at or after the current one that maps to that type. When no
 * later step maps to it, an earlier mapped step is taken instead: on a cache hit the stored sources arrive before
 * the cache_hit event although the cache is asked first. An event no step maps to is ignored. A step without a
 * mapped event is reached in order: the first step is reached as soon as the run starts.
 */
export function activeStep(steps: GuideStep[], events: string[], state: GuideRunState): ActiveStep {
    const idle: ActiveStep = { index: null, completedThrough: -1, done: false, failed: false, stopped: false, endedBy: null };

    if (steps.length === 0) {
        return idle;
    }

    let index: number | null = state !== 'idle' && steps[0].event === null ? 0 : null;

    for (const type of events) {
        const from = index ?? 0;
        let found = steps.findIndex((step, position) => position >= from && step.event === type);

        if (found === -1) {
            found = steps.findIndex((step) => step.event === type);
        }

        if (found !== -1) {
            index = found;
        }
    }

    const current = index ?? 0;

    if (state === 'error' || events.some((type) => FAILURE_EVENTS.includes(type))) {
        return { ...idle, index: current, completedThrough: current - 1, failed: true };
    }

    if (state === 'stopped') {
        return { ...idle, index: current, completedThrough: current - 1, stopped: true };
    }

    if (state === 'done') {
        const endedBy = events.find((type) => EARLY_END_EVENTS.includes(type)) ?? null;
        const last = endedBy === null ? steps.length - 1 : current;

        return { ...idle, index: last, completedThrough: last, done: true, endedBy };
    }

    return { ...idle, index, completedThrough: current - 1 };
}
