<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Course } from '../../composables/lessonTypes';
import { useLessonProgress } from '../../composables/useLessonProgress';

defineProps<{ course: Course; current: string }>();

const progress = useLessonProgress();
const open = ref(false);

/** Whether the reader opened the course rail on a wide screen; collapsed until they do, so the scene gets the room. */
const storageKey = 'ai-lab:learn:sidebar';

let wide: MediaQueryList | null = null;

function storedOpen(): boolean {
    try {
        return window.localStorage.getItem(storageKey) === 'open';
    } catch {
        return false;
    }
}

function store(value: boolean): void {
    try {
        window.localStorage.setItem(storageKey, value ? 'open' : 'closed');
    } catch {
        // storage refused (private mode): the rail just starts collapsed next time
    }
}

/**
 * From 1024 px up the rail sits beside the scene and remembers whether it was open; below, it is a collapsed block above
 * the scene. Follows the window when it is resized across.
 */
function followWidth(): void {
    open.value = (wide?.matches ?? false) && storedOpen();
}

function onToggle(event: Event): void {
    open.value = (event.target as HTMLDetailsElement).open;

    if (wide?.matches) {
        store(open.value);
    }
}

onMounted(() => {
    wide = window.matchMedia('(min-width: 1024px)');
    followWidth();
    wide.addEventListener('change', followWidth);
});

onBeforeUnmount(() => wide?.removeEventListener('change', followWidth));

const keyOf = (module: string, lesson: string): string => `${module}/${lesson}`;

function playable(module: Course['modules'][number]): number {
    return module.lessons.filter((lesson) => lesson.href !== null).length;
}

function done(module: Course['modules'][number]): number {
    return module.lessons.filter((lesson) => lesson.href !== null && progress.isDone(keyOf(module.slug, lesson.slug))).length;
}

</script>

<template>
    <details class="rounded-md border border-rule bg-white p-3 lg:shrink-0" :class="open ? 'lg:w-[208px]' : 'lg:w-11 lg:p-0'" :open="open" @toggle="onToggle">
        <summary
            class="flex cursor-pointer list-none items-center gap-2 text-[14px] font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 [&::-webkit-details-marker]:hidden"
            :class="open ? '' : 'lg:flex-col lg:px-0 lg:py-3'"
            :title="open ? 'Hide the course outline' : 'Show the course outline'"
        >
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" class="shrink-0">
                <path d="M3 4.5h12M3 9h12M3 13.5h12" />
            </svg>
            <span :class="open ? '' : 'lg:[writing-mode:vertical-rl]'">Course</span>
            <span v-if="open" class="ml-auto text-[13px] font-normal text-muted">Hide</span>
        </summary>
        <nav aria-label="Course" class="mt-3">
            <section v-for="module in course.modules" :key="module.slug" class="mb-4">
                <h2 class="m-0 text-[13px] font-semibold">{{ module.title }} <span class="font-normal text-muted">{{ done(module) }}/{{ playable(module) }}</span></h2>
                <ul class="mb-0 mt-1 list-none space-y-1 p-0 text-[13px] leading-snug">
                    <li v-for="lesson in module.lessons" :key="lesson.slug">
                        <Link
                            v-if="lesson.href"
                            :href="lesson.href"
                            class="text-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            :class="keyOf(module.slug, lesson.slug) === current ? 'font-semibold' : ''"
                            :aria-current="keyOf(module.slug, lesson.slug) === current ? 'page' : undefined"
                        >{{ lesson.title }}</Link>
                        <span v-else class="text-muted">{{ lesson.title }} (coming soon)</span>
                        <span v-if="lesson.href && progress.isDone(keyOf(module.slug, lesson.slug))" class="ml-1 text-success"><span aria-hidden="true">✓</span><span class="sr-only">done</span></span>
                    </li>
                </ul>
            </section>
            <button
                type="button"
                class="text-[13px] text-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                @click="progress.resetLesson(current)"
            >reset this lesson</button>
        </nav>
    </details>
</template>
