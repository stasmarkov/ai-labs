<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface TopicLink {
    title: string;
    href: string;
}

/**
 * The header both a lesson page and a guide page share: a Learn / module / topic breadcrumb, a switch between walking
 * through the topic and reading it as a reference, the other ways into the same topic and a short meta line. Without a
 * module (the guide that explains the lessons) only the breadcrumb is shown.
 */
defineProps<{
    module: { slug: string; title: string } | null;
    title: string;
    meta: string | null;
    view: 'walk' | 'reference';
    walkHref: string | null;
    referenceHref: string | null;
    also: TopicLink[];
    trialHref?: string | null;
}>();

const focus = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';
</script>

<template>
    <div class="mb-2">
        <nav aria-label="Breadcrumb" class="text-[14px] text-muted">
            <Link href="/ai/learn" :class="['text-ink underline', focus]">Learn</Link>
            <template v-if="module">
                / <Link :href="`/ai/learn#${module.slug}`" :class="['text-ink underline', focus]">{{ module.title }}</Link>
            </template>
            / <span class="text-ink">{{ title }}</span>
        </nav>
        <nav aria-label="This topic" v-if="module" class="mt-2">
            <ul class="m-0 flex list-none flex-wrap items-center gap-x-4 gap-y-1 p-0 text-[14px]">
                <li>
                    <span v-if="view === 'walk'" aria-current="page" class="font-semibold">Walk through</span>
                    <Link v-else-if="walkHref" :href="walkHref" :class="['text-signal underline', focus]">Walk through</Link>
                    <span v-else aria-disabled="true" class="text-muted">Walk through · lesson coming</span>
                </li>
                <li>
                    <span v-if="view === 'reference'" aria-current="page" class="font-semibold">Reference</span>
                    <Link v-else-if="referenceHref" :href="referenceHref" :class="['text-signal underline', focus]">Reference</Link>
                    <span v-else aria-disabled="true" class="text-muted">Reference</span>
                </li>
                <li v-if="also.length > 0" class="flex flex-wrap items-center gap-x-3 text-muted">
                    Also:
                    <Link v-for="link in also" :key="link.href" :href="link.href" :class="['text-signal underline', focus]">{{ link.title }}</Link>
                </li>
            </ul>
        </nav>
        <p v-if="meta || trialHref" class="m-0 mt-1 text-[13px] text-muted">
            {{ meta }}
            <template v-if="trialHref">
                <template v-if="meta"> · </template>
                <Link :href="trialHref" :class="['text-signal underline', focus]">Format trial</Link>
            </template>
        </p>
    </div>
</template>
