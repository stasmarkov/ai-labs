<script lang="ts">
import LabLayout from '../../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabCode from '../../../components/LabCode.vue';

interface Snippet {
    file: string;
    startLine: number;
    endLine: number;
    code: string;
}

interface Stage {
    id: string;
    title: string;
    outcomes: string[];
    what: string;
    sample: string;
    snippet: number;
}

const props = defineProps<{
    guide: { key: string; title: string; href: string };
    format: { key: string; name: string; idea: string; time: string };
    items: Stage[];
    snippets: Snippet[];
    compareHref: string;
}>();

const STEP_MS = 2500;

const current = ref(0);
const playing = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

const stage = computed(() => props.items[current.value]);

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
    playing.value = false;
}

function advance(): void {
    if (current.value >= props.items.length - 1) {
        stop();

        return;
    }
    current.value += 1;
}

function play(): void {
    if (current.value >= props.items.length - 1) {
        current.value = 0;
    }
    playing.value = true;
    timer = setInterval(advance, STEP_MS);
}

function toggle(): void {
    if (playing.value) {
        stop();
    } else {
        play();
    }
}

function select(index: number): void {
    stop();
    current.value = index;
}

const flow = ref<HTMLOListElement | null>(null);

/**
 * Arrow keys move from the stage button that has focus (falling back to the shown stage) and keep focus on the
 * stage they select. Buttons are found by their data-stage index, so no per-render ref callbacks are needed.
 */
async function move(offset: number): Promise<void> {
    const focused = document.activeElement instanceof HTMLElement ? document.activeElement.dataset.stage : undefined;
    const target = (focused === undefined ? current.value : Number(focused)) + offset;

    if (target < 0 || target >= props.items.length) {
        return;
    }
    select(target);
    await nextTick();
    flow.value?.querySelector<HTMLButtonElement>(`[data-stage="${target}"]`)?.focus();
}

onBeforeUnmount(stop);
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

    <section aria-labelledby="trace-flow" class="mt-6">
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="trace-flow" class="m-0 text-[18px]">The trip of one photo</h2>
            <button
                type="button"
                class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                :aria-pressed="playing"
                @click="toggle"
            >
                {{ playing ? 'Pause' : 'Play' }}
            </button>
        </div>

        <ol
            ref="flow"
            class="mt-4 flex list-none flex-wrap items-center gap-y-2 p-0"
            aria-label="Stages in pipeline order"
            @keydown.left.prevent="move(-1)"
            @keydown.right.prevent="move(1)"
        >
            <li v-for="(item, index) in items" :key="item.id" class="flex items-center">
                <button
                    type="button"
                    :data-stage="index"
                    class="rounded-sm border px-3 py-1 text-[14px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    :class="index === current ? 'border-ink bg-ink text-white' : 'border-rule text-ink hover:bg-[#f2f2f2]'"
                    :aria-current="index === current ? 'step' : undefined"
                    @click="select(index)"
                >
                    {{ index + 1 }}. {{ item.title }}
                </button>
                <span v-if="index < items.length - 1" class="mx-2 text-muted" aria-hidden="true">&rarr;</span>
            </li>
        </ol>

        <p class="sr-only" role="status" aria-live="polite">
            Stage {{ current + 1 }} of {{ items.length }}: {{ stage.title }}
        </p>

        <article class="mt-6 min-w-0 rounded-sm border border-rule p-4" aria-labelledby="trace-stage-title">
            <h3 id="trace-stage-title" class="m-0 text-[18px] font-semibold">
                Stage {{ current + 1 }} of {{ items.length }}. {{ stage.title }}
            </h3>
            <p class="mb-0 mt-1 text-[13px] text-muted">Teaches {{ stage.outcomes.join(', ') }}</p>
            <p class="mb-0 mt-3 text-[15px]">{{ stage.what }}</p>
            <p class="mb-0 mt-3 text-[15px] font-semibold">Example data at this stage</p>
            <pre class="m-0 mt-1 overflow-x-auto rounded-sm border border-rule p-3 font-mono text-[13px]" tabindex="0"><code>{{ stage.sample }}</code></pre>
            <div class="mt-3">
                <LabCode v-bind="snippets[stage.snippet]" />
            </div>
        </article>
    </section>
</template>
