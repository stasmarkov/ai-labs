<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PacketLayer, { type PacketSource } from './PacketLayer.vue';
import SceneIcon from './SceneIcon.vue';
import type { EdgeState, NodeState, SceneEdge, SceneNode, Step } from '../../composables/lessonTypes';
import { cardHeight, cardWidth, curveFor, fitViewBox, pathOf, pointOn, titleLines, type Curve } from '../../composables/sceneGeometry';
import { useReducedMotion } from '../../composables/useReducedMotion';

const props = defineProps<{
    nodes: SceneNode[];
    edges: SceneEdge[];
    legend: string[];
    step: Step;
    previous: Step | null;
    paused: boolean;
    replayToken: number;
}>();

const crossingSeconds = 1.2;

const packetColors: Record<string, string> = {
    request: '#1d4ed8',
    token: '#2b7a4b',
    tool: '#7c3aed',
    embedding: '#0e7490',
    'cache-hit': '#b45309',
    failure: '#b42318',
    health: '#6b665c',
};

const statusFills: Record<string, string> = { ok: '#ffffff', busy: '#fde8d8', down: '#fbdcd8' };

/** Card outline and status dot per status; text on the card stays ink. */
const statusStrokes: Record<string, string> = { ok: '#1a1916', busy: '#c2410c', down: '#b42318' };

const statusWords: Record<string, string> = { ok: 'ok', busy: 'busy', down: 'down' };

const reducedMotion = useReducedMotion();

const shown = ref<Step>(props.step);
const suppressPackets = ref(false);
const overlay = ref(false);
const native = ref(false);
const container = ref<HTMLElement | null>(null);

const nodeById = computed(() => new Map(props.nodes.map((node) => [node.id, node])));

const viewBox = computed(() => fitViewBox(props.nodes));

/** Every connector is laid out once per lesson; two-way pairs get separate lanes. */
const curves = computed(() => {
    const pairs = new Set(props.edges.map((edge) => `${edge.from}>${edge.to}`));
    const result = new Map<string, Curve>();

    for (const edge of props.edges) {
        const from = nodeById.value.get(edge.from);
        const to = nodeById.value.get(edge.to);

        if (from && to) {
            result.set(edge.id, curveFor(edge, from, to, pairs.has(`${edge.to}>${edge.from}`)));
        }
    }

    return result;
});

const emptyNode: NodeState = { load: 0, queue: 0, status: 'ok', badge: null };

function nodeState(id: string): NodeState {
    return shown.value.nodes[id] ?? emptyNode;
}

function edgeState(id: string): EdgeState | null {
    const state = shown.value.edges[id];

    return state && state.visible ? state : null;
}

function isBusyEdge(id: string): boolean {
    return Object.values(edgeState(id)?.packets ?? {}).some((rate) => rate > 0);
}

function loadOf(id: string): number {
    return Math.min(Math.max(nodeState(id).load, 0), 100);
}

function loadColor(load: number): string {
    if (load >= 90) {
        return '#c2410c';
    }

    return load >= 60 ? '#b45309' : '#2b7a4b';
}

/** Badge pills are drawn above the packets, so a packet never hides their text. Their width comes from the text length, at about 8.9 units a character at size 16. */
function badgeWidth(text: string): number {
    return Math.round(text.length * 8.9 + 26);
}

const visibleNodes = computed(() => props.nodes.filter((node) => nodeState(node.id).status !== 'hidden'));

const visibleEdges = computed(() => props.edges.filter((edge) => {
    const from = nodeById.value.get(edge.from);
    const to = nodeById.value.get(edge.to);

    return from && to && curves.value.has(edge.id) && nodeState(from.id).status !== 'hidden' && nodeState(to.id).status !== 'hidden' && edgeState(edge.id) !== null;
}));

function curveOf(edge: SceneEdge): Curve {
    return curves.value.get(edge.id) as Curve;
}

const sources = computed<PacketSource[]>(() => {
    if (reducedMotion.value || suppressPackets.value) {
        return [];
    }

    const result: PacketSource[] = [];

    for (const edge of visibleEdges.value) {
        for (const [kind, rate] of Object.entries(edgeState(edge.id)?.packets ?? {})) {
            if (rate > 0) {
                result.push({ key: `${edge.id}:${kind}`, kind, rate, ...curveOf(edge) });
            }
        }
    }

    return result;
});

interface Marker {
    key: string;
    kind: string;
    x: number;
    y: number;
}

