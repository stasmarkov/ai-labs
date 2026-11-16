<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabTeach from '../../components/LabTeach.vue';
import type { Teach } from '../../components/LabTeach.vue';
import LabTag from '../../components/LabTag.vue';
import { diffLines } from '../../composables/useLineDiff';

interface Version {
    id: number;
    version: number;
    system_prompt: string;
    note: string | null;
    temperature: number;
    max_tokens: number;
    created_at: string | null;
}

interface PromptSummary {
    id: number;
    name: string;
    latest_version: number;
    versions_count: number;
    updated_at: string | null;
}

interface PromptDetail {
    id: number;
    name: string;
    versions: Version[];
}

interface Result {
    position: number;
    provider: string;
    model: string;
    status: 'ok' | 'failed' | 'timeout';
    output: string | null;
    error: string | null;
    tokens_in: number | null;
    tokens_out: number | null;
    ttft_ms: number | null;
    duration_ms: number;
    ai_run_invocation_id: string | null;
}

interface Run {
    id: string;
    prompt_version_id: number | null;
    version_label: string;
    system_prompt: string;
    input: string;
    input_flags: string[];
    temperature: number;
    max_tokens: number;
    created_at: string | null;
    results: Result[];
}

interface RunSummary {
    id: string;
    version_label: string;
    input: string;
    models: string[];
    created_at: string | null;
}

interface Target {
    provider: string;
    model: string;
}

interface ProviderModels {
    name: string;
    cloud: boolean;
    models: string[];
}

interface ApiBody {
    message?: string;
    errors?: Record<string, string[]>;
    code?: string;
}

const EXAMPLES = [
    { label: 'Summarise a listing', text: '2019 BMW 320d, 84,000 km, one owner, full service history, minor scratch on the rear bumper.' },
    { label: 'Rewrite a complaint', text: 'The seller said the car had no accidents but the report shows a 2021 front collision.' },
    { label: 'Extract the facts', text: 'Selling my 2016 Golf, petrol, automatic, 112k km, new tyres, asking 9,400 EUR in Leipzig.' },
];

const props = defineProps<{
    teach: Teach;
    run: Run | null;
    recent: RunSummary[];
    prompts: PromptSummary[];
    providers: ProviderModels[];
    defaultTargets: Target[];
    localOnlyCode: string;
    idPlaceholder: string;
    urls: { prompts: string; prompt: string; versions: string; runs: string; run: string; page: string; log: string };
    limits: { input: number; systemPrompt: number; maxTokens: { min: number; max: number }; temperature: number; targets: { min: number; max: number } };
}>();

const promptList = ref<PromptSummary[]>(props.prompts);
const history = ref<RunSummary[]>(props.recent);
const selected = ref<PromptDetail | null>(null);
const selectedVersionId = ref<number | null>(null);
const name = ref('');
const systemPrompt = ref(props.run?.system_prompt ?? '');
const note = ref('');
const temperature = ref(props.run?.temperature ?? 0.2);
const maxTokens = ref(props.run?.max_tokens ?? 512);
const input = ref(props.run?.input ?? '');
const targets = ref<Target[]>(props.defaultTargets.map((target) => ({ ...target })));
const shown = ref<Run | null>(props.run);
const message = ref<string | null>(null);
const busy = ref(false);
const diffFrom = ref<number | null>(null);
const diffTo = ref<number | null>(null);

const selectedVersion = computed(() => selected.value?.versions.find((version) => version.id === selectedVersionId.value) ?? null);
const editorMatchesVersion = computed(() => {
    const version = selectedVersion.value;

    return version !== null
        && version.system_prompt === systemPrompt.value.trim().replace(/\r\n?/g, '\n')
        && version.temperature === Number(temperature.value)
        && version.max_tokens === Number(maxTokens.value);
});
const runSource = computed(() => {
    if (editorMatchesVersion.value && selected.value !== null && selectedVersion.value !== null) {
        return `Runs version ${selectedVersion.value.version} of ${selected.value.name}.`;
    }

    return 'Runs the text in the editor without saving it.';
});
const targetsValid = computed(() => targets.value.length >= props.limits.targets.min && targets.value.every((target) => target.model !== ''));
const inputLength = computed(() => [...input.value.trim()].length);
const canRun = computed(() => !busy.value && targetsValid.value && inputLength.value > 0 && inputLength.value <= props.limits.input && systemPrompt.value.trim() !== '');
const canSaveVersion = computed(() => !busy.value && systemPrompt.value.trim() !== '');
const diff = computed(() => {
    const versions = selected.value?.versions ?? [];
    const from = versions.find((version) => version.id === diffFrom.value);
    const to = versions.find((version) => version.id === diffTo.value);

    return from && to ? { from, to, lines: diffLines(from.system_prompt, to.system_prompt) } : null;
});

