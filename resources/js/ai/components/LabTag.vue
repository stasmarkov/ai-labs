<script setup lang="ts">
defineProps<{
    caption?: string;
}>();
</script>

<template>
    <span class="inline-flex max-w-full items-center gap-1 rounded-sm border border-rule px-2 py-0.5 font-mono text-[13px]">
        <span v-if="caption" class="text-muted">{{ caption }}</span>
        <span class="truncate"><slot /></span>
    </span>
</template>
