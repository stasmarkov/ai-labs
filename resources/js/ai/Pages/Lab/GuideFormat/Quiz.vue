<script lang="ts">
import LabLayout from '../../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabCode from '../../../components/LabCode.vue';

interface Snippet {
    file: string;
    startLine: number;
    endLine: number;
    code: string;
}

interface Question {
    id: string;
    title: string;
    outcomes: string[];
    question: string;
    options: string[];
    correct: number;
    explanation: string;
    snippet: number;
}

const props = defineProps<{
    guide: { key: string; title: string; href: string };
    format: { key: string; name: string; idea: string; time: string };
    items: Question[];
    snippets: Snippet[];
    compareHref: string;
}>();

const picked = ref<Record<string, number>>({});
const currentIndex = ref(0);

const current = computed(() => props.items[currentIndex.value]);
const isLast = computed(() => currentIndex.value === props.items.length - 1);
const score = computed(() => props.items.filter((question) => picked.value[question.id] === question.correct).length);
const finished = computed(() => isLast.value && current.value.id in picked.value);

const selected = ref<number | null>(null);
const feedback = ref('');

function choose(question: Question): void {
    const index = selected.value;
    if (index === null || question.id in picked.value) {
        return;
    }
    picked.value = { ...picked.value, [question.id]: index };
    feedback.value =
        index === question.correct
            ? `Correct. ${question.explanation}`
            : `Not quite. The right answer is: ${question.options[question.correct]}. ${question.explanation}`;
    if (currentIndex.value === props.items.length - 1) {
        const total = props.items.filter((item) => picked.value[item.id] === item.correct).length;
        feedback.value += ` Score: ${total} / ${props.items.length}.`;
    }
}

function next(): void {
    if (!isLast.value && current.value.id in picked.value) {
        currentIndex.value += 1;
        selected.value = null;
        feedback.value = '';
    }
}

function reset(): void {
    picked.value = {};
    currentIndex.value = 0;
    selected.value = null;
    feedback.value = '';
}
</script>

<template>
    <p class="m-0 text-[14px] text-muted">
        <Link href="/ai/learn" class="text-ink underline">Learn</Link>
        /
        <Link :href="compareHref" class="text-ink underline">Format trial</Link>
        /
        {{ format.name }}
    </p>
    <h1 class="mt-2 text-[28px] font-semibold">{{ format.name }}: {{ guide.title }}</h1>
    <p class="mt-2 text-[15px] text-muted">{{ format.idea }} Expected time (estimate): {{ format.time }}</p>

    <p class="sr-only" role="status" aria-live="polite">{{ feedback }}</p>

    <p class="mt-6 text-[14px] text-muted">Question {{ currentIndex + 1 }} of {{ items.length }}</p>

    <div class="mt-2 min-w-0 rounded-sm border border-rule p-4">
        <fieldset :key="current.id" class="m-0 min-w-0 border-0 p-0">
            <legend class="text-[18px] font-semibold">Question {{ currentIndex + 1 }}. {{ current.title }}</legend>
            <p class="mb-0 mt-1 text-[13px] text-muted">Teaches {{ current.outcomes.join(', ') }}</p>
            <p class="mb-0 mt-3 text-[15px]">{{ current.question }}</p>
            <div class="mt-3 space-y-2">
                <label v-for="(option, optionIndex) in current.options" :key="optionIndex" class="flex items-start gap-2 text-[15px]">
                    <input
                        type="radio"
                        class="mt-1 size-4"
                        :name="`quiz-${current.id}`"
                        :value="optionIndex"
                        :checked="(picked[current.id] ?? selected) === optionIndex"
                        :disabled="current.id in picked"
                        @change="selected = optionIndex"
                    />
                    <span>
                        {{ option }}
                        <strong v-if="current.id in picked && optionIndex === current.correct"> (correct answer)</strong>
                        <span v-else-if="picked[current.id] === optionIndex"> (your answer)</span>
                    </span>
                </label>
            </div>
            <button
                v-if="!(current.id in picked)"
                type="button"
                class="mt-3 rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50"
                :disabled="selected === null"
                @click="choose(current)"
            >
                Check answer
            </button>
        </fieldset>
        <div v-if="current.id in picked" class="mt-4">
            <p class="m-0 text-[15px]">
                <span class="font-semibold">{{ picked[current.id] === current.correct ? 'Correct.' : 'Not quite.' }}</span>
                {{ current.explanation }}
            </p>
            <div class="mt-3">
                <LabCode v-bind="snippets[current.snippet]" />
            </div>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button
            v-if="!isLast"
            type="button"
            class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50"
            :disabled="!(current.id in picked)"
            @click="next"
        >
            Next
        </button>
        <p v-if="finished" class="m-0 text-[18px] font-semibold">Score: {{ score }} / {{ items.length }}</p>
        <button
            type="button"
            class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            @click="reset"
        >
            Start over
        </button>
    </div>
</template>
