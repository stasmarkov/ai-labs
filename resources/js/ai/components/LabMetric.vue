<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number | null;
    unit?: string;
    live?: boolean;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <dt class="text-[13px] text-muted">{{ label }}</dt>
        <dd class="m-0 font-mono text-[18px] tabular-nums" :class="live ? 'text-signal' : 'text-ink'">
            {{ value ?? '—' }}<span v-if="unit && value !== null" class="ml-1 text-[13px] text-muted">{{ unit }}</span>
        </dd>
    </div>
</template>
