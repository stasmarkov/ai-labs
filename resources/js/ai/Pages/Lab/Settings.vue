<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import LabSettingRow from '../../components/LabSettingRow.vue';
import type { SettingRow } from '../../components/LabSettingRow.vue';

const props = defineProps<{
    rows: SettingRow[];
    flash: { feature: string; message: string } | null;
    saveUrl: string;
    resetUrl: string;
    featurePlaceholder: string;
}>();

function urlFor(template: string, feature: string): string {
    return template.replace(props.featurePlaceholder, feature);
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Settings</h1>
    <p class="mt-2 text-[15px] text-muted">
        Pick the provider and model of each feature. A choice made on a lab page wins for that request; a saved choice
        wins over configuration; reset returns the feature to configuration.
    </p>

    <ul class="mt-6 grid list-none grid-cols-1 gap-4 p-0">
        <li v-for="row in rows" :key="row.feature" class="min-w-0">
            <LabSettingRow
                :row="row"
                :save-url="urlFor(saveUrl, row.feature)"
                :reset-url="urlFor(resetUrl, row.feature)"
                :message="flash?.feature === row.feature ? flash.message : null"
            />
        </li>
    </ul>
</template>
