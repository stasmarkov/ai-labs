<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabTag from '../../components/LabTag.vue';
import type { GuideStep } from '../../composables/useGuideSteps';
import { presetQuery } from '../../composables/usePresetQuery';

interface StepReport {
    position: number;
    tool: string;
    arguments: Record<string, unknown>;
    outcome: string;
    error: string | null;
    result: Record<string, unknown> | null;
    duration_ms: number;
    run_id: string;
}

interface PendingAction {
    action_id: string;
    tool: string;
    arguments: Record<string, unknown>;
    preview: Record<string, unknown>;
    expires_at: string;
}

interface Reply {
    conversation_id: string;
    answer: string;
    steps: StepReport[];
    pending_action: PendingAction | null;
    limit_reached: boolean;
    run_ids: string[];
    refused?: boolean;
    pattern?: string | null;
}

type Decision = 'confirmed' | 'rejected';

interface Entry {
    id: number;
    label: string;
    isDecision: boolean;
    loading: boolean;
    reply: Reply | null;
    error: string | null;
    decision: Decision | null;
    deciding: boolean;
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    askUrl: string;
    confirmUrl: string;
    rejectUrl: string;
    runUrl: string;
    idPlaceholder: string;
    actionPlaceholder: string;
    maxQuestionLength: number;
    maxSteps: number;
    model: { provider: string; name: string };
    examples: string[];
}>();

const question = ref(presetQuery());
const entries = ref<Entry[]>([]);
const conversationId = ref<string | null>(null);
let nextEntryId = 1;

const busy = computed(() => entries.value.some((entry) => entry.loading || entry.deciding));
const length = computed(() => [...question.value].length);
const tooLong = computed(() => length.value > props.maxQuestionLength);
const canAsk = computed(() => !busy.value && question.value.trim() !== '' && !tooLong.value);
const showExamples = computed(() => entries.value.length === 0);
const eventTypes: string[] = [];

const openEntry = computed<Entry | null>(() => {
    const proposed = entries.value.filter((entry) => entry.reply?.pending_action != null && entry.decision === null);

    return proposed.at(-1) ?? null;
});

function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

function argumentsLabel(args: Record<string, unknown>): string {
    return Object.keys(args).length > 0 ? pretty(args) : 'no arguments';
}

function runHref(runId: string): string {
    return props.runUrl.replace(props.idPlaceholder, encodeURIComponent(runId));
}

function actionUrl(template: string, actionId: string): string {
    return template.replace(props.actionPlaceholder, encodeURIComponent(actionId));
}

function outcomeClass(outcome: string): string {
    if (outcome === 'error' || outcome === 'failed') {
        return 'text-danger';
    }

    return outcome === 'ok' || outcome === 'confirmed' ? 'text-signal' : 'text-muted';
}

function titleOf(value: unknown): string {
    return typeof value === 'string' && value !== '' ? `"${value}"` : 'make and model (no title set)';
}

function isTitleChange(action: PendingAction): boolean {
    return 'new_title' in action.preview;
}

function carLabel(preview: Record<string, unknown>): string {
    return [preview.make, preview.model, preview.year].filter((part) => part !== null && part !== undefined).join(' ');
}

function currentTags(preview: Record<string, unknown>): string {
    const tags = Array.isArray(preview.tags) ? preview.tags : [];

    return tags.length > 0 ? tags.join(', ') : 'none';
}

interface Failure {
    status: number;
    message: string;
    code?: string;
}

async function post(url: string, body: Record<string, unknown>): Promise<Reply> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
        });
    } catch {
        throw { status: 0, message: 'Could not reach the server (connection lost).' } satisfies Failure;
    }

    const payload = (await response.json().catch(() => ({}))) as { data?: Reply; message?: string; errors?: Record<string, string[]>; code?: string };

    if (!response.ok || !payload.data) {
        const fields = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';

        throw { status: response.status, message: fields || payload.message || `Request failed (server error ${response.status}).`, code: payload.code } satisfies Failure;
    }

    return payload.data;
}

function forgetMissingConversation(failure: unknown): void {
    if (typeof failure === 'object' && failure !== null && (failure as Failure).status === 404 && (failure as Failure).code === 'conversation_not_found') {
        conversationId.value = null;
    }
}

function failureMessage(failure: unknown): string {
    return typeof failure === 'object' && failure !== null && 'message' in failure ? String((failure as Failure).message) : 'Request failed.';
}

function focusQuestion(): void {
    void nextTick(() => document.getElementById('admin-question')?.focus());
}

