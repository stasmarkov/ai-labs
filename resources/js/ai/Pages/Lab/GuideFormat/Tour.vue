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

interface Stop {
    id: string;
    title: string;
    outcomes: string[];
    snippet: number;
    notes: { from: number; to: number; text: string }[];
}

const props = defineProps<{
    guide: { key: string; title: string; href: string };
    format: { key: string; name: string; idea: string; time: string };
    items: Stop[];
    snippets: Snippet[];
    compareHref: string;
}>();

const current = ref(0);

const stop = computed(() => props.items[current.value]);
const isFirst = computed(() => current.value === 0);
const isLast = computed(() => current.value >= props.items.length - 1);

function previous(): void {
    if (!isFirst.value) {
        current.value -= 1;
    }
}

function next(): void {
    if (!isLast.value) {
        current.value += 1;
    }
}

function lineRange(note: { from: number; to: number }): string {
    const start = props.snippets[stop.value.snippet].startLine;

    return note.from === note.to ? `Line ${start + note.from - 1}` : `Lines ${start + note.from - 1} to ${start + note.to - 1}`;
}
</script>

<template>
    <p class="m-0 text-[14px] text-muted">
        <Link href="/ai/guides" class="text-ink underline">Guides</Link>
        /
        <Link :href="compareHref" class="text-ink underline">Format trial</Link>
        /
        {{ format.name }}
    </p>
    <h1 class="mt-2 text-[28px] font-semibold">{{ format.name }}: {{ guide.title }}</h1>
    <p class="mt-2 text-[15px] text-muted">{{ format.idea }} Expected time (estimate): {{ format.time }}</p>

    <section aria-labelledby="tour-map" class="mt-6">
        <h2 id="tour-map" class="m-0 text-[18px]">The route</h2>
        <ol class="mt-3 flex list-none flex-wrap items-center gap-y-2 p-0" aria-label="Stops of the tour">
            <li v-for="(item, index) in items" :key="item.id" class="flex items-center">
                <button
                    type="button"
                    class="rounded-sm border px-3 py-1 text-[14px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    :class="index === current ? 'border-ink bg-ink text-white' : 'border-rule text-ink hover:bg-[#f2f2f2]'"
                    :aria-current="index === current ? 'step' : undefined"
                    @click="current = index"
                >
                    {{ index + 1 }}. {{ item.title }}
                </button>
                <span v-if="index < items.length - 1" class="mx-2 text-muted" aria-hidden="true">&rarr;</span>
            </li>
        </ol>

        <p class="sr-only" role="status" aria-live="polite">Stop {{ current + 1 }} of {{ items.length }}: {{ stop.title }}</p>

        <article class="mt-6 min-w-0 rounded-sm border border-rule p-4" aria-labelledby="tour-stop-title">
            <h3 id="tour-stop-title" class="m-0 text-[18px] font-semibold">
                Stop {{ current + 1 }} of {{ items.length }}. {{ stop.title }}
            </h3>
            <p class="mb-0 mt-1 text-[13px] text-muted">Teaches {{ stop.outcomes.join(', ') }}</p>
            <div class="mt-3">
                <LabCode v-bind="snippets[stop.snippet]" />
            </div>
            <ul class="mt-3 list-none p-0">
                <li v-for="(note, index) in stop.notes" :key="index" class="mt-2 text-[15px]">
                    <span class="font-semibold">{{ lineRange(note) }}.</span>
                    {{ note.text }}
                </li>
            </ul>
            <div class="mt-4 flex gap-3">
                <button
                    type="button"
                    class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50"
                    :disabled="isFirst"
                    @click="previous"
                >
                    Previous stop
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50"
                    :disabled="isLast"
                    @click="next"
                >
                    Next stop
                </button>
            </div>
        </article>
    </section>
</template>
