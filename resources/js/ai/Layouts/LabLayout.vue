<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import LabStatusDot from '../components/LabStatusDot.vue';
import LabTag from '../components/LabTag.vue';

interface NavigationItem {
    href: string;
    label: string;
}

interface NavigationGroup {
    label: string;
    items: NavigationItem[];
}

interface LabShared {
    textModel?: string;
    embeddingModel?: string;
    ollama?: { reachable: boolean | 'not-used' };
    navigation?: NavigationGroup[];
}

const page = usePage();
const lab = computed(() => (page.props.lab ?? {}) as LabShared);
const groups = computed(() => lab.value.navigation ?? []);
const overviewHref = computed(() => groups.value[0]?.items[0]?.href ?? '/ai');
const courseHref = '/ai/learn';

/**
 * The lesson player puts a scene, a step panel and a course rail side by side, and a guide page sits beside the course
 * sidebar and its own rail, so both get the whole window up to 1440 px and a slightly narrower lab navigation.
 */
const lesson = computed(() => page.component === 'Lab/Lesson' || page.component === 'Lab/Guide');
const width = computed(() => (lesson.value ? 'max-w-[1440px]' : 'max-w-[1200px]'));

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
    const path = page.url.split('?')[0];

    if (href === courseHref && path.startsWith('/ai/guides')) {
        return true;
    }

    return path === href || (href !== overviewHref.value && path.startsWith(`${href}/`));
}
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a href="#lab-content" class="lab-skip-link">Skip to content</a>
        <header class="border-b border-rule">
            <div class="mx-auto flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6" :class="width">
                <span class="text-[18px] font-semibold">AI lab</span>
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <LabTag v-if="lab.textModel" caption="text">{{ lab.textModel }}</LabTag>
                    <LabTag v-if="lab.embeddingModel" caption="embeddings">{{ lab.embeddingModel }}</LabTag>
                </div>
                <div class="ml-auto">
                    <LabStatusDot :state="ollama.state" :label="ollama.label" />
                </div>
            </div>
        </header>

        <div class="mx-auto flex w-full flex-1 flex-col md:flex-row" :class="width">
            <nav aria-label="Lab pages" class="shrink-0 border-b border-rule px-4 py-3 md:border-b-0 md:border-r md:py-6" :class="lesson ? 'md:w-48 md:px-5' : 'md:w-52 md:px-6'">
                <div class="flex flex-wrap gap-x-6 gap-y-3 md:flex-col md:gap-y-5">
                    <div v-for="(group, index) in groups" :key="group.label" class="min-w-0">
                        <h2 :id="`lab-nav-group-${index}`" class="m-0 mb-1 text-[12px] font-semibold uppercase tracking-wide text-muted">
                            {{ group.label }}
                        </h2>
                        <ul :aria-labelledby="`lab-nav-group-${index}`" class="m-0 flex list-none flex-wrap gap-x-4 gap-y-0 p-0 md:flex-col">
                            <li v-for="item in group.items" :key="item.href">
                                <Link
                                    :href="item.href"
                                    class="inline-block rounded-sm py-1 text-[15px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal"
                                    :class="isCurrent(item.href) ? 'font-semibold text-signal' : 'text-ink'"
                                    :aria-current="isCurrent(item.href) ? 'page' : undefined"
                                >
                                    {{ item.label }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
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
