<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface Entry {
    key: string;
    name: string;
    idea: string;
    time: string;
    href: string;
    outcomes: Record<string, string>;
}

defineProps<{
    guide: { key: string; title: string; href: string };
    outcomes: { id: string; text: string }[];
    rubric: { name: string; meaning: string }[];
    entries: Entry[];
}>();
</script>

<template>
    <p class="m-0 text-[14px] text-muted">
        <Link href="/ai/learn" class="text-ink underline">Learn</Link>
        /
        <Link :href="guide.href" class="text-ink underline">{{ guide.title }}</Link>
    </p>
    <h1 class="mt-2 text-[28px] font-semibold">Guide format trial: {{ guide.title }}</h1>
    <p class="mt-2 text-[15px] text-muted">
        The same guide taught six ways. Try each one, then judge them against the same five outcomes and the same four criteria.
    </p>

    <section aria-labelledby="formats-outcomes" class="mt-6">
        <h2 id="formats-outcomes" class="m-0 text-[18px]">What you can do afterwards</h2>
        <ol class="mt-3 list-none space-y-1 p-0">
            <li v-for="outcome in outcomes" :key="outcome.id" class="text-[15px]">
                <span class="font-semibold">{{ outcome.id }}</span> {{ outcome.text }}
            </li>
        </ol>
    </section>

    <section aria-labelledby="formats-rubric" class="mt-6">
        <h2 id="formats-rubric" class="m-0 text-[18px]">How to judge</h2>
        <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div v-for="criterion in rubric" :key="criterion.name" class="min-w-0 rounded-sm border border-rule p-3">
                <dt class="font-semibold">{{ criterion.name }}</dt>
                <dd class="m-0 mt-1 text-[15px]">{{ criterion.meaning }}</dd>
            </div>
        </dl>
    </section>

    <section aria-labelledby="formats-entries" class="mt-6">
        <h2 id="formats-entries" class="m-0 text-[18px]">The six ways</h2>
        <ul class="mt-3 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
            <li v-for="entry in entries" :key="entry.key" class="min-w-0 rounded-sm border border-rule p-4">
                <h3 class="m-0 text-[16px] font-semibold">
                    <Link :href="entry.href" class="text-ink underline">{{ entry.name }}</Link>
                </h3>
                <p class="mb-0 mt-2 text-[15px]">{{ entry.idea }}</p>
                <p class="mb-0 mt-2 text-[14px] text-muted">Expected time (estimate): {{ entry.time }}</p>
                <h4 class="mb-0 mt-3 text-[14px] font-semibold">Where it teaches each outcome</h4>
                <dl class="m-0 mt-1 space-y-1 text-[14px]">
                    <div v-for="(place, id) in entry.outcomes" :key="id" class="flex min-w-0 gap-2">
                        <dt class="shrink-0 font-semibold">{{ id }}</dt>
                        <dd class="m-0 min-w-0">{{ place }}</dd>
                    </div>
                </dl>
            </li>
        </ul>
    </section>
</template>
