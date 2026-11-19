<script setup lang="ts">
import type { Step } from '../../composables/lessonTypes';
import LessonText from './LessonText.vue';

defineProps<{ step: Step; total: number }>();

const roles: Record<string, { label: string; class: string }> = {
    problem: { label: 'Problem', class: 'bg-danger' },
    solution: { label: 'Solution', class: 'bg-success' },
    careful: { label: 'Careful', class: 'bg-signal' },
    useful: { label: 'Useful', class: 'bg-[#1d4ed8]' },
    summary: { label: 'Summary', class: 'bg-ink' },
};

const callouts: Record<string, string> = { careful: 'Careful', useful: 'Useful', 'key-idea': 'Key idea' };

const signs: Record<string, string> = { '+': 'Added', '~': 'Changed', '-': 'Removed' };
</script>

<template>
    <article aria-labelledby="lesson-step-title" class="rounded-sm border border-rule p-4">
        <p class="m-0 flex items-center gap-2 text-[13px] text-muted">
            <span class="rounded-sm px-2 py-0.5 text-[12px] font-semibold uppercase tracking-wide text-white" :class="roles[step.role]?.class ?? 'bg-ink'">{{ roles[step.role]?.label ?? step.role }}</span>
            Step {{ step.number }} of {{ total }}
        </p>
        <h2 id="lesson-step-title" class="mb-0 mt-2 text-[20px] font-semibold">{{ step.title }}</h2>
        <p v-for="(paragraph, index) in step.body" :key="index" class="mb-0 mt-3 text-[15px]"><LessonText :text="paragraph" /></p>

        <p v-if="step.callout" class="mb-0 mt-4 rounded-sm border-l-4 border-ink bg-[#ebe6da] px-3 py-2 text-[14px]">
            <strong>{{ callouts[step.callout.kind] ?? step.callout.kind }}.</strong>
            <LessonText :text="step.callout.text" />
        </p>

        <template v-if="step.changed.length">
            <h3 class="mb-0 mt-4 text-[14px] font-semibold">What changed</h3>
            <ul class="mb-0 mt-2 list-none space-y-1 p-0 text-[14px]">
                <li v-for="(change, index) in step.changed" :key="index" class="flex gap-2">
                    <span class="w-4 shrink-0 font-mono font-semibold" aria-hidden="true">{{ change.sign }}</span>
                    <span><span class="sr-only">{{ signs[change.sign] }}: </span><LessonText :text="change.text" /></span>
                </li>
            </ul>
        </template>

        <p v-if="step.deeper" class="mb-0 mt-4 text-[14px]"><a :href="step.deeper" class="text-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">Go deeper in the guide</a></p>
    </article>
</template>