async function ask(): Promise<void> {
    if (!canAsk.value) {
        return;
    }

    const sent = question.value.trim();
    const entry = { id: nextEntryId++, label: sent, isDecision: false, loading: true, reply: null, error: null, decision: null, deciding: false } as Entry;

    entries.value.push(entry);
    const live = entries.value[entries.value.length - 1];
    question.value = '';

    try {
        const reply = await post(props.askUrl, { question: sent, ...(conversationId.value === null ? {} : { conversation_id: conversationId.value }) });

        conversationId.value = reply.conversation_id;
        live.reply = reply;
    } catch (failure) {
        forgetMissingConversation(failure);
        live.error = failureMessage(failure);
    } finally {
        live.loading = false;
    }

    focusQuestion();
}

async function decide(decision: Decision): Promise<void> {
    const decided = openEntry.value;
    const action = decided?.reply?.pending_action;

    if (!decided || !action || conversationId.value === null || busy.value) {
        return;
    }

    decided.deciding = true;
    entries.value.push({
        id: nextEntryId++,
        label: decision === 'confirmed' ? 'Confirmed the change' : 'Rejected the change',
        isDecision: true,
        loading: true,
        reply: null,
        error: null,
        decision: null,
        deciding: false,
    });
    const live = entries.value[entries.value.length - 1];

    try {
        const reply = await post(actionUrl(decision === 'confirmed' ? props.confirmUrl : props.rejectUrl, action.action_id), { conversation_id: conversationId.value });

        decided.decision = decision;
        live.reply = reply;
    } catch (failure) {
        forgetMissingConversation(failure);
        live.error = failureMessage(failure);
    } finally {
        decided.deciding = false;
        live.loading = false;
    }

    focusQuestion();
}

function useExample(example: string): void {
    question.value = example;
    focusQuestion();
}

