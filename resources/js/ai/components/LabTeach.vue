<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface Teach {
    key: string;
    title: string;
    works: { title: string; summary: string }[];
    build: { step: string; detail: string; tab: string | null; doc: string | null }[];
    watch: { point: string; detail: string; tab: string }[];
    elsewhere: { domain: string; problem: string; how: string }[];
}

const props = defineProps<{
    teach: Teach;
}>();

const guideBase = `/ai/guides/${props.teach.key}`;

const tabAnchors: Record<string, string> = { recipes: 'modify', failures: 'support', support: 'support' };

function rowHref(tab: string | null): string | null {
    return tab === null ? null : `${guideBase}#${tabAnchors[tab] ?? tab}`;
}

const parts = ['works', 'build', 'watch', 'elsewhere'] as const;
const titles = {
    works: 'How it works',
    build: 'Build it yourself',
    watch: 'Watch out for',
    elsewhere: 'Same pattern elsewhere',
} as const;
const linkLabels = {
    works: 'Read how it works in the guide',
    build: 'Open the build steps in the guide',
    watch: 'Open the warnings in the guide',
    elsewhere: 'Open the examples in the guide',
} as const;
</script>

<template>
    <section data-lab-teach class="mt-6 flex flex-col gap-3" aria-label="What this page teaches">
        <details
            v-for="part in parts"
            :key="part"
            :data-teach-part="part"
            :open="part === 'works'"
            class="rounded-sm border border-rule px-4 py-3"
        >
            <summary class="cursor-pointer text-[15px] font-medium">{{ titles[part] }}</summary>

            <div v-if="part === 'works'" class="mt-3">
                <slot name="works">
                    <ol class="m-0 flex list-decimal flex-col gap-2 pl-5 text-[14px] text-muted">
                        <li v-for="(step, index) in teach.works" :key="index">
                            <span class="font-medium text-ink">{{ step.title }}</span>
                            <span class="block">{{ step.summary }}</span>
                        </li>
                    </ol>
                </slot>
            </div>

            <ol v-else-if="part === 'build'" class="m-0 mt-3 flex list-decimal flex-col gap-2 pl-5 text-[14px] text-muted">
                <li v-for="(row, index) in teach.build" :key="index">
                    <span class="font-medium text-ink">{{ row.step }}</span>
                    <span class="block">{{ row.detail }}</span>
                    <Link v-if="rowHref(row.tab) !== null" :href="rowHref(row.tab) ?? ''" class="text-[13px] text-ink underline">Guide: how to do it</Link>
                    <a v-if="row.doc !== null" :href="row.doc" rel="noopener" target="_blank" class="ml-3 text-[13px] text-ink underline">Public docs</a>
                </li>
            </ol>

            <ul v-else-if="part === 'watch'" class="m-0 mt-3 flex list-disc flex-col gap-2 pl-5 text-[14px] text-muted">
                <li v-for="(row, index) in teach.watch" :key="index">
                    <span class="font-medium text-ink">{{ row.point }}</span>
                    <span class="block">{{ row.detail }}</span>
                    <Link :href="rowHref(row.tab) ?? guideBase" class="text-[13px] text-ink underline">Guide: what goes wrong</Link>
                </li>
            </ul>

            <ul v-else class="m-0 mt-3 flex list-none flex-col gap-2 p-0 text-[14px] text-muted">
                <li v-for="(row, index) in teach.elsewhere" :key="index">
                    <span class="font-medium text-ink">{{ row.domain }}</span>
                    <span class="block">{{ row.problem }}</span>
                    <span class="block">{{ row.how }}</span>
                </li>
            </ul>

            <Link :href="`${guideBase}#${part}`" data-guide-link class="mt-3 inline-block text-[13px] text-ink underline">{{ linkLabels[part] }}</Link>
        </details>
    </section>
</template>