const markers = computed<Marker[]>(() => {
    const result: Marker[] = [];

    for (const edge of visibleEdges.value) {
        for (const [kind, rate] of Object.entries(edgeState(edge.id)?.packets ?? {})) {
            const count = Math.min(rate, 3);

            for (let index = 1; index <= count; index += 1) {
                result.push({ key: `${edge.id}:${kind}:${index}`, kind, ...pointOn(curveOf(edge), index / (count + 1)) });
            }
        }
    }

    return result;
});

const usedKinds = computed(() => props.legend);

const textEquivalent = computed(() => visibleNodes.value.map((node) => {
    const state = nodeState(node.id);
    const queued = state.queue > 0 ? `, ${state.queue} queued` : '';
    const badge = state.badge ? `, ${state.badge}` : '';

    return `${node.label}: ${state.status}, load ${state.load} %${queued}${badge}`;
}));

function swapTo(step: Step): void {
    shown.value = step;
}

watch(() => props.step, (step) => {
    swapTo(step);
    suppressPackets.value = props.paused;
});

watch(() => props.paused, (paused) => {
    if (!paused) {
        suppressPackets.value = false;
    }
});

watch(() => props.replayToken, async () => {
    suppressPackets.value = false;

    if (reducedMotion.value) {
        return;
    }

    if (props.previous) {
        swapTo(props.previous);
        await nextTick();
        await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
        swapTo(props.step);
    }
});

function onFullscreenChange(): void {
    native.value = document.fullscreenElement !== null;

    if (!document.fullscreenElement) {
        overlay.value = false;
    }
}

function onEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && overlay.value) {
        overlay.value = false;
    }
}

