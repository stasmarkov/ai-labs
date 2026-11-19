<script setup lang="ts">
import type { Step } from '../../composables/lessonTypes';

defineProps<{ steps: Step[]; current: number; paused: boolean }>();

defineEmits<{
    go: [number];
    replay: [];
    pause: [];
}>();

const control = 'inline-flex min-h-10 items-center rounded-sm border px-3 text-[14px] font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <nav aria-label="Lesson steps" class="mt-4">
        <ol class="m-0 flex list-none flex-wrap gap-1 p-0">
            <li v-for="step in steps" :key="step.number">
                <button
                    type="button"
                    class="inline-flex h-10 min-w-10 items-center justify-center rounded-sm border px-2 font-mono text-[14px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    :class="step.number === current ? 'border-ink bg-ink text-white' : 'border-rule text-ink hover:bg-[#ebe6da]'"
                    :aria-current="step.number === current ? 'step' : undefined"
                    :aria-label="`Step ${step.number}: ${step.title}`"
                    :title="step.title"
                    @click="$emit('go', step.number)"
                >{{ step.number }}</button>
            </li>
        </ol>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" :class="`${control} border-ink text-ink`" :disabled="current <= 1" @click="$emit('go', current - 1)">Back</button>
            <button type="button" :class="`${control} border-signal bg-signal text-white`" :disabled="current >= steps.length" @click="$emit('go', current + 1)">
                {{ current >= steps.length ? 'Last step' : `Next: ${steps[current].title}` }}
            </button>
            <button type="button" :class="`${control} border-rule text-ink`" @click="$emit('replay')">Replay</button>
            <button type="button" :class="`${control} border-rule text-ink`" :aria-pressed="paused" @click="$emit('pause')">{{ paused ? 'Resume' : 'Pause' }}</button>
            <p class="m-0 text-[13px] text-muted">Keys: <kbd class="font-mono">←</kbd> <kbd class="font-mono">→</kbd> move, <kbd class="font-mono">R</kbd> replay, <kbd class="font-mono">Space</kbd> pause</p>
        </div>
    </nav>
</template>
