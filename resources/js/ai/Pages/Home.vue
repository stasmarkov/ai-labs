<script lang="ts">
import LabLayout from '../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface GuideLink {
    path: string;
    anchor: string | null;
}

interface Capability {
    key: string;
    title: string;
    route: string;
    slice: string;
    guide: GuideLink;
    code: string | null;
}

defineProps<{
    title: string;
    about: string;
    runSteps: Array<{ label: string; command: string; note: string | null }>;
    labHref: string;
    capabilities: Capability[];
    coming: Array<{ key: string; title: string; slice: string }>;
    serverModes: Array<{ key: string; title: string; summary: string; guide: GuideLink }>;
    numbers: GuideLink & { note: string };
    guide: GuideLink;
}>();

function location(link: GuideLink): string {
    return link.anchor === null ? link.path : `${link.path}#${link.anchor}`;
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">{{ title }}</h1>
    <p class="mt-3 max-w-[68ch] text-[15px]">{{ about }}</p>
    <p class="mt-3 text-[15px]">
        <Link :href="labHref" class="font-semibold text-ink underline">Open the lab</Link>
    </p>

    <section aria-labelledby="home-run" class="mt-8 border-t border-rule pt-6">
        <h2 id="home-run" class="m-0 text-[18px]">Run it</h2>
        <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
            <li v-for="step in runSteps" :key="step.command" class="flex flex-col gap-1">
                <span class="text-[13px] text-muted">{{ step.label }}</span>
                <code class="block max-w-full overflow-x-auto font-mono text-[13px]">{{ step.command }}</code>
                <span v-if="step.note" class="text-[13px] text-muted">{{ step.note }}</span>
            </li>
        </ol>
    </section>

    <section aria-labelledby="home-map" class="mt-8 border-t border-rule pt-6">
        <h2 id="home-map" class="m-0 text-[18px]">What you can try</h2>
        <p class="mt-2 text-[13px] text-muted">Guide sections and code paths are files in this repository; open them in your editor.</p>
        <ul class="m-0 mt-3 grid list-none grid-cols-1 gap-x-8 gap-y-5 p-0 sm:grid-cols-2">
            <li v-for="capability in capabilities" :key="capability.key" class="flex min-w-0 flex-col gap-1">
                <Link :href="capability.route" class="text-[15px] font-semibold text-ink underline">{{ capability.title }}</Link>
                <span class="font-mono text-[13px] text-muted">{{ capability.slice }}</span>
                <code class="break-words font-mono text-[13px]">{{ location(capability.guide) }}</code>
                <code v-if="capability.code" class="break-words font-mono text-[13px]">{{ capability.code }}</code>
            </li>
        </ul>
        <template v-if="coming.length > 0">
            <h3 class="m-0 mt-5 text-[15px]">Coming</h3>
            <ul class="m-0 mt-2 list-none p-0 text-[15px] text-muted">
                <li v-for="item in coming" :key="item.key">{{ item.title }} · {{ item.slice }}</li>
            </ul>
        </template>
    </section>

    <section aria-labelledby="home-servers" class="mt-8 border-t border-rule pt-6">
        <h2 id="home-servers" class="m-0 text-[18px]">Server modes</h2>
        <ul class="m-0 mt-3 grid list-none grid-cols-1 gap-x-8 gap-y-4 p-0 sm:grid-cols-3">
            <li v-for="mode in serverModes" :key="mode.key" class="flex min-w-0 flex-col gap-1">
                <span class="text-[15px] font-semibold">{{ mode.title }}</span>
                <code class="font-mono text-[13px] text-muted">ddev server {{ mode.key }}</code>
                <span class="text-[15px]">{{ mode.summary }}</span>
                <code class="break-words font-mono text-[13px]">{{ location(mode.guide) }}</code>
            </li>
        </ul>
    </section>

    <section aria-labelledby="home-numbers" class="mt-8 border-t border-rule pt-6">
        <h2 id="home-numbers" class="m-0 text-[18px]">Where the numbers are</h2>
        <p class="mt-3 text-[15px]">{{ numbers.note }}</p>
        <code class="mt-1 block break-words font-mono text-[13px]">{{ location(numbers) }}</code>
        <p class="mt-4 text-[15px]">The full walk-through, in the order it was built:</p>
        <code class="mt-1 block break-words font-mono text-[13px]">{{ location(guide) }}</code>
    </section>
</template>