async function toggleFullscreen(): Promise<void> {
    if (document.fullscreenElement) {
        await document.exitFullscreen();

        return;
    }

    if (overlay.value) {
        overlay.value = false;

        return;
    }

    if (container.value && typeof container.value.requestFullscreen === 'function') {
        try {
            await container.value.requestFullscreen();

            return;
        } catch {
            // refused by the browser: fall back to the overlay
        }
    }

    overlay.value = true;
}

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
    window.addEventListener('keydown', onEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    window.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div ref="container" class="scene-root bg-paper" :class="overlay ? 'fixed inset-0 z-50 overflow-auto p-4' : ''">
        <slot />

        <div class="relative mt-3 overflow-hidden rounded-md border border-rule bg-[#fbfaf6]">
            <svg
                :viewBox="`${viewBox.x} ${viewBox.y} ${viewBox.width} ${viewBox.height}`"
                class="scene-svg block h-auto w-full"
                :class="paused ? 'scene-paused' : ''"
                role="img"
                :aria-label="`Scene for step ${step.number}: ${step.title}. A text description follows.`"
            >
                <defs>
                    <pattern id="scene-dots" width="24" height="24" patternUnits="userSpaceOnUse">
                        <circle cx="12" cy="12" r="1.3" fill="#ddd7ca" />
                    </pattern>
                    <marker id="scene-arrow-busy" viewBox="0 0 10 10" refX="1" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0 0 10 5 0 10Z" fill="#4a463e" />
                    </marker>
                    <marker id="scene-arrow-idle" viewBox="0 0 10 10" refX="1" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0 0 10 5 0 10Z" fill="#a8a294" />
                    </marker>
                </defs>

                <rect :x="viewBox.x" :y="viewBox.y" :width="viewBox.width" :height="viewBox.height" fill="url(#scene-dots)" />

                <g fill="none" stroke-linecap="round">
                    <path
                        v-for="edge in visibleEdges"
                        :key="edge.id"
                        :d="pathOf(curveOf(edge))"
                        :stroke="isBusyEdge(edge.id) ? '#4a463e' : '#a8a294'"
                        stroke-width="2.5"
                        :stroke-dasharray="isBusyEdge(edge.id) ? undefined : '6 6'"
                        :marker-end="isBusyEdge(edge.id) ? 'url(#scene-arrow-busy)' : 'url(#scene-arrow-idle)'"
                    />
                </g>

                <g
                    v-for="node in visibleNodes"
                    :key="node.id"
                    v-memo="[nodeState(node.id).load, nodeState(node.id).queue, nodeState(node.id).status, nodeState(node.id).badge]"
                    class="scene-node"
                    :transform="`translate(${node.x - cardWidth / 2} ${node.y - cardHeight / 2})`"
                >
                    <rect x="5" y="7" :width="cardWidth" :height="cardHeight" rx="12" fill="#e2dccf" />
                    <rect
                        :width="cardWidth"
                        :height="cardHeight"
                        rx="12"
                        :fill="statusFills[nodeState(node.id).status] ?? '#ffffff'"
                        :stroke="statusStrokes[nodeState(node.id).status] ?? '#1a1916'"
                        stroke-width="2"
                        class="scene-fill"
                    />
                    <rect x="14" y="14" width="38" height="38" rx="9" fill="#f5f2eb" stroke="#d8d2c4" stroke-width="1.5" />
                    <g transform="translate(21 21)"><SceneIcon :kind="node.kind" /></g>
                    <text font-size="20" font-weight="600" fill="#1a1916">
                        <tspan
                            v-for="(line, index) in titleLines(node.label)"
                            :key="index"
                            x="64"
                            :y="titleLines(node.label).length === 1 ? 40 : 30 + index * 22"
                        >{{ line }}</tspan>
                    </text>
                    <rect x="14" y="68" :width="cardWidth - 92" height="10" rx="5" fill="#e2dccf" />
                    <rect x="14" y="68" :width="((cardWidth - 92) * loadOf(node.id)) / 100" height="10" rx="5" :fill="loadColor(loadOf(node.id))" class="scene-bar" />
                    <text :x="cardWidth - 14" y="80" text-anchor="end" font-size="17" font-weight="600" fill="#1a1916">{{ nodeState(node.id).load }} %</text>
                    <circle cx="20" cy="96" r="4.5" :fill="statusStrokes[nodeState(node.id).status] ?? '#1a1916'" />
                    <text x="31" y="102" font-size="16" fill="#1a1916">{{ statusWords[nodeState(node.id).status] ?? nodeState(node.id).status }}<tspan v-if="nodeState(node.id).queue > 0">, {{ nodeState(node.id).queue }} waiting</tspan></text>
                    <g v-if="nodeState(node.id).queue > 0" :transform="`translate(${cardWidth - 4} 4)`">
                        <circle r="18" fill="#c2410c" stroke="#ffffff" stroke-width="2.5" />
                        <text y="6" text-anchor="middle" font-size="17" font-weight="700" fill="#ffffff">{{ nodeState(node.id).queue }}</text>
                    </g>
                </g>

                <g v-if="reducedMotion">
                    <circle v-for="marker in markers" :key="marker.key" :cx="marker.x" :cy="marker.y" r="8" fill="#ffffff" :stroke="packetColors[marker.kind] ?? '#1a1916'" stroke-width="3" stroke-dasharray="4 3" />
                </g>
                <PacketLayer v-else :sources="sources" :colors="packetColors" :crossing-seconds="crossingSeconds" :paused="paused" :restart-token="replayToken" />

                <g aria-hidden="true">
                    <template v-for="node in visibleNodes" :key="node.id">
                        <g v-if="nodeState(node.id).badge" :transform="`translate(${node.x} ${node.y + cardHeight / 2 + 12})`">
                            <rect :x="-badgeWidth(nodeState(node.id).badge ?? '') / 2" y="0" :width="badgeWidth(nodeState(node.id).badge ?? '')" height="30" rx="15" fill="#ffffff" stroke="#d8d2c4" stroke-width="1.5" />
                            <text y="20" text-anchor="middle" font-size="16" font-weight="600" fill="#1a1916">{{ nodeState(node.id).badge }}</text>
                        </g>
                    </template>
                </g>
            </svg>

            <ul class="sr-only">
                <li v-for="line in textEquivalent" :key="line">{{ line }}</li>
                <li v-for="(change, index) in step.changed" :key="`change-${index}`">Change {{ change.sign }} {{ change.text }}</li>
            </ul>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <ul class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-[13px] text-muted" aria-label="Packet legend">
                <li v-for="kind in usedKinds" :key="kind" class="flex items-center gap-1.5">
                    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="5" :fill="reducedMotion ? 'none' : packetColors[kind]" :stroke="packetColors[kind]" stroke-width="2" :stroke-dasharray="reducedMotion ? '3 2' : undefined" /></svg>
                    {{ kind }}
                </li>
            </ul>
            <button
                type="button"
                class="inline-flex min-h-9 items-center rounded-sm border border-rule bg-white px-3 text-[14px] text-ink hover:bg-[#ebe6da] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                :aria-pressed="overlay || native"
                @click="toggleFullscreen"
            >{{ overlay || native ? 'Exit full screen' : 'Full screen' }}</button>
        </div>
    </div>
</template>

<style scoped>
.scene-fill {
    transition: fill 0.4s ease;
}

.scene-bar {
    transition: width 0.4s ease;
}

.scene-paused .scene-fill,
.scene-paused .scene-bar {
    transition: none;
}

.scene-root:fullscreen {
    overflow: auto;
    padding: 1rem;
}

.scene-root:fullscreen .scene-svg,
.scene-root.fixed .scene-svg {
    max-height: calc(100vh - 12rem);
}

@media (prefers-reduced-motion: reduce) {
    .scene-fill,
    .scene-bar {
        transition: none;
    }
}
</style>
