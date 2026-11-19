<script setup lang="ts">
import type { KpiValue } from '../../composables/lessonTypes';

defineProps<{ kpis: KpiValue[] }>();

function trendClass(trend: string): string {
    if (trend === 'better') {
        return 'text-success';
    }

    return trend === 'worse' ? 'text-danger' : 'text-ink';
}
</script>

<template>
    <dl class="m-0 grid grid-cols-2 gap-px overflow-hidden rounded-sm border border-rule bg-rule sm:grid-cols-4" aria-label="Measured numbers for this step">
        <div v-for="kpi in kpis" :key="kpi.id" class="min-w-0 bg-paper px-3 py-2">
            <dt class="text-[13px] text-muted">{{ kpi.label }}</dt>
            <dd class="m-0 truncate font-mono text-[18px] font-semibold" :class="trendClass(kpi.trend)">
                {{ kpi.value }}
                <span v-if="kpi.trend === 'better'" class="sr-only">(better than the previous step)</span>
                <span v-else-if="kpi.trend === 'worse'" class="sr-only">(worse than the previous step)</span>
            </dd>
        </div>
    </dl>
</template>
