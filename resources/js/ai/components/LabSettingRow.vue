<script setup lang="ts">
import { computed, nextTick, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import LabButton from './LabButton.vue';
import LabTag from './LabTag.vue';
import LabTextInput from './LabTextInput.vue';

export interface SettingProvider {
    name: string;
    models: string[];
    free: boolean;
    note: string | null;
}

export interface SettingIndex {
    table: string;
    command: string;
    total: number;
    indexed: number;
    current: number;
    foreign: number;
    warning: boolean;
}

export interface SettingRow {
    feature: string;
    kind: 'text' | 'embeddings';
    effective: {
        provider: string;
        model: string;
        source: string;
        modelSource: string;
        installed: boolean | null;
        pullCommand: string | null;
    };
    saved: { provider: string; model: string } | null;
    savedProviderEnabled: boolean | null;
    providers: SettingProvider[];
    index: SettingIndex | null;
}

const OTHER = '__other__';

const props = defineProps<{
    row: SettingRow;
    saveUrl: string;
    resetUrl: string;
    message: string | null;
}>();

function initialModel(): { model: string; other: string } {
    const provider = props.row.providers.find((entry) => entry.name === props.row.effective.provider);
    const listed = provider?.models.includes(props.row.effective.model) ?? false;

    return listed || !provider?.free ? { model: props.row.effective.model, other: '' } : { model: OTHER, other: props.row.effective.model };
}

const form = useForm({
    provider: props.row.effective.provider,
    ...initialModel(),
    reindex_acknowledged: false,
});

const bag = computed(() => `settings-${props.row.feature}`);
const provider = computed(() => props.row.providers.find((entry) => entry.name === form.provider));
const models = computed(() => provider.value?.models ?? []);
const idPrefix = computed(() => `setting-${props.row.feature}`);
const selectedModel = computed(() => (form.model === OTHER ? form.other : form.model));
const needsAcknowledgement = computed(
    () => props.row.kind === 'embeddings'
        && (form.provider !== props.row.effective.provider || selectedModel.value !== props.row.effective.model),
);
const MODEL_SOURCE_LABELS: Record<string, string> = {
    request: 'request',
    settings: 'saved',
    configuration: 'configuration',
    default: 'provider default',
};
const modelSourceLabel = computed(() =>
    props.row.effective.modelSource === props.row.effective.source
        ? null
        : (MODEL_SOURCE_LABELS[props.row.effective.modelSource] ?? props.row.effective.modelSource),
);

let syncing = false;

/**
 * Puts the selects back on what the feature now uses. The form outlives a save or reset (Inertia keeps page state),
 * so without this the selects would keep the old choice and an embeddings row would ask for a re-index on its own.
 */
function syncFromEffective(): void {
    syncing = true;
    form.provider = props.row.effective.provider;
    Object.assign(form, initialModel());
    form.reindex_acknowledged = false;
    form.defaults();
    void nextTick(() => {
        syncing = false;
    });
}

watch(() => form.provider, () => {
    if (syncing) {
        return;
    }

    form.model = models.value[0] ?? (provider.value?.free ? OTHER : '');
    form.other = '';
    form.clearErrors();
});

watch(() => [props.row.effective.provider, props.row.effective.model], syncFromEffective);

function save(): void {
    form
        .transform((data) => ({
            provider: data.provider,
            model: data.model === OTHER ? data.other : data.model,
            reindex_acknowledged: data.reindex_acknowledged,
        }))
        .put(props.saveUrl, { errorBag: bag.value, preserveScroll: true, onSuccess: syncFromEffective });
}

function reset(): void {
    form.delete(props.resetUrl, { errorBag: bag.value, preserveScroll: true, onSuccess: syncFromEffective });
}

const errors = computed(() => Object.values(form.errors).filter((error): error is string => typeof error === 'string' && error !== ''));
</script>

<template>
    <section :aria-labelledby="`${idPrefix}-title`" class="min-w-0 rounded-sm border border-rule p-4">
        <h2 :id="`${idPrefix}-title`" class="m-0 flex flex-wrap items-center gap-2 text-[18px] font-semibold">
            <span class="font-mono text-[16px]">{{ row.feature }}</span>
            <LabTag caption="source">{{ row.effective.source }}</LabTag>
        </h2>
        <p class="mb-0 mt-2 break-words text-[15px]">
            Using <span class="font-mono">{{ row.effective.provider }}</span> / <span class="font-mono">{{ row.effective.model }}</span>
            <span v-if="modelSourceLabel" class="text-muted" data-testid="model-source"> ({{ modelSourceLabel }})</span>
        </p>
        <p v-if="row.effective.installed === false" role="status" class="mb-0 mt-2 text-[15px] font-medium text-danger">
            Not installed. Pull it with <code class="break-all font-mono text-[13px]">{{ row.effective.pullCommand }}</code>
        </p>
        <p v-if="row.savedProviderEnabled === false && row.saved" class="mb-0 mt-2 text-[15px] text-muted">
            The saved provider {{ row.saved.provider }} is not enabled, so the saved choice is ignored.
        </p>

        <form class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="save">
            <div class="flex min-w-0 flex-col gap-1">
                <label :for="`${idPrefix}-provider`" class="text-[13px] font-medium text-muted">Provider</label>
                <select :id="`${idPrefix}-provider`" v-model="form.provider" class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink">
                    <option v-for="entry in row.providers" :key="entry.name" :value="entry.name">{{ entry.name }}</option>
                </select>
            </div>
            <div class="flex min-w-0 flex-col gap-1">
                <label :for="`${idPrefix}-model`" class="text-[13px] font-medium text-muted">Model</label>
                <select :id="`${idPrefix}-model`" v-model="form.model" class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink">
                    <option v-for="id in models" :key="id" :value="id">{{ id }}</option>
                    <option v-if="provider?.free" :value="OTHER">Other id</option>
                </select>
            </div>
            <LabTextInput
                v-if="form.model === OTHER"
                :id="`${idPrefix}-other`"
                v-model="form.other"
                label="Other model id"
                placeholder="model-id"
            />
            <p v-if="provider?.note" class="m-0 text-[13px] text-muted sm:col-span-2">{{ provider.note }}</p>

            <label v-if="needsAcknowledgement" class="flex items-start gap-2 text-[15px] sm:col-span-2">
                <input v-model="form.reindex_acknowledged" type="checkbox" class="mt-1 size-4">
                <span>I will re-index after changing the model; search answers 409 until then.</span>
            </label>
            <p v-if="needsAcknowledgement" class="m-0 text-[13px] text-muted sm:col-span-2">
                Changing the embedding model leaves the stored vectors stale until you re-index.
            </p>

            <ul v-if="errors.length" role="alert" class="m-0 list-none p-0 text-[15px] font-medium text-danger sm:col-span-2">
                <li v-for="error in errors" :key="error">{{ error }}</li>
            </ul>
            <p v-if="message" role="status" class="m-0 text-[15px] font-medium text-success sm:col-span-2">{{ message }}</p>

            <div class="flex flex-wrap gap-3 sm:col-span-2">
                <LabButton type="submit" :disabled="form.processing">Save</LabButton>
                <LabButton variant="secondary" :disabled="form.processing || row.saved === null" @click="reset">Reset to configuration</LabButton>
            </div>
        </form>

        <div v-if="row.index" class="mt-4 border-t border-rule pt-4">
            <p class="m-0 text-[15px]">
                Indexed rows in <span class="font-mono">{{ row.index.table }}</span>:
                <strong>{{ row.index.indexed }}</strong> of {{ row.index.total }};
                {{ row.index.current }} made with the effective model.
            </p>
            <div v-if="row.index.warning" role="alert" class="mt-3 rounded-sm border border-danger p-3 text-[15px]">
                <p class="m-0 font-medium text-danger">
                    {{ row.index.foreign }} vectors were made with another model, so search over them is stale.
                </p>
                <p class="mb-1 mt-2">Re-index to rebuild them:</p>
                <code class="block break-all font-mono text-[13px]">{{ row.index.command }}</code>
            </div>
            <p v-else class="mb-0 mt-2 text-[13px] text-muted">
                Re-index command after a model change: <code class="break-all font-mono">{{ row.index.command }}</code>
            </p>
        </div>
    </section>
</template>
