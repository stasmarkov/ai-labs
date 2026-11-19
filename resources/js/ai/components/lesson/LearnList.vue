<script setup lang="ts">
defineProps<{
    outcomes: { id: string; text: string }[];
    ticked: string[];
}>();
</script>

<template>
    <section aria-labelledby="lesson-learn" class="rounded-md border border-rule bg-white p-5">
        <h2 id="lesson-learn" class="m-0 text-[16px] font-semibold">You will learn</h2>
        <ul class="mb-0 mt-2 list-none space-y-2 p-0">
            <li v-for="outcome in outcomes" :key="outcome.id" class="flex gap-2 text-[14px]">
                <span
                    class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border text-[12px] leading-none"
                    :class="ticked.includes(outcome.id) ? 'border-success bg-success text-white' : 'border-field'"
                    aria-hidden="true"
                >{{ ticked.includes(outcome.id) ? '✓' : '' }}</span>
                <span>
                    {{ outcome.text }}
                    <span class="sr-only">{{ ticked.includes(outcome.id) ? '(learned)' : '(not yet)' }}</span>
                </span>
            </li>
        </ul>
    </section>
</template>
