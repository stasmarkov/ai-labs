<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PacketLayer, { type PacketSource } from './PacketLayer.vue';
import type { EdgeState, NodeState, SceneEdge, SceneNode, Step } from '../../composables/lessonTypes';
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
const nodeWidth = 160;
const nodeHeight = 76;

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

const reducedMotion = useReducedMotion();

const shown = ref<Step>(props.step);
const suppressPackets = ref(false);
const overlay = ref(false);
const container = ref<HTMLElement | null>(null);

const nodeById = computed(() => new Map(props.nodes.map((node) => [node.id, node])));

const emptyNode: NodeState = { load: 0, queue: 0, status: 'ok', badge: null };

function nodeState(id: string): NodeState {
    return shown.value.nodes[id] ?? emptyNode;
}

function edgeState(id: string): EdgeState | null {
    const state = shown.value.edges[id];

    return state && state.visible ? state : null;
}

const visibleNodes = computed(() => props.nodes.filter((node) => nodeState(node.id).status !== 'hidden'));

const visibleEdges = computed(() => props.edges.filter((edge) => {
    const from = nodeById.value.get(edge.from);
    const to = nodeById.value.get(edge.to);

    return from && to && nodeState(from.id).status !== 'hidden' && nodeState(to.id).status !== 'hidden' && edgeState(edge.id) !== null;
}));

function endpoints(edge: SceneEdge): { x1: number; y1: number; x2: number; y2: number } {
    const from = nodeById.value.get(edge.from) as SceneNode;
    const to = nodeById.value.get(edge.to) as SceneNode;

    return { x1: from.x, y1: from.y, x2: to.x, y2: to.y };
}

function pointAt(edge: SceneEdge, progress: number): { x: number; y: number } {
    const { x1, y1, x2, y2 } = endpoints(edge);

    return { x: x1 + (x2 - x1) * progress, y: y1 + (y2 - y1) * progress };
}

const sources = computed<PacketSource[]>(() => {
    if (reducedMotion.value || suppressPackets.value) {
        return [];
    }

    const result: PacketSource[] = [];

    for (const edge of visibleEdges.value) {
        for (const [kind, rate] of Object.entries(edgeState(edge.id)?.packets ?? {})) {
            if (rate > 0) {
                result.push({ key: `${edge.id}:${kind}`, kind, rate, ...endpoints(edge) });
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
                result.push({ key: `${edge.id}:${kind}:${index}`, kind, ...pointAt(edge, index / (count + 1)) });
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
    <div ref="container" class="bg-paper" :class="overlay ? 'fixed inset-0 z-50 overflow-auto p-4' : ''">
        <slot />

        <div class="relative mt-3 rounded-sm border border-rule bg-white">
            <svg viewBox="0 0 1200 600" class="block h-auto w-full" :class="paused ? 'scene-paused' : ''" role="img" :aria-label="`Scene for step ${step.number}: ${step.title}. A text description follows.`">
                <g>
                    <g v-for="edge in visibleEdges" :key="edge.id">
                        <line v-bind="endpoints(edge)" stroke="#8a8478" stroke-width="3" />
                    </g>
                </g>

                <g
                    v-for="node in visibleNodes"
                    :key="node.id"
                    v-memo="[nodeState(node.id).load, nodeState(node.id).queue, nodeState(node.id).status, nodeState(node.id).badge]"
                    class="scene-node"
                    :transform="`translate(${node.x - nodeWidth / 2} ${node.y - nodeHeight / 2})`"
                >
                    <rect x="6" y="6" :width="nodeWidth" :height="nodeHeight" rx="6" fill="#d8d2c4" />
                    <rect :width="nodeWidth" :height="nodeHeight" rx="6" :fill="statusFills[nodeState(node.id).status] ?? '#ffffff'" stroke="#1a1916" stroke-width="2" class="scene-fill" />
                    <text :x="nodeWidth / 2" y="28" text-anchor="middle" font-size="18" font-weight="600" fill="#1a1916">{{ node.label }}</text>
                    <rect x="12" y="44" :width="nodeWidth - 24" height="10" rx="3" fill="#d8d2c4" />
                    <rect x="12" y="44" :width="((nodeWidth - 24) * Math.min(Math.max(nodeState(node.id).load, 0), 100)) / 100" height="10" rx="3" fill="#c2410c" class="scene-bar" />
                    <text :x="nodeWidth / 2" y="68" text-anchor="middle" font-size="13" fill="#1a1916">load {{ nodeState(node.id).load }} %</text>
                    <g v-if="nodeState(node.id).queue > 0" :transform="`translate(${nodeWidth - 6} 2)`">
                        <circle r="18" fill="#c2410c" stroke="#ffffff" stroke-width="2" />
                        <text y="6" text-anchor="middle" font-size="16" font-weight="700" fill="#ffffff">{{ nodeState(node.id).queue }}</text>
                    </g>
                    <text v-if="nodeState(node.id).badge" :x="nodeWidth / 2" :y="nodeHeight + 26" text-anchor="middle" font-size="14" font-weight="600" fill="#1a1916">{{ nodeState(node.id).badge }}</text>
                </g>

                <g v-if="reducedMotion">
                    <circle v-for="marker in markers" :key="marker.key" :cx="marker.x" :cy="marker.y" r="9" fill="none" :stroke="packetColors[marker.kind] ?? '#1a1916'" stroke-width="3" stroke-dasharray="4 3" />
                </g>
                <PacketLayer v-else :sources="sources" :colors="packetColors" :crossing-seconds="crossingSeconds" :paused="paused" :restart-token="replayToken" />
            </svg>

            <ul class="sr-only">
                <li v-for="line in textEquivalent" :key="line">{{ line }}</li>
                <li v-for="(change, index) in step.changed" :key="`change-${index}`">Change {{ change.sign }} {{ change.text }}</li>
            </ul>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <ul class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-[13px]" aria-label="Packet legend">
                <li v-for="kind in usedKinds" :key="kind" class="flex items-center gap-1">
                    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="5" :fill="reducedMotion ? 'none' : packetColors[kind]" :stroke="packetColors[kind]" stroke-width="2" :stroke-dasharray="reducedMotion ? '3 2' : undefined" /></svg>
                    {{ kind }}
                </li>
            </ul>
            <button
                type="button"
                class="inline-flex min-h-10 items-center rounded-sm border border-rule px-3 text-[14px] text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                :aria-pressed="overlay"
                @click="toggleFullscreen"
            >Full screen</button>
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

@media (prefers-reduced-motion: reduce) {
    .scene-fill,
    .scene-bar {
        transition: none;
    }
}
</style>