function urlFor(template: string, id: string | number): string {
    return template.replace(props.idPlaceholder, encodeURIComponent(String(id)));
}

function modelsOf(provider: string): string[] {
    return props.providers.find((item) => item.name === provider)?.models ?? [];
}

function changeProvider(target: Target): void {
    target.model = modelsOf(target.provider)[0] ?? '';
}

function addTarget(): void {
    const used = new Set(targets.value.map((target) => `${target.provider}:${target.model}`));

    for (const provider of props.providers) {
        const model = provider.models.find((candidate) => !used.has(`${provider.name}:${candidate}`));

        if (model !== undefined && targets.value.length < props.limits.targets.max) {
            targets.value.push({ provider: provider.name, model });

            return;
        }
    }
}

function removeTarget(index: number): void {
    if (targets.value.length > props.limits.targets.min) {
        targets.value.splice(index, 1);
    }
}

async function send(url: string, method: string, body?: unknown): Promise<{ ok: boolean; status: number; json: unknown }> {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    return { ok: response.ok, status: response.status, json: await response.json().catch(() => null) };
}

function failure(status: number, json: unknown): string {
    const body = (json ?? {}) as ApiBody;

    if (status === 429) {
        return 'Too many runs, try again in a minute.';
    }

    if (status === 404) {
        return body.code === props.localOnlyCode ? 'The playground is available on a local machine only.' : 'Not found.';
    }

    return Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? `The server answered ${status}.`;
}

async function refreshList(): Promise<void> {
    const result = await send(props.urls.prompts, 'GET');

    if (result.ok) {
        promptList.value = result.json as PromptSummary[];
    }
}

async function refreshHistory(): Promise<void> {
    const result = await send(props.urls.runs, 'GET');

    if (result.ok) {
        history.value = result.json as RunSummary[];
    }
}

function showVersion(version: Version): void {
    selectedVersionId.value = version.id;
    systemPrompt.value = version.system_prompt;
    temperature.value = version.temperature;
    maxTokens.value = version.max_tokens;
    note.value = version.note ?? '';
}

function openDetail(detail: PromptDetail): void {
    selected.value = detail;
    diffTo.value = detail.versions[0]?.id ?? null;
    diffFrom.value = detail.versions[1]?.id ?? detail.versions[0]?.id ?? null;

    if (detail.versions[0]) {
        showVersion(detail.versions[0]);
    }
}

async function selectPrompt(id: number): Promise<void> {
    message.value = null;
    const result = await send(urlFor(props.urls.prompt, id), 'GET');

    if (result.ok) {
        openDetail(result.json as PromptDetail);
    } else {
        message.value = failure(result.status, result.json);
    }
}

function newPrompt(): void {
    selected.value = null;
    selectedVersionId.value = null;
    name.value = '';
    note.value = '';
    message.value = null;
}

function versionPayload(): Record<string, unknown> {
    return { system_prompt: systemPrompt.value, note: note.value.trim() === '' ? null : note.value.trim(), temperature: Number(temperature.value), max_tokens: Number(maxTokens.value) };
}

async function save(): Promise<void> {
    if (!canSaveVersion.value) {
        return;
    }

    busy.value = true;
    message.value = null;

    try {
        const creating = selected.value === null;
        const result = creating
            ? await send(props.urls.prompts, 'POST', { name: name.value, ...versionPayload() })
            : await send(urlFor(props.urls.versions, selected.value?.id ?? 0), 'POST', versionPayload());

        if (!result.ok) {
            message.value = (result.json as ApiBody | null)?.code === 'unchanged' ? 'Nothing changed since the latest version.' : failure(result.status, result.json);

            return;
        }

        await selectPrompt(creating ? (result.json as PromptDetail).id : (selected.value?.id ?? 0));
        await refreshList();
        message.value = creating ? 'Saved as version 1.' : `Saved as version ${(result.json as Version).version}.`;
    } catch {
        message.value = 'Connection lost.';
    } finally {
        busy.value = false;
    }
}

async function remove(): Promise<void> {
    if (selected.value === null || !window.confirm(`Delete ${selected.value.name} and its versions? Its runs stay in the history.`)) {
        return;
    }

    busy.value = true;
    const result = await send(urlFor(props.urls.prompt, selected.value.id), 'DELETE').catch(() => null);
    busy.value = false;

    if (result === null || !result.ok) {
        message.value = result === null ? 'Connection lost.' : failure(result.status, result.json);

        return;
    }

    newPrompt();
    await refreshList();
}

