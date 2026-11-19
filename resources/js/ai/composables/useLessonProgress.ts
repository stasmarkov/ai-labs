import { ref } from 'vue';

const storageKey = 'ai-lab:learn:v1';

interface LessonRecord {
    viewed: number[];
    done: boolean;
}

interface Stored {
    lessons: Record<string, LessonRecord>;
}

const state = ref<Stored>({ lessons: {} });
let loaded = false;

/**
 * Keeps only well-formed records: anything else in storage (an old shape, a hand edit) is dropped, never trusted.
 */
function sanitize(parsed: unknown): Stored {
    const lessons: Record<string, LessonRecord> = {};

    if (!parsed || typeof parsed !== 'object' || !('lessons' in parsed)) {
        return { lessons };
    }

    const stored = (parsed as { lessons: unknown }).lessons;

    if (!stored || typeof stored !== 'object' || Array.isArray(stored)) {
        return { lessons };
    }

    for (const [key, value] of Object.entries(stored as Record<string, unknown>)) {
        const record = value as Partial<LessonRecord> | null;

        if (!record || typeof record !== 'object' || !Array.isArray(record.viewed) || typeof record.done !== 'boolean') {
            continue;
        }

        const viewed = record.viewed.filter((step): step is number => Number.isInteger(step) && step > 0);

        lessons[key] = { viewed: [...new Set(viewed)].sort((a, b) => a - b), done: record.done };
    }

    return { lessons };
}

function load(): void {
    if (loaded || typeof window === 'undefined') {
        return;
    }

    loaded = true;

    try {
        state.value = sanitize(JSON.parse(window.localStorage.getItem(storageKey) ?? 'null'));
    } catch {
        state.value = { lessons: {} };
    }
}

function save(): void {
    try {
        window.localStorage.setItem(storageKey, JSON.stringify(state.value));
    } catch {
        // storage can be full or blocked; progress then lasts for this page view only
    }
}

export function useLessonProgress() {
    load();

    const viewedSteps = (key: string): number[] => state.value.lessons[key]?.viewed ?? [];

    const isDone = (key: string): boolean => state.value.lessons[key]?.done ?? false;

    function markViewed(key: string, step: number, total: number): void {
        const record = state.value.lessons[key] ?? { viewed: [], done: false };
        const viewed = record.viewed.includes(step) ? record.viewed : [...record.viewed, step].sort((a, b) => a - b);

        state.value = {
            lessons: { ...state.value.lessons, [key]: { viewed, done: record.done || step === total } },
        };
        save();
    }

    function resetLesson(key: string): void {
        const { [key]: removed, ...rest } = state.value.lessons;

        void removed;
        state.value = { lessons: rest };
        save();
    }

    function resetAll(): void {
        state.value = { lessons: {} };
        save();
    }

    return { viewedSteps, isDone, markViewed, resetLesson, resetAll };
}
