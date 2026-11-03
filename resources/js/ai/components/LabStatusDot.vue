<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    state: 'ok' | 'down' | 'idle';
    label: string;
}>();

const colour = computed(() => ({
    ok: 'bg-success',
    down: 'bg-danger',
    idle: 'bg-muted',
})[props.state]);
</script>

<template>
    <span class="inline-flex items-center gap-2 text-[13px]">
        <span class="size-2 rounded-full" :class="colour" aria-hidden="true" />
        <span>{{ label }}</span>
    </span>
</template>
