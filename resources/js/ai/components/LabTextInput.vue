<script setup lang="ts">
defineProps<{
    label: string;
    id: string;
    placeholder?: string;
    type?: 'text' | 'search' | 'number';
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <label :for="id" class="text-[13px] font-medium text-muted">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            :type="type ?? 'text'"
            :placeholder="placeholder"
            class="min-h-10 w-full min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink placeholder:text-muted"
        >
    </div>
</template>
