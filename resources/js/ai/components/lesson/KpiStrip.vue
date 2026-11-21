<script setup lang="ts">
import { computed } from 'vue';
import type { KpiValue } from '../../composables/lessonTypes';

const props = defineProps<{ kpis: KpiValue[] }>();

/**
 * Columns per row from 640 px up: up to four KPIs share one row; more are split into two rows that are as even as
 * possible (5 → 3 + 2, 6 → 3 + 3, 7 → 4 + 3). Cells grow, so a shorter last row stretches to the full width instead of
 * leaving an empty cell. Below 640 px two columns, and an odd last cell spans the row.
 */
const columns = computed(() => {
    const count = props.kpis.length;

    return count <= 4 ? Math.max(count, 1) : Math.ceil(count / 2);
});

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
    <dl
        class="m-0 flex flex-wrap gap-px overflow-hidden rounded-md border border-rule bg-rule"
        :style="{ '--kpi-basis': `calc(${100 / columns}% - 1px)` }"
        :data-columns="columns"
        aria-label="Measured numbers for this step"
    >
        <div v-for="kpi in kpis" :key="kpi.id" class="min-w-0 grow basis-[calc(50%-1px)] bg-white px-3 py-2 sm:basis-(--kpi-basis)">
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
