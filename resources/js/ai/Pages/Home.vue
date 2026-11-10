<script lang="ts">
import LabLayout from '../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface Capability {
    key: string;
    title: string;
    route: string;
    summary: string;
    guideKey: string;
    guideHref: string;
}

defineProps<{
    title: string;
    about: string;
    runSteps: Array<{ label: string; command: string; note: string | null }>;
    labHref: string;
    guidesHref: string;
    capabilities: Capability[];
    coming: Capability[];
    serverModes: Array<{ key: string; title: string; summary: string }>;
}>();
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
        <ul class="m-0 mt-3 grid list-none grid-cols-1 gap-x-8 gap-y-5 p-0 sm:grid-cols-2">
            <li v-for="capability in capabilities" :key="capability.key" class="flex min-w-0 flex-col gap-1">
                <Link :href="capability.route" class="text-[15px] font-semibold text-ink underline">{{ capability.title }}</Link>
                <span class="text-[15px]">{{ capability.summary }}</span>
                <Link :href="capability.guideHref" class="text-[13px] text-ink underline">How it works</Link>
            </li>
        </ul>
        <template v-if="coming.length > 0">
            <h3 class="m-0 mt-5 text-[15px]">Coming</h3>
            <ul class="m-0 mt-2 list-none p-0 text-[15px] text-muted">
                <li v-for="item in coming" :key="item.key" class="flex flex-wrap items-baseline gap-x-2">
                    <Link :href="item.route" class="font-semibold text-ink underline">{{ item.title }}</Link>
                    <span>{{ item.summary }}</span>
                    <Link :href="item.guideHref" class="text-[13px] text-ink underline">How it works</Link>
                </li>
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
            </li>
        </ul>
    </section>

    <section aria-labelledby="home-guides" class="mt-8 border-t border-rule pt-6">
        <h2 id="home-guides" class="m-0 text-[18px]">Guides</h2>
        <p class="mt-3 text-[15px]">
            Each feature has a guide: how it works, what can go wrong, what to change and what to measure.
            <Link :href="guidesHref" class="font-semibold text-ink underline">Read the guides</Link>
        </p>
    </section>
</template>
