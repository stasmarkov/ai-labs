<script setup lang="ts">
/** An example is the text to fill in, or a short label shown on the chip with the longer text it fills in. */
export type LabExample = string | { label: string; text: string };

defineProps<{
    examples: LabExample[];
    label: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    pick: [example: string];
}>();

function chipLabel(example: LabExample): string {
    return typeof example === 'string' ? example : example.label;
}

function chipText(example: LabExample): string {
    return typeof example === 'string' ? example : example.text;
}
</script>

<template>
    <div role="group" :aria-label="label" class="flex flex-wrap items-center gap-2">
        <span class="text-[13px] text-muted" aria-hidden="true">Try:</span>
        <button
            v-for="example in examples"
            :key="chipLabel(example)"
            type="button"
            data-example
            :disabled="disabled"
            class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50"
            @click="emit('pick', chipText(example))"
        >
            {{ chipLabel(example) }}
        </button>
    </div>
</template>
