<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Course } from '../../composables/lessonTypes';
import { useLessonProgress } from '../../composables/useLessonProgress';

defineProps<{ course: Course; current: string }>();

const progress = useLessonProgress();
const open = ref(true);

let wide: MediaQueryList | null = null;

/**
 * Open from 1024 px up, where the toggle is hidden; collapsed below. Follows the window when it is resized across.
 */
function followWidth(): void {
    open.value = wide?.matches ?? true;
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
    <details class="rounded-sm border border-rule p-3 lg:border-0 lg:p-0" :open="open" @toggle="open = ($event.target as HTMLDetailsElement).open">
        <summary class="cursor-pointer text-[14px] font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 lg:hidden">Course</summary>
        <nav aria-label="Course" class="mt-3 lg:mt-0">
            <section v-for="module in course.modules" :key="module.slug" class="mb-4">
                <h2 class="m-0 text-[14px] font-semibold">{{ module.title }} <span class="font-normal text-muted">{{ done(module) }}/{{ playable(module) }}</span></h2>
                <ul class="mb-0 mt-1 list-none space-y-1 p-0 text-[14px]">
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
