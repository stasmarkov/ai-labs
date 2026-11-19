<script setup lang="ts">
import type { KpiValue } from '../../composables/lessonTypes';

defineProps<{ kpis: KpiValue[] }>();

function trendClass(trend: string): string {
    if (trend === 'better') {
        return 'text-success';
    }

    return trend === 'worse' ? 'text-danger' : 'text-ink';
}

/**
 * "51.6 tokens/s" becomes a large figure and a small unit, so the value fits its cell; text without a leading number stays whole.
 */
function split(value: string): { figure: string; unit: string; numeric: boolean } {
    const match = /^([-+]?[\d.,]+)\s+(.+)$/.exec(value);

    if (match) {
        return { figure: match[1], unit: match[2], numeric: true };
    }

    return { figure: value, unit: '', numeric: /^[-+]?[\d.,]+$/.test(value) };
}
</script>

<template>
    <dl class="m-0 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-rule bg-rule sm:grid-cols-4" aria-label="Measured numbers for this step">
        <div v-for="kpi in kpis" :key="kpi.id" class="min-w-0 bg-white px-3 py-2">
            <dt class="text-[13px] leading-snug text-muted">{{ kpi.label }}</dt>
            <dd class="m-0 mt-0.5 flex flex-wrap items-baseline gap-x-1 break-words font-semibold leading-tight" :class="trendClass(kpi.trend)">
                <span :class="split(kpi.value).numeric ? 'font-mono text-[20px]' : 'text-[16px]'">{{ split(kpi.value).figure }}</span>
                <span v-if="split(kpi.value).unit" class="text-[13px]">{{ split(kpi.value).unit }}</span>
                <span v-if="kpi.trend === 'better'" class="sr-only">(better than the previous step)</span>
                <span v-else-if="kpi.trend === 'worse'" class="sr-only">(worse than the previous step)</span>
            </dd>
        </div>
    </dl>
</template>
