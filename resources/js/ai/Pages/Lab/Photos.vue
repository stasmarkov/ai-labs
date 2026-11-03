<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import LabTag from '../../components/LabTag.vue';

defineProps<{
    slices: string[];
    blockedBy: string | null;
    backlog: string;
    guide: string;
}>();
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Photos</h1>
    <p class="mt-3 max-w-[65ch] text-[15px]">Coming soon. Similar car photos: the nearest photos of other listings, found with image embeddings.</p>

    <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-3">
        <div class="flex flex-col gap-1">
            <dt class="text-[13px] text-muted">Planned in</dt>
            <dd class="m-0 flex flex-wrap gap-2">
                <LabTag v-for="slice in slices" :key="slice">{{ slice }}</LabTag>
            </dd>
        </div>
        <div v-if="blockedBy" class="flex flex-col gap-1">
            <dt class="text-[13px] text-muted">Blocked by</dt>
            <dd class="m-0"><LabTag>{{ blockedBy }}</LabTag></dd>
        </div>
        <div class="flex flex-col gap-1">
            <dt class="text-[13px] text-muted">Read</dt>
            <dd class="m-0 font-mono text-[13px]">
                <code>{{ backlog }}</code> (the slice entries) and <code>{{ guide }}</code>
            </dd>
        </div>
    </dl>
</template>
