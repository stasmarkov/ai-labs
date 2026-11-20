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

interface Scenario {
    id: string;
    title: string;
    outcomes: string[];
    symptom: string;
    clues: string[];
    causes: { text: string; why: string }[];
    correct: number;
    fix: string;
    test: string;
    snippet: number | null;
}

const props = defineProps<{
    guide: { key: string; title: string; href: string };
    format: { key: string; name: string; idea: string; time: string };
    items: Scenario[];
    snippets: Snippet[];
    compareHref: string;
}>();

const cluesShown = ref<Record<string, number>>({});
const picked = ref<Record<string, number>>({});
const selected = ref<Record<string, number>>({});
const revealed = ref<Record<string, boolean>>({});
const feedback = ref('');

const solved = computed(() => props.items.filter((scenario) => picked.value[scenario.id] === scenario.correct).length);

function shown(scenario: Scenario): number {
    return cluesShown.value[scenario.id] ?? 0;
}

function showClue(scenario: Scenario): void {
    if (shown(scenario) < scenario.clues.length) {
        cluesShown.value = { ...cluesShown.value, [scenario.id]: shown(scenario) + 1 };
        feedback.value = `Clue ${shown(scenario)}: ${scenario.clues[shown(scenario) - 1]}`;
    }
}

function choose(scenario: Scenario): void {
    const index = selected.value[scenario.id];
    if (index === undefined) {
        return;
    }
    const cause = scenario.causes[index];
    picked.value = { ...picked.value, [scenario.id]: index };
    feedback.value =
        index === scenario.correct
            ? `Correct. ${cause.why}`
            : `Not this one. ${cause.why} Try another cause.`;
}

function isSolved(scenario: Scenario): boolean {
    return picked.value[scenario.id] === scenario.correct || revealed.value[scenario.id] === true;
}

function showAnswer(scenario: Scenario): void {
    revealed.value = { ...revealed.value, [scenario.id]: true };
    feedback.value = `Answer: ${scenario.causes[scenario.correct].text} Fix: ${scenario.fix} Test that prevents it: ${scenario.test}`;
}

function reset(): void {
    cluesShown.value = {};
    picked.value = {};
    selected.value = {};
    revealed.value = {};
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

    <section aria-labelledby="debug-progress" class="mt-6 flex flex-wrap items-center gap-3">
        <h2 id="debug-progress" class="m-0 text-[18px]">Your progress</h2>
        <p class="m-0 text-[15px]">{{ solved }} of {{ items.length }} scenarios solved</p>
        <button
            type="button"
            class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            @click="reset"
        >
            Start over
        </button>
    </section>

    <p class="sr-only" role="status" aria-live="polite">{{ feedback }}</p>

    <ol class="mt-6 list-none space-y-6 p-0">
        <li v-for="(scenario, index) in items" :key="scenario.id" class="min-w-0 rounded-sm border border-rule p-4">
            <h2 class="m-0 text-[18px] font-semibold">Scenario {{ index + 1 }}. {{ scenario.title }}</h2>
            <p class="mb-0 mt-1 text-[13px] text-muted">Teaches {{ scenario.outcomes.join(', ') }}</p>

            <p class="mb-0 mt-3 text-[15px] font-semibold">What you see (example)</p>
            <pre class="m-0 mt-1 overflow-x-auto rounded-sm border border-rule p-3 font-mono text-[13px]" tabindex="0"><code>{{ scenario.symptom }}</code></pre>

            <div class="mt-3">
                <p class="m-0 text-[15px] font-semibold">Clues</p>
                <ul v-if="shown(scenario) > 0" class="mb-0 mt-1 list-disc pl-5 text-[15px]">
                    <li v-for="clue in scenario.clues.slice(0, shown(scenario))" :key="clue">{{ clue }}</li>
                </ul>
                <button
                    v-if="shown(scenario) < scenario.clues.length"
                    type="button"
                    class="mt-2 rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    @click="showClue(scenario)"
                >
                    Show clue {{ shown(scenario) + 1 }} of {{ scenario.clues.length }} for scenario {{ index + 1 }}
                </button>
            </div>

            <fieldset class="m-0 mt-4 min-w-0 border-0 p-0">
                <legend class="text-[15px] font-semibold">What is the cause?</legend>
                <div class="mt-2 space-y-2">
                    <label v-for="(cause, causeIndex) in scenario.causes" :key="causeIndex" class="flex items-start gap-2 text-[15px]">
                        <input
                            type="radio"
                            class="mt-1 size-4"
                            :name="`debug-${scenario.id}`"
                            :value="causeIndex"
                            :checked="isSolved(scenario) ? causeIndex === scenario.correct : selected[scenario.id] === causeIndex"
                            :disabled="shown(scenario) === 0 || isSolved(scenario)"
                            @change="selected = { ...selected, [scenario.id]: causeIndex }"
                        />
                        <span>
                            {{ cause.text }}
                            <strong v-if="isSolved(scenario) && causeIndex === scenario.correct"> (the cause)</strong>
                            <span v-else-if="picked[scenario.id] === causeIndex"> (your last pick)</span>
                        </span>
                    </label>
                </div>
                <p v-if="shown(scenario) === 0" class="mb-0 mt-2 text-[13px] text-muted">Show a clue first to unlock the causes.</p>
                <button
                    v-if="!isSolved(scenario)"
                    type="button"
                    class="mr-2 mt-3 rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50"
                    :disabled="shown(scenario) === 0 || selected[scenario.id] === undefined"
                    @click="choose(scenario)"
                >
                    Check cause for scenario {{ index + 1 }}
                </button>
                <button
                    v-if="!isSolved(scenario)"
                    type="button"
                    class="mt-3 rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    @click="showAnswer(scenario)"
                >
                    Show answer for scenario {{ index + 1 }}
                </button>
            </fieldset>

            <p v-if="!isSolved(scenario) && scenario.id in picked && picked[scenario.id] !== scenario.correct" class="mb-0 mt-3 text-[15px]">
                <span class="font-semibold">Not this one.</span> {{ scenario.causes[picked[scenario.id]].why }} Try another cause.
            </p>

            <div v-if="isSolved(scenario)" class="mt-4">
                <p class="m-0 text-[15px]">
                    <span class="font-semibold">{{ picked[scenario.id] === scenario.correct ? 'Correct.' : 'Answer.' }}</span> The cause: {{ scenario.causes[scenario.correct].text }}. {{ scenario.causes[scenario.correct].why }}
                </p>
                <p class="mb-0 mt-3 text-[15px]"><span class="font-semibold">Fix.</span> {{ scenario.fix }}</p>
                <p class="mb-0 mt-3 text-[15px]"><span class="font-semibold">Test that prevents it.</span> {{ scenario.test }}</p>
                <div v-if="scenario.snippet !== null" class="mt-3">
                    <LabCode v-bind="snippets[scenario.snippet]" />
                </div>
            </div>
        </li>
    </ol>
</template>
