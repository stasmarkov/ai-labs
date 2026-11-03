<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import LabStatusDot from '../components/LabStatusDot.vue';
import LabTag from '../components/LabTag.vue';

interface LabShared {
    textModel?: string;
    embeddingModel?: string;
    ollama?: { reachable: boolean | 'not-used' };
}

const navigation = [
    { href: '/ai', label: 'Overview' },
    { href: '/ai/describe', label: 'Describe' },
    { href: '/ai/semantic', label: 'Semantic search' },
    { href: '/ai/docs', label: 'Docs search' },
    { href: '/ai/chat', label: 'Chat' },
    { href: '/ai/photos', label: 'Photos' },
];

const page = usePage();
const lab = computed(() => (page.props.lab ?? page.props) as LabShared);

const ollama = computed<{ state: 'ok' | 'down' | 'idle'; label: string }>(() => {
    const reachable = lab.value.ollama?.reachable;

    if (reachable === true) {
        return { state: 'ok', label: 'Ollama reachable' };
    }

    if (reachable === false) {
        return { state: 'down', label: 'Ollama unreachable' };
    }

    return { state: 'idle', label: 'Ollama not used' };
});

function isCurrent(href: string): boolean {
    return page.url === href || (href !== '/ai' && page.url.startsWith(`${href}/`));
}
</script>

<template>
    <div class="min-h-screen">
        <a href="#lab-content" class="lab-skip-link">Skip to content</a>
        <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-rule px-4 py-3 sm:px-6">
            <span class="text-[18px] font-semibold">AI lab</span>
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <LabTag v-if="lab.textModel" caption="text">{{ lab.textModel }}</LabTag>
                <LabTag v-if="lab.embeddingModel" caption="embeddings">{{ lab.embeddingModel }}</LabTag>
            </div>
            <div class="ml-auto">
                <LabStatusDot :state="ollama.state" :label="ollama.label" />
            </div>
        </header>

        <div class="mx-auto flex max-w-[1200px] flex-col md:flex-row">
            <nav aria-label="Lab pages" class="shrink-0 border-b border-rule px-4 py-3 md:w-52 md:border-b-0 md:border-r md:px-6 md:py-6">
                <ul class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 md:flex-col md:gap-y-2">
                    <li v-for="item in navigation" :key="item.href">
                        <Link
                            :href="item.href"
                            class="inline-block py-1 text-[15px]"
                            :class="isCurrent(item.href) ? 'font-semibold text-signal' : 'text-ink'"
                            :aria-current="isCurrent(item.href) ? 'page' : undefined"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <div class="flex min-w-0 flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:flex-row">
                <main id="lab-content" tabindex="-1" class="min-w-0 flex-1">
                    <slot />
                </main>
                <aside id="lab-rail" aria-label="Under the hood" class="min-w-0 empty:hidden border-t border-rule pt-6 lg:w-[360px] lg:shrink-0 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                    <slot name="rail" />
                </aside>
            </div>
        </div>
    </div>
</template>