function newChat(): void {
    entries.value = [];
    conversationId.value = null;
    question.value = '';
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="m-0 text-[28px] font-semibold">Admin assistant</h1>
        <LabTag caption="access">local only</LabTag>
        <LabTag caption="model">{{ model.name }}</LabTag>
    </div>
    <p class="mt-2 text-[15px] text-muted">
        Ask about listings or ask for a small change. The loop is written in PHP: the model asks for a tool, PHP checks the call, and a read runs at once. A write only becomes a card with the exact change, and nothing is saved until you click Confirm. A question uses at most {{ maxSteps }} tool steps.
    </p>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="eventTypes" state="idle" />

    <section v-if="showExamples" aria-labelledby="admin-empty-title" class="mt-6" data-state="empty">
        <h2 id="admin-empty-title" class="m-0 text-[18px]">Nothing asked yet</h2>
        <p class="mt-1 text-[13px] text-muted">Pick an example or write your own question below.</p>
        <ul class="m-0 mt-3 flex list-none flex-wrap gap-2 p-0" aria-label="Example questions">
            <li v-for="example in examples" :key="example">
                <button type="button" class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink" @click="useExample(example)">
                    {{ example }}
                </button>
            </li>
        </ul>
    </section>

    <ol v-if="entries.length > 0" aria-label="Conversation" class="m-0 mt-6 flex list-none flex-col gap-8 p-0">
        <li v-for="entry in entries" :key="entry.id" class="min-w-0 border-t border-rule pt-6" :data-entry="entry.id">
            <h2 class="m-0 text-[13px] font-medium text-muted">{{ entry.isDecision ? 'Your decision' : 'You' }}</h2>
            <p class="m-0 mt-1 whitespace-pre-wrap break-words text-[15px] font-medium">{{ entry.label }}</p>

            <p v-if="entry.loading" class="mt-4 text-[13px] text-muted" role="status">Waiting for the model…</p>

            <template v-if="entry.reply">
                <section v-if="entry.reply.steps.length > 0" :aria-labelledby="`admin-steps-${entry.id}`" class="mt-4" data-section="timeline">
                    <h3 :id="`admin-steps-${entry.id}`" class="m-0 text-[13px] font-medium text-muted">Tool steps</h3>
                    <ol class="m-0 mt-2 flex list-none flex-col gap-3 p-0">
                        <li
                            v-for="step in entry.reply.steps"
                            :key="step.position + step.run_id"
                            class="min-w-0 border-l-2 pl-3"
                            :class="step.outcome === 'error' || step.outcome === 'failed' ? 'border-danger' : 'border-rule'"
                            :data-step-outcome="step.outcome"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[13px] text-muted">{{ step.position }}</span>
                                <LabTag>{{ step.tool }}</LabTag>
                                <span class="text-[13px] font-semibold" :class="outcomeClass(step.outcome)">{{ step.outcome }}</span>
                                <span class="text-[13px] tabular-nums text-muted">{{ step.duration_ms }} ms</span>
                                <a :href="runHref(step.run_id)" class="text-[13px] text-signal underline">Logged run</a>
                            </div>
                            <pre class="m-0 mt-1 whitespace-pre-wrap break-words font-mono text-[13px]">{{ argumentsLabel(step.arguments) }}</pre>
                            <p v-if="step.error" class="m-0 mt-1 text-[13px] text-danger">{{ step.error }}</p>
                            <details v-if="step.result" class="mt-1 min-w-0">
                                <summary class="cursor-pointer text-[13px] text-muted">Result</summary>
                                <pre class="m-0 mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words font-mono text-[13px]">{{ pretty(step.result) }}</pre>
                            </details>
                        </li>
                    </ol>
                </section>

                <template v-if="entry.reply.answer.trim() !== ''">
                    <h3 class="m-0 mt-4 text-[13px] font-medium text-muted">Answer</h3>
                    <p class="mt-2 whitespace-pre-wrap break-words text-[15px] leading-relaxed">{{ entry.reply.answer }}</p>
                </template>

                <p v-if="entry.reply.refused" class="mt-3 text-[15px] font-medium text-danger" data-state="refused" role="alert">
                    Refused before any model call{{ entry.reply.pattern ? ` (${entry.reply.pattern})` : '' }}.
                </p>
                <p v-if="entry.reply.limit_reached" class="mt-3 text-[13px] font-semibold text-muted" data-state="limit-reached">
                    Limit reached: the question used its {{ maxSteps }} tool steps or its time, so the last answer was written without tools. Ask a narrower question.
                </p>

                <section
                    v-if="entry.reply.pending_action"
                    :aria-labelledby="`admin-card-${entry.id}`"
                    class="mt-4 rounded-sm border border-ink px-4 py-3"
                    data-section="confirmation"
                    :data-decision="entry.decision ?? 'open'"
                >
                    <h3 :id="`admin-card-${entry.id}`" class="m-0 text-[15px] font-semibold">
                        {{ entry.decision === 'confirmed' ? 'Change confirmed' : entry.decision === 'rejected' ? 'Change rejected' : 'Confirm this change' }}
                    </h3>
                    <p class="m-0 mt-1 text-[13px] text-muted">
                        {{ entry.reply.pending_action.tool }} on listing #{{ entry.reply.pending_action.preview.id }}. Nothing is saved until you confirm.
                    </p>
                    <dl v-if="isTitleChange(entry.reply.pending_action)" class="m-0 mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <dt class="text-[13px] text-muted">Before</dt>
                            <dd class="m-0 break-words text-[15px]">{{ titleOf(entry.reply.pending_action.preview.old_title) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[13px] text-muted">After</dt>
                            <dd class="m-0 break-words text-[15px] font-medium">{{ titleOf(entry.reply.pending_action.preview.new_title) }}</dd>
                        </div>
                    </dl>
                    <dl v-else-if="'tag' in entry.reply.pending_action.preview" class="m-0 mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <dt class="text-[13px] text-muted">Before: tags on {{ carLabel(entry.reply.pending_action.preview) || 'the listing' }}</dt>
                            <dd class="m-0 break-words text-[15px]">{{ currentTags(entry.reply.pending_action.preview) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[13px] text-muted">After: adds the tag</dt>
                            <dd class="m-0 break-words text-[15px] font-medium">{{ entry.reply.pending_action.preview.tag }}</dd>
                        </div>
                    </dl>
                    <pre v-else class="m-0 mt-3 whitespace-pre-wrap break-words font-mono text-[13px]">{{ pretty(entry.reply.pending_action.preview) }}</pre>
                    <div v-if="openEntry?.id === entry.id" class="mt-3 flex flex-wrap gap-2">
                        <LabButton :disabled="busy" @click="decide('confirmed')">Confirm</LabButton>
                        <LabButton variant="secondary" :disabled="busy" @click="decide('rejected')">Reject</LabButton>
                    </div>
                </section>
            </template>

            <p v-if="entry.error" role="alert" class="mt-3 text-[15px] font-medium text-danger">{{ entry.error }}</p>
        </li>
    </ol>

    <form class="mt-8 flex flex-col gap-3 border-t border-rule pt-6" @submit.prevent="ask">
        <div class="flex min-w-0 flex-col gap-1">
            <label for="admin-question" class="text-[13px] font-medium text-muted">{{ entries.length > 0 ? 'Follow-up question' : 'Question' }}</label>
            <textarea
                id="admin-question"
                v-model="question"
                rows="3"
                placeholder='e.g. Show me listing 12'
                class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink placeholder:text-muted"
                :aria-invalid="tooLong"
                aria-describedby="admin-question-count"
            />
            <p id="admin-question-count" class="m-0 text-[13px]" :class="tooLong ? 'font-medium text-danger' : 'text-muted'">
                {{ length }} / {{ maxQuestionLength }} characters
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <LabButton type="submit" :disabled="!canAsk">{{ busy ? 'Working…' : 'Ask' }}</LabButton>
            <LabButton variant="secondary" :disabled="entries.length === 0 || busy" @click="newChat">New chat</LabButton>
        </div>
    </form>
    <p v-if="tooLong" role="alert" class="mt-2 text-[13px] text-danger">The question is limited to {{ maxQuestionLength }} characters.</p>
</template>
