<script setup lang="ts">
defineProps<{
    type: string;
    atMs: number;
    detail?: string;
}>();
</script>

<template>
    <li class="lab-fade-in flex min-w-0 items-baseline gap-3 border-b border-rule py-1 font-mono text-[13px]">
        <span class="w-16 shrink-0 text-right tabular-nums text-muted">{{ atMs }} ms</span>
        <span class="shrink-0 font-medium text-ink">{{ type }}</span>
        <span v-if="detail" class="min-w-0 truncate text-muted">{{ detail }}</span>
    </li>
</template>
