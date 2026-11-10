<script setup lang="ts">
import LabCode from './LabCode.vue';

defineProps<{
    title: string;
    html: string;
    snippet: { file: string; startLine: number; endLine: number; code: string } | null;
}>();
</script>

<template>
    <article class="flex min-w-0 flex-col gap-3 border-b border-rule pb-5">
        <h3 class="m-0 text-[16px] font-semibold">{{ title }}</h3>
        <div class="lab-prose" v-html="html" />
        <LabCode v-if="snippet" v-bind="snippet" />
    </article>
</template>
