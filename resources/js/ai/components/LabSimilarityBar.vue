<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: number;
}>();

const clamped = computed(() => Math.min(1, Math.max(0, props.value)));
</script>

<template>
    <div class="flex min-w-0 items-center gap-3">
        <div
            class="h-1 min-w-0 flex-1 bg-rule"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="1"
            :aria-valuenow="clamped"
            :aria-label="`Similarity ${clamped.toFixed(4)}`"
        >
            <div class="h-1 bg-ink" :style="{ width: `${clamped * 100}%` }" />
        </div>
        <span class="font-mono text-[13px] tabular-nums">{{ clamped.toFixed(4) }}</span>
    </div>
</template>
