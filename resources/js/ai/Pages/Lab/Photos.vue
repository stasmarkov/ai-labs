<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    summary: string;
    guideHref: string;
}>();
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Photos</h1>
    <p class="mt-3 max-w-[65ch] text-[15px]">Coming soon. {{ summary }}</p>
    <p class="mt-4 text-[15px]">
        <Link :href="guideHref" class="font-semibold text-ink underline">Read the plan</Link>
    </p>
</template>
