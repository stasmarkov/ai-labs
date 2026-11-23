<script lang="ts">
export const FALLBACK_REASONS: Record<string, string> = {
    unreachable: 'the main model server was unreachable',
    model_missing: 'the main model is missing',
    server_error: 'the main model server failed',
    timeout: 'the main model timed out',
    busy: 'the main model was busy',
};

export function fallbackReasonLabel(reason: string | null): string {
    return reason === null ? 'an error' : (FALLBACK_REASONS[reason] ?? reason);
}
</script>

<script setup lang="ts">
defineProps<{
    model: string;
    fromModel: string | null;
    reason: string | null;
}>();
</script>

<template>
    <p v-if="fromModel" class="m-0 mt-3 text-[15px]" data-testid="fallback-note">
        Answered by the fallback model <span class="font-mono">{{ model }}</span> instead of
        <span class="font-mono">{{ fromModel }}</span>: {{ fallbackReasonLabel(reason) }}.
    </p>
</template>
