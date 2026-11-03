<script setup lang="ts">
export interface SegmentedOption {
    value: string;
    label: string;
}

defineProps<{
    label: string;
    options: SegmentedOption[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="inline-flex flex-wrap rounded-sm border border-ink">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            class="min-h-10 px-4 text-[15px] font-medium"
            :class="model === option.value ? 'bg-ink text-paper' : 'bg-transparent text-ink'"
            @click="model = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
