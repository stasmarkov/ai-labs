<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'primary' | 'secondary';
    type?: 'button' | 'submit';
    disabled?: boolean;
}>(), {
    variant: 'primary',
    type: 'button',
    disabled: false,
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex min-h-10 items-center justify-center rounded-sm border px-4 text-[15px] font-semibold disabled:cursor-not-allowed disabled:opacity-50"
        :class="variant === 'primary'
            ? 'border-signal bg-signal text-white'
            : 'border-ink bg-transparent text-ink'"
    >
        <slot />
    </button>
</template>
