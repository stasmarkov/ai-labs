<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { activeStep } from '../composables/useGuideSteps';
import type { GuideRunState, GuideStep } from '../composables/useGuideSteps';

const props = defineProps<{
    steps: GuideStep[];
    events: string[];
    state: GuideRunState;
    guideHref: string;
}>();

const active = computed(() => activeStep(props.steps, props.events, props.state));

const endLabels: Record<string, string> = { refused: 'refused', no_context: 'no relevant context' };

const running = computed(() => !active.value.done && !active.value.failed && !active.value.stopped);

function isFailed(index: number): boolean {
    return active.value.failed && active.value.index === index;
}

function isStopped(index: number): boolean {
    return active.value.stopped && active.value.index === index;
}

function isCurrent(index: number): boolean {
    return running.value && active.value.index === index;
}

function marker(index: number): string {
    if (isFailed(index)) {
        return '✗';
    }

    if (isStopped(index)) {
        return '■';
    }

    if (index <= active.value.completedThrough) {
        return '✓';
    }

    return isCurrent(index) ? '▶' : '○';
}

function endNote(index: number): string | null {
    if (isFailed(index)) {
        return 'failed';
    }

    if (isStopped(index)) {
        return 'stopped';
    }

    if (active.value.done && index === active.value.completedThrough) {
        return active.value.endedBy === null ? 'done' : (endLabels[active.value.endedBy] ?? active.value.endedBy);
    }

    return null;
}
</script>

<template>
    <details v-if="steps.length > 0" class="mt-4 rounded-sm border border-rule px-4 py-3">
        <summary class="cursor-pointer text-[15px] font-medium">How it works</summary>
        <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
            <li
                v-for="(step, index) in steps"
                :key="index"
                :aria-current="isCurrent(index) ? 'step' : undefined"
                class="flex gap-3 text-[14px]"
                :class="isCurrent(index) || endNote(index) !== null ? 'font-medium text-ink' : 'text-muted'"
            >
                <span class="w-4 shrink-0" aria-hidden="true">{{ marker(index) }}</span>
                <div class="min-w-0">
                    <p class="m-0 font-medium">
                        {{ step.title }}
                        <span v-if="endNote(index) !== null" class="text-[13px] font-normal">({{ endNote(index) }})</span>
                    </p>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div class="mt-1 font-normal [&_p]:m-0" v-html="step.html" />
                </div>
            </li>
        </ol>
        <Link :href="guideHref" class="mt-3 inline-block text-[13px] text-ink underline">Open the full guide</Link>
    </details>
</template>
