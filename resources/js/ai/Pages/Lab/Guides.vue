<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabTag from '../../components/LabTag.vue';

interface GuideCard {
    key: string;
    title: string;
    summary: string;
    planned: boolean;
    group: 'capability' | 'foundation';
}

const props = defineProps<{
    guides: GuideCard[];
}>();

const groups = computed(() => [
    { id: 'capability', label: 'Capabilities', guides: props.guides.filter((guide) => guide.group === 'capability') },
    { id: 'foundation', label: 'Foundations', guides: props.guides.filter((guide) => guide.group === 'foundation') },
]);
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Guides</h1>
    <p class="mt-2 text-[15px] text-muted">How each part of the lab works, how to change it, and what to check when it breaks.</p>

    <section v-for="group in groups" :key="group.id" :aria-labelledby="`guides-${group.id}`" class="mt-6">
        <h2 :id="`guides-${group.id}`" class="m-0 text-[18px]">{{ group.label }}</h2>
        <ul class="mt-3 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
            <li v-for="guide in group.guides" :key="guide.key" class="min-w-0 rounded-sm border border-rule p-4">
                <h3 class="m-0 flex flex-wrap items-center gap-2 text-[16px] font-semibold">
                    <Link :href="`/ai/guides/${guide.key}`" class="text-ink underline">{{ guide.title }}</Link>
                    <LabTag v-if="guide.planned">planned</LabTag>
                </h3>
                <p class="mb-0 mt-2 text-[15px]">{{ guide.summary }}</p>
            </li>
        </ul>
    </section>
</template>
