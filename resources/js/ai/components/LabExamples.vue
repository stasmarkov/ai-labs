<script setup lang="ts">
defineProps<{
    examples: string[];
    label: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    pick: [example: string];
}>();
</script>

<template>
    <div role="group" :aria-label="label" class="flex flex-wrap items-center gap-2">
        <span class="text-[13px] text-muted" aria-hidden="true">Try:</span>
        <button
            v-for="example in examples"
            :key="example"
            type="button"
            data-example
            :disabled="disabled"
            class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50"
            @click="emit('pick', example)"
        >
            {{ example }}
        </button>
    </div>
</template>
