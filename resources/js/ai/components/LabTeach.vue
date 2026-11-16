<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LabHowItWorks from './LabHowItWorks.vue';
import type { GuideRunState, GuideStep } from '../composables/useGuideSteps';

export interface Teach {
    key: string;
    title: string;
    /** The guide page URL, built by the server; a part or row adds the tab as a hash. */
    href: string;
    works: GuideStep[];
    build: { step: string; detail: string; tab: string | null; doc: string | null }[];
    watch: { point: string; detail: string; tab: string }[];
    elsewhere: { domain: string; problem: string; how: string }[];
}

/**
 * The teach block below a lab page. Only How it works follows a run (through events and state); the other three parts
 * are memoised on the teach prop, so streamed tokens never re-render them.
 */
withDefaults(
    defineProps<{
        teach: Teach;
        events?: string[];
        state?: GuideRunState;
    }>(),
    { events: () => [], state: 'idle' },
);

const staticParts = ['build', 'watch', 'elsewhere'] as const;
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
        <details data-teach-part="works" open class="rounded-sm border border-rule px-4 py-3">
            <summary class="cursor-pointer text-[15px] font-medium">{{ titles.works }}</summary>
            <LabHowItWorks bare :steps="teach.works" :events="events" :state="state" />
            <Link :href="`${teach.href}#works`" data-guide-link class="mt-3 inline-block text-[13px] text-ink underline">{{ linkLabels.works }}</Link>
        </details>

        <details
            v-for="part in staticParts"
            :key="part"
            v-memo="[teach]"
            :data-teach-part="part"
            class="rounded-sm border border-rule px-4 py-3"
        >
            <summary class="cursor-pointer text-[15px] font-medium">{{ titles[part] }}</summary>

            <ol v-if="part === 'build'" class="m-0 mt-3 flex list-decimal flex-col gap-2 pl-5 text-[14px] text-muted">
                <li v-for="(row, index) in teach.build" :key="index">
                    <span class="font-medium text-ink">{{ row.step }}</span>
                    <span class="block">{{ row.detail }}</span>
                    <Link v-if="row.tab !== null" :href="`${teach.href}#${row.tab}`" class="text-[13px] text-ink underline">Guide: how to do it</Link>
                    <a v-if="row.doc !== null" :href="row.doc" rel="noopener noreferrer" target="_blank" class="ml-3 text-[13px] text-ink underline">Public docs</a>
                </li>
            </ol>

            <ul v-else-if="part === 'watch'" class="m-0 mt-3 flex list-disc flex-col gap-2 pl-5 text-[14px] text-muted">
                <li v-for="(row, index) in teach.watch" :key="index">
                    <span class="font-medium text-ink">{{ row.point }}</span>
                    <span class="block">{{ row.detail }}</span>
                    <Link :href="`${teach.href}#${row.tab}`" class="text-[13px] text-ink underline">Guide: what goes wrong</Link>
                </li>
            </ul>

            <ul v-else class="m-0 mt-3 flex list-none flex-col gap-2 p-0 text-[14px] text-muted">
                <li v-for="(row, index) in teach.elsewhere" :key="index">
                    <span class="font-medium text-ink">{{ row.domain }}</span>
                    <span class="block">{{ row.problem }}</span>
                    <span class="block">{{ row.how }}</span>
                </li>
            </ul>

            <Link :href="`${teach.href}#${part}`" data-guide-link class="mt-3 inline-block text-[13px] text-ink underline">{{ linkLabels[part] }}</Link>
        </details>
    </section>
</template>