async function run(): Promise<void> {
    if (!canRun.value) {
        return;
    }

    busy.value = true;
    message.value = null;

    const source = editorMatchesVersion.value && selectedVersion.value !== null
        ? { prompt_version_id: selectedVersion.value.id }
        : { system_prompt: systemPrompt.value, temperature: Number(temperature.value), max_tokens: Number(maxTokens.value) };

    try {
        const result = await send(props.urls.runs, 'POST', { ...source, input: input.value, targets: targets.value });

        if (result.ok) {
            shown.value = result.json as Run;
            window.history.replaceState(window.history.state, '', urlFor(props.urls.page, shown.value.id));
            await refreshHistory();
        } else {
            message.value = failure(result.status, result.json);
        }
    } catch {
        message.value = 'Connection lost.';
    } finally {
        busy.value = false;
    }
}

async function openRun(id: string): Promise<void> {
    message.value = null;
    const result = await send(urlFor(props.urls.run, id), 'GET');

    if (result.ok) {
        shown.value = result.json as Run;
        window.history.replaceState(window.history.state, '', urlFor(props.urls.page, id));
    } else {
        message.value = failure(result.status, result.json);
    }
}

function versionLabel(version: Version): string {
    return `v${version.version}${version.note ? ` — ${version.note}` : ''}`;
}

