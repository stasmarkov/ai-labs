<script lang="ts">
export interface PacketSource {
    key: string;
    kind: string;
    rate: number;
    x1: number;
    y1: number;
    cx: number;
    cy: number;
    x2: number;
    y2: number;
}
</script>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Moves the packets outside Vue: one <g> whose circles are created once and moved with setAttribute in a
 * requestAnimationFrame loop, so a frame never re-renders a component. A packet follows its connector, a quadratic curve
 * (start, control, end); a frame only sets transforms, and a fill only when a pooled circle changes kind. The loop runs only while there are packets,
 * the scene is not paused, the tab is visible and the scene is on screen.
 */
const props = defineProps<{
    sources: PacketSource[];
    colors: Record<string, string>;
    crossingSeconds: number;
    paused: boolean;
    restartToken: number;
}>();

const svgNamespace = 'http://www.w3.org/2000/svg';
const layer = ref<SVGGElement | null>(null);
const pool: SVGCircleElement[] = [];
const kinds: string[] = [];

let elapsed = 0;
let lastTime = 0;
let frame = 0;
let onScreen = true;
let observer: IntersectionObserver | null = null;

function circleAt(index: number): SVGCircleElement {
    let circle = pool[index];

    if (!circle) {
        circle = document.createElementNS(svgNamespace, 'circle');
        circle.setAttribute('r', '5.5');
        circle.setAttribute('stroke', '#ffffff');
        circle.setAttribute('stroke-width', '1.5');
        layer.value?.appendChild(circle);
        pool.push(circle);
        kinds.push('');
    }

    return circle;
}

function draw(): void {
    let count = 0;

    for (const source of props.sources) {
        const spacing = 1 / source.rate;
        const phase = elapsed % spacing;

        for (let age = phase; age < props.crossingSeconds; age += spacing) {
            const progress = age / props.crossingSeconds;
            const rest = 1 - progress;
            const x = rest * rest * source.x1 + 2 * rest * progress * source.cx + progress * progress * source.x2;
            const y = rest * rest * source.y1 + 2 * rest * progress * source.cy + progress * progress * source.y2;
            const circle = circleAt(count);

            circle.setAttribute('transform', `translate(${x} ${y})`);

            if (kinds[count] !== source.kind) {
                circle.setAttribute('fill', props.colors[source.kind] ?? '#1a1916');
                kinds[count] = source.kind;
            }

            circle.removeAttribute('display');
            count += 1;
        }
    }

    for (let index = count; index < pool.length; index += 1) {
        pool[index].setAttribute('display', 'none');
    }
}

function shouldRun(): boolean {
    return !props.paused && props.sources.length > 0 && !document.hidden && onScreen;
}

function tick(time: number): void {
    if (lastTime !== 0) {
        elapsed += Math.min((time - lastTime) / 1000, 0.1);
    }

    lastTime = time;
    draw();
    frame = requestAnimationFrame(tick);
}

function stop(): void {
    if (frame !== 0) {
        cancelAnimationFrame(frame);
        frame = 0;
    }

    lastTime = 0;
}

function update(): void {
    if (shouldRun()) {
        if (frame === 0) {
            frame = requestAnimationFrame(tick);
        }

        return;
    }

    stop();
    draw();
}

function onVisibilityChange(): void {
    update();
}

watch(() => [props.sources, props.paused], update);

watch(() => props.restartToken, () => {
    elapsed = 0;
    draw();
});

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibilityChange);

    const target = layer.value?.ownerSVGElement ?? layer.value;

    if (target && typeof IntersectionObserver === 'function') {
        observer = new IntersectionObserver((entries) => {
            onScreen = entries.some((entry) => entry.isIntersecting);
            update();
        });
        observer.observe(target);
    }

    update();
});

onBeforeUnmount(() => {
    stop();
    observer?.disconnect();
    document.removeEventListener('visibilitychange', onVisibilityChange);
});
</script>

<template>
    <g ref="layer" class="scene-packets" />
</template>
