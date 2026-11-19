<script setup lang="ts">
import type { Step } from '../../composables/lessonTypes';

defineProps<{ steps: Step[]; current: number; paused: boolean }>();

defineEmits<{
    go: [number];
    replay: [];
    pause: [];
}>();

const control = 'inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-sm border px-3 text-[14px] font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

/** Passed steps are filled lightly, the current one in ink, the rest outlined. */
function dotClass(number: number, current: number): string {
    if (number === current) {
        return 'border-ink bg-ink text-white';
    }

    return number < current ? 'border-[#b9b2a3] bg-[#ebe6da] text-ink hover:bg-[#ddd6c6]' : 'border-rule bg-white text-ink hover:bg-[#ebe6da]';
}
</script>

<template>
    <nav aria-label="Lesson steps" class="mt-4 rounded-md border border-rule bg-white px-3 py-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <ol class="m-0 flex shrink-0 list-none items-center gap-1 p-0">
                <li v-for="step in steps" :key="step.number" class="flex items-center">
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-full border font-mono text-[13px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        :class="dotClass(step.number, current)"
                        :aria-current="step.number === current ? 'step' : undefined"
                        :aria-label="`Step ${step.number}: ${step.title}`"
                        :title="step.title"
                        @click="$emit('go', step.number)"
                    >{{ step.number }}</button>
                </li>
            </ol>
            <p class="m-0 min-w-0 text-[14px] text-muted">
                Step {{ current }} of {{ steps.length }}: <span class="font-semibold text-ink">{{ steps[current - 1]?.title }}</span>
            </p>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" :class="`${control} border-ink bg-white text-ink`" :disabled="current <= 1" @click="$emit('go', current - 1)">
                <span aria-hidden="true">←</span> Back
            </button>
            <button type="button" :class="`${control} border-signal bg-signal text-white`" :disabled="current >= steps.length" @click="$emit('go', current + 1)">
                <span>{{ current >= steps.length ? 'Last step' : `Next: ${steps[current].title}` }}</span>
                <span v-if="current < steps.length" aria-hidden="true">→</span>
            </button>
            <button type="button" :class="`${control} border-rule bg-white text-ink`" @click="$emit('replay')">Replay</button>
            <button type="button" :class="`${control} border-rule bg-white text-ink`" :aria-pressed="paused" @click="$emit('pause')">{{ paused ? 'Resume' : 'Pause' }}</button>
            <p class="m-0 ml-auto text-[13px] text-muted">
                <kbd class="rounded-sm border border-rule px-1 font-mono">←</kbd> <kbd class="rounded-sm border border-rule px-1 font-mono">→</kbd> move,
                <kbd class="rounded-sm border border-rule px-1 font-mono">R</kbd> replay,
                <kbd class="rounded-sm border border-rule px-1 font-mono">Space</kbd> pause
            </p>
        </div>
    </nav>
</template>
