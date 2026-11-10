<script setup lang="ts">
export interface ProviderOption {
    name: string;
    configured: boolean;
    hasKey: boolean;
}

function optionLabel(provider: ProviderOption): string {
    return `${provider.name}${provider.configured ? ' (configured)' : ''}${provider.hasKey ? '' : ' - no key'}`;
}

defineProps<{
    id: string;
    providers: ProviderOption[];
    disabled?: boolean;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div v-if="providers.length > 1" class="flex min-w-0 flex-col gap-1">
        <label :for="id" class="text-[15px] font-medium text-ink">Provider</label>
        <select :id="id" v-model="model" :disabled="disabled" class="min-h-10 rounded-sm border border-ink bg-transparent px-3 text-[15px] text-ink">
            <option v-for="provider in providers" :key="provider.name" :value="provider.name">
                {{ optionLabel(provider) }}
            </option>
        </select>
    </div>
</template>
