<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabTag from '../../components/LabTag.vue';
import type { Course } from '../../composables/lessonTypes';
import { useLessonProgress } from '../../composables/useLessonProgress';

const props = defineProps<{ course: Course }>();

const progress = useLessonProgress();

const playable = computed(() => props.course.modules.flatMap((module) => module.lessons.filter((lesson) => lesson.href !== null).map((lesson) => `${module.slug}/${lesson.slug}`)));
const doneLessons = computed(() => playable.value.filter((key) => progress.isDone(key)).length);
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Learn</h1>
    <p class="mt-2 text-[15px] text-muted">Short animated lessons: watch a request move through the system one step at a time.</p>

    <div class="mt-4 flex flex-wrap items-center gap-3 text-[14px]">
        <p class="m-0" aria-live="polite">{{ doneLessons }} of {{ playable.length }} lessons done</p>
        <progress class="h-2 w-40" :value="doneLessons" :max="Math.max(playable.length, 1)" aria-label="Lessons done"></progress>
        <button type="button" class="text-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" @click="progress.resetAll()">reset progress</button>
    </div>

    <section v-for="module in course.modules" :key="module.slug" :aria-labelledby="`learn-${module.slug}`" class="mt-6">
        <h2 :id="`learn-${module.slug}`" class="m-0 text-[18px]">{{ module.title }}</h2>
        <p class="mb-0 mt-1 text-[15px] text-muted">{{ module.summary }}</p>
        <ul class="mt-3 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
            <li v-for="lesson in module.lessons" :key="lesson.slug" class="min-w-0 rounded-sm border border-rule p-4">
                <h3 class="m-0 flex flex-wrap items-center gap-2 text-[16px] font-semibold">
                    <Link v-if="lesson.href" :href="lesson.href" class="text-ink underline">{{ lesson.title }}</Link>
                    <span v-else>{{ lesson.title }}</span>
                    <LabTag v-if="lesson.href && progress.isDone(`${module.slug}/${lesson.slug}`)">done</LabTag>
                    <LabTag v-if="lesson.comingSoon">coming soon</LabTag>
                    <LabTag v-else-if="!lesson.available">unavailable</LabTag>
                </h3>
                <p class="mb-0 mt-2 text-[15px]">{{ lesson.summary }}</p>
                <p v-if="lesson.minutes" class="mb-0 mt-2 text-[13px] text-muted">{{ lesson.level }}, {{ lesson.minutes }} min, {{ lesson.steps }} steps</p>
            </li>
        </ul>
    </section>
</template>