const DIFF_MARKS = { same: '  ', added: '+ ', removed: '- ' } as const;
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Prompt playground</h1>
    <p class="mt-2 text-[15px] text-muted">
        Write a system prompt, save it as a numbered version that never changes, and run one input against two or three models side by side.
    </p>

    <div class="mt-6 grid gap-8 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <section aria-labelledby="playground-prompts-title" class="min-w-0">
            <div class="flex items-center justify-between gap-2">
                <h2 id="playground-prompts-title" class="m-0 text-[18px]">Prompts</h2>
                <LabButton variant="secondary" @click="newPrompt">New prompt</LabButton>
            </div>
            <p v-if="promptList.length === 0" class="mt-2 text-[15px] text-muted">No prompt yet.</p>
            <ul class="m-0 mt-3 flex list-none flex-col gap-1 p-0">
                <li v-for="prompt in promptList" :key="prompt.id">
                    <button
                        type="button"
                        class="min-h-10 w-full min-w-0 rounded-sm border px-3 text-left text-[15px] text-ink"
                        :class="selected?.id === prompt.id ? 'border-ink font-semibold' : 'border-rule'"
                        :aria-current="selected?.id === prompt.id ? 'true' : undefined"
                        @click="selectPrompt(prompt.id)"
                    >
                        <span class="block truncate">{{ prompt.name }}</span>
                        <span class="block text-[13px] text-muted">v{{ prompt.latest_version }} · {{ prompt.versions_count }} saved</span>
                    </button>
                </li>
            </ul>
        </section>

        <div class="min-w-0">
            <form class="flex flex-col gap-4" @submit.prevent="run">
                <div v-if="selected === null" class="flex flex-col gap-1">
                    <label for="playground-name" class="text-[13px] font-medium text-muted">Prompt name</label>
                    <input id="playground-name" v-model="name" type="text" maxlength="80" class="min-h-10 w-full min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink" placeholder="e.g. listing summary" />
                </div>
                <h2 v-else-if="selected" class="m-0 text-[18px]">{{ selected.name }}</h2>

                <div v-if="selected" class="flex flex-col gap-1">
                    <label for="playground-version" class="text-[13px] font-medium text-muted">Version</label>
                    <select
                        id="playground-version"
                        aria-describedby="playground-version-hint"
                        class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink"
                        :value="selectedVersionId"
                        @change="(event) => { const version = selected?.versions.find((item) => item.id === Number((event.target as HTMLSelectElement).value)); if (version) showVersion(version); }"
                    >
                        <option v-for="version in selected.versions" :key="version.id" :value="version.id">{{ versionLabel(version) }}</option>
                    </select>
                    <p id="playground-version-hint" class="m-0 text-[13px] text-muted">A saved version never changes; pick one to run it again or to compare it.</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="playground-system" class="text-[13px] font-medium text-muted">System prompt</label>
                    <textarea id="playground-system" v-model="systemPrompt" rows="6" :maxlength="limits.systemPrompt * 2" placeholder="e.g. You write one-sentence summaries of used-car listings. Reply with the summary only." class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 font-mono text-[13px] text-ink" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <label for="playground-temperature" class="text-[13px] font-medium text-muted">Temperature</label>
                        <input id="playground-temperature" v-model.number="temperature" type="number" min="0" :max="limits.temperature" step="0.01" placeholder="e.g. 0" class="min-h-10 min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink" />
                        <p class="m-0 text-[13px] text-muted">0 gives the fairest comparison.</p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="playground-max-tokens" class="text-[13px] font-medium text-muted">Max tokens</label>
                        <input id="playground-max-tokens" v-model.number="maxTokens" type="number" :min="limits.maxTokens.min" :max="limits.maxTokens.max" step="16" placeholder="e.g. 256" class="min-h-10 min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="playground-note" class="text-[13px] font-medium text-muted">Note</label>
                        <input id="playground-note" v-model="note" type="text" maxlength="200" placeholder="e.g. shorter, no adjectives" class="min-h-10 min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <LabButton variant="secondary" :disabled="!canSaveVersion || (selected === null && name.trim() === '')" @click="save">
                        {{ selected === null ? 'Save as version 1' : 'Save as new version' }}
                    </LabButton>
                    <LabButton v-if="selected" variant="secondary" :disabled="busy" @click="remove">Delete prompt</LabButton>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="playground-input" class="text-[13px] font-medium text-muted">Input</label>
                    <textarea id="playground-input" v-model="input" rows="3" :maxlength="limits.input * 2" placeholder="e.g. 2019 BMW 320d, 84,000 km, one owner, full service history." class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink" />
                    <p class="m-0 text-[13px] text-muted">{{ inputLength }} of {{ limits.input }} characters.</p>
                    <div role="group" aria-label="Example inputs" class="flex flex-wrap gap-2">
                        <button
                            v-for="example in EXAMPLES"
                            :key="example.label"
                            type="button"
                            data-example
                            class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink"
                            @click="input = example.text"
                        >
                            {{ example.label }}
                        </button>
                    </div>
                </div>

                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="mb-1 text-[13px] font-medium text-muted">Targets ({{ limits.targets.min }} to {{ limits.targets.max }})</legend>
                    <p id="playground-targets-hint" class="m-0 text-[13px] text-muted">Pick a provider and a model for each target; the same input runs against every target.</p>
                    <div v-for="(target, index) in targets" :key="index" class="flex flex-wrap items-end gap-2">
                        <div class="flex min-w-0 flex-col gap-1">
                            <label :for="`playground-provider-${index}`" class="text-[13px] text-muted">Provider {{ index + 1 }}</label>
                            <select :id="`playground-provider-${index}`" v-model="target.provider" aria-describedby="playground-targets-hint" class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink" @change="changeProvider(target)">
                                <option v-for="provider in providers" :key="provider.name" :value="provider.name">{{ provider.name }}</option>
                            </select>
                        </div>
                        <div class="flex min-w-0 flex-col gap-1">
                            <label :for="`playground-model-${index}`" class="text-[13px] text-muted">Model {{ index + 1 }}</label>
                            <select :id="`playground-model-${index}`" v-model="target.model" aria-describedby="playground-targets-hint" class="min-h-10 min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink">
                                <option v-for="model in modelsOf(target.provider)" :key="model" :value="model">{{ model }}</option>
                            </select>
                        </div>
                        <LabButton variant="secondary" :disabled="targets.length <= limits.targets.min" @click="removeTarget(index)">Remove</LabButton>
                    </div>
                    <div>
                        <LabButton variant="secondary" :disabled="targets.length >= limits.targets.max" @click="addTarget">Add target</LabButton>
                    </div>
                    <p v-if="providers.length === 0" role="alert" class="m-0 text-[15px] text-danger">No text provider is enabled.</p>
                </fieldset>

                <div class="flex flex-wrap items-center gap-3">
                    <LabButton type="submit" :disabled="!canRun">{{ busy ? 'Working…' : 'Run' }}</LabButton>
                    <span class="text-[13px] text-muted">{{ runSource }} The targets run one after another.</span>
                </div>
            </form>

            <p v-if="message" role="alert" class="mt-3 text-[15px] font-medium text-danger">{{ message }}</p>

            <section v-if="selected && selected.versions.length > 1" class="mt-8 border-t border-rule pt-6" aria-labelledby="playground-diff-title">
                <h2 id="playground-diff-title" class="m-0 text-[18px]">Diff between versions</h2>
                <p id="playground-diff-hint" class="m-0 mt-1 text-[13px] text-muted">Choose two saved versions to see which lines changed.</p>
                <div class="mt-3 flex flex-wrap gap-4">
                    <div class="flex flex-col gap-1">
                        <label for="playground-diff-from" class="text-[13px] text-muted">From</label>
                        <select id="playground-diff-from" v-model="diffFrom" aria-describedby="playground-diff-hint" class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink">
                            <option v-for="version in selected.versions" :key="version.id" :value="version.id">{{ versionLabel(version) }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="playground-diff-to" class="text-[13px] text-muted">To</label>
                        <select id="playground-diff-to" v-model="diffTo" aria-describedby="playground-diff-hint" class="min-h-10 rounded-sm border border-field bg-white px-3 text-[15px] text-ink">
                            <option v-for="version in selected.versions" :key="version.id" :value="version.id">{{ versionLabel(version) }}</option>
                        </select>
                    </div>
                </div>
                <template v-if="diff">
                    <p class="mt-2 text-[13px] text-muted">
                        Temperature {{ diff.from.temperature }} → {{ diff.to.temperature }} · max tokens {{ diff.from.max_tokens }} → {{ diff.to.max_tokens }}
                    </p>
                    <pre class="m-0 mt-2 overflow-x-auto whitespace-pre-wrap break-words font-mono text-[13px]" aria-label="Line diff"><template v-for="(line, index) in diff.lines" :key="index"><span :class="line.kind === 'added' ? 'font-semibold text-signal' : line.kind === 'removed' ? 'text-danger line-through' : ''">{{ DIFF_MARKS[line.kind] }}{{ line.text }}</span>
</template></pre>
                </template>
            </section>

            <section v-if="shown" class="mt-8 border-t border-rule pt-6" aria-labelledby="playground-run-title">
                <h2 id="playground-run-title" class="m-0 text-[18px]">Comparison</h2>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <LabTag caption="prompt">{{ shown.version_label }}</LabTag>
                    <LabTag caption="temperature">{{ shown.temperature }}</LabTag>
                    <LabTag caption="max tokens">{{ shown.max_tokens }}</LabTag>
                </div>
                <p v-for="flag in shown.input_flags" :key="flag" role="status" class="m-0 mt-2 text-[15px] font-medium text-danger">Input matches an injection pattern: {{ flag }}</p>
                <p class="mt-2 whitespace-pre-wrap break-words text-[15px]"><span class="text-muted">Input: </span>{{ shown.input }}</p>

                <div class="mt-4 grid gap-4" :class="shown.results.length === 3 ? 'lg:grid-cols-3' : 'md:grid-cols-2'">
                    <article v-for="result in shown.results" :key="result.position" class="min-w-0 border border-rule p-3" :aria-label="`${result.provider} ${result.model}`">
                        <h3 class="m-0 break-words font-mono text-[15px] font-semibold">{{ result.provider }} / {{ result.model }}</h3>
                        <div class="mt-1"><LabTag caption="status">{{ result.status }}</LabTag></div>
                        <p v-if="result.error" role="alert" class="m-0 mt-2 break-words text-[13px] text-danger">{{ result.error }}</p>
                        <p v-if="result.output !== null" class="m-0 mt-2 whitespace-pre-wrap break-words text-[15px]">{{ result.output }}</p>
                        <dl class="m-0 mt-3 grid grid-cols-2 gap-3">
                            <LabMetric label="Tokens in" :value="result.tokens_in" />
                            <LabMetric label="Tokens out" :value="result.tokens_out" />
                            <LabMetric label="First token" :value="result.ttft_ms" unit="ms" />
                            <LabMetric label="Total" :value="result.duration_ms" unit="ms" />
                        </dl>
                        <a v-if="result.ai_run_invocation_id" :href="urlFor(urls.log, result.ai_run_invocation_id)" class="mt-2 inline-block text-[13px] text-ink underline">Run log</a>
                    </article>
                </div>
            </section>

            <section class="mt-8 border-t border-rule pt-6" aria-labelledby="playground-history-title">
                <h2 id="playground-history-title" class="m-0 text-[18px]">History</h2>
                <p v-if="history.length === 0" class="mt-2 text-[15px] text-muted">No run yet.</p>
                <ul class="m-0 mt-3 flex list-none flex-col gap-2 p-0">
                    <li v-for="item in history" :key="item.id" class="flex min-w-0 flex-wrap items-center gap-2">
                        <button type="button" class="min-h-10 min-w-0 break-words text-left text-[15px] text-ink underline" @click="openRun(item.id)">{{ item.input }}</button>
                        <LabTag>{{ item.version_label }}</LabTag>
                        <span class="font-mono text-[13px] text-muted">{{ item.models.join(' · ') }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <LabTeach :teach="teach" />
</template>
