<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LabButton from '../../components/LabButton.vue';
import { csrfToken } from '../../composables/csrfToken';

interface Current {
    title: string | null;
    tags: string[];
    tags_total: number;
}

interface Change {
    id: string;
    tool: string;
    status: 'pending' | 'confirmed' | 'rejected' | 'expired' | 'failed';
    arguments: Record<string, unknown>;
    preview: Record<string, unknown>;
    result: Record<string, unknown> | null;
    error: string | null;
    failure?: string | null;
    proposed_by?: { time: string | null; tool: string; arguments: Record<string, unknown> } | null;
    current?: Current | null;
    nonce?: string;
    expires_at: string;
    decided_at: string | null;
    created_at: string;
}

interface Row {
    id: string;
    tool: string;
    status: Change['status'];
    listing: { id: number; make: string | null; model: string | null; year: number | null };
    summary: { old_title?: string | null; new_title?: string; tag?: string; already_tagged?: boolean };
    current?: Current | null;
    nonce?: string;
    expires_at: string;
}

const props = defineProps<{
    pending: Row[];
    recent: Row[];
    change: Change | null;
    showUrl: string;
    confirmTemplate: string;
    rejectTemplate: string;
    idPlaceholder: string;
    confirmUrl?: string;
    rejectUrl?: string;
}>();

const shown = ref<Change | null>(props.change);
const busy = ref(false);
const message = ref<string | null>(null);
const rowMessages = ref<Record<string, string>>({});
const decidable = computed(() => shown.value?.status === 'pending' && !!shown.value.nonce);

watch(
    () => props.change,
    (next) => {
        shown.value = next;
    },
);

function hrefFor(id: string): string {
    return props.showUrl.replace(props.idPlaceholder, id);
}

function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

function carOf(listing: Row['listing']): string {
    return [listing.make, listing.model].filter(Boolean).join(' ');
}

function labelOf(listing: Row['listing']): string {
    return [`Listing ${listing.id}`, [carOf(listing), listing.year].filter(Boolean).join(' ')].filter(Boolean).join(', ');
}

function before(listing: Row['listing'], oldTitle: string | null | undefined): string {
    return oldTitle ?? carOf(listing);
}

function tagsText(current: Current): string {
    const more = current.tags_total - current.tags.length;

    return current.tags.length === 0 ? 'no tags' : `${current.tags.join(', ')}${more > 0 ? ` and ${more} more` : ''}`;
}

/** Posts a decision, then reloads the lists (and the open change), also after a 409 so a stale page shows the truth. */
async function decide(id: string, url: string, nonce: string | undefined, fromRow: boolean): Promise<void> {
    if (!nonce || busy.value) {
        return;
    }

    busy.value = true;
    message.value = null;
    const { [id]: _dropped, ...rest } = rowMessages.value;
    rowMessages.value = rest;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ nonce }),
        });
        const payload = (await response.json().catch(() => ({}))) as { data?: Change; message?: string };

        if (response.ok && payload.data) {
            if (!fromRow) {
                shown.value = payload.data;
            }
        } else if (fromRow) {
            rowMessages.value = { ...rowMessages.value, [id]: payload.message || `Request failed (server error ${response.status}).` };
        } else {
            message.value = payload.message || `Request failed (server error ${response.status}).`;
        }

        if (response.ok || response.status === 409) {
            router.reload({ only: props.change === null ? ['pending', 'recent'] : ['pending', 'recent', 'change'] });
        }
    } catch {
        const text = 'Could not reach the server (connection lost).';

        if (fromRow) {
            rowMessages.value = { ...rowMessages.value, [id]: text };
        } else {
            message.value = text;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Changes</h1>
    <p class="mt-2 text-[15px] text-muted">
        Changes an outside client proposed over MCP. Nothing is saved until you confirm a change here.
    </p>

    <section v-if="shown" aria-labelledby="change-heading" class="mt-6 rounded-sm border border-ink px-4 py-3" data-section="change" :data-status="shown.status">
        <h2 id="change-heading" class="m-0 text-[17px] font-semibold">{{ shown.tool }}</h2>
        <p class="m-0 mt-1 text-[13px] text-muted">Status: {{ shown.status }}. Expires {{ shown.expires_at }}.</p>
        <pre class="m-0 mt-3 whitespace-pre-wrap break-words font-mono text-[13px]">{{ pretty(shown.preview) }}</pre>
        <div v-if="shown.current" class="mt-3" data-section="current">
            <h3 class="m-0 text-[15px] font-semibold">The listing now</h3>
            <p class="m-0 mt-1 text-[13px] text-muted">Title: {{ shown.current.title ?? 'none' }}. Tags: {{ tagsText(shown.current) }}.</p>
        </div>
        <div v-if="shown.proposed_by" class="mt-3" data-section="proposed-by">
            <h3 class="m-0 text-[15px] font-semibold">Proposed by</h3>
            <p class="m-0 mt-1 text-[13px] text-muted">{{ shown.proposed_by.tool }}<template v-if="shown.proposed_by.time"> at {{ shown.proposed_by.time }}</template></p>
            <pre class="m-0 mt-2 whitespace-pre-wrap break-words font-mono text-[13px]">{{ pretty(shown.proposed_by.arguments) }}</pre>
        </div>
        <p v-if="shown.status === 'confirmed'" class="m-0 mt-3 text-[15px] font-medium">Applied.</p>
        <p v-if="shown.status === 'failed'" role="alert" class="m-0 mt-3 text-[15px] font-medium text-danger">Not applied: {{ shown.failure || shown.error }}</p>
        <p v-if="shown.status === 'expired'" class="m-0 mt-3 text-[15px] text-muted">This change expired. Ask the client to propose it again.</p>
        <div v-if="decidable && confirmUrl && rejectUrl" class="mt-3 flex flex-wrap gap-2">
            <LabButton :disabled="busy" @click="decide(shown.id, confirmUrl, shown.nonce, false)">Confirm</LabButton>
            <LabButton variant="secondary" :disabled="busy" @click="decide(shown.id, rejectUrl, shown.nonce, false)">Reject</LabButton>
        </div>
        <p v-if="message" role="alert" class="m-0 mt-3 text-[15px] font-medium text-danger">{{ message }}</p>
    </section>

    <section aria-labelledby="pending-heading" class="mt-8">
        <h2 id="pending-heading" class="m-0 text-[17px] font-semibold">Waiting for a decision</h2>
        <p v-if="pending.length === 0" class="mt-2 text-[15px] text-muted">No change is waiting.</p>
        <ul v-else class="mt-3 grid list-none grid-cols-1 gap-3 p-0">
            <li v-for="item in pending" :key="item.id" class="rounded-sm border border-rule px-4 py-3" data-section="pending-item">
                <Link :href="hrefFor(item.id)" class="font-medium underline">{{ item.tool }}</Link>
                <span class="ml-2 text-[13px] text-muted">expires {{ item.expires_at }}</span>
                <p class="m-0 mt-1 text-[13px] text-muted">{{ labelOf(item.listing) }}</p>
                <p v-if="item.summary.new_title !== undefined" class="m-0 mt-2 text-[15px]">
                    Title: "{{ before(item.listing, item.summary.old_title) }}" to "{{ item.summary.new_title }}"
                </p>
                <p v-else-if="item.summary.tag !== undefined" class="m-0 mt-2 text-[15px]">
                    Add the tag "{{ item.summary.tag }}"<template v-if="item.summary.already_tagged"> (the listing has it already)</template>
                </p>
                <p v-if="item.current" class="m-0 mt-1 text-[13px] text-muted" data-section="current">Now: title {{ item.current.title ?? 'none' }}, {{ tagsText(item.current) }}.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <LabButton :disabled="busy" @click="decide(item.id, confirmTemplate.replace(idPlaceholder, item.id), item.nonce, true)">Confirm</LabButton>
                    <LabButton variant="secondary" :disabled="busy" @click="decide(item.id, rejectTemplate.replace(idPlaceholder, item.id), item.nonce, true)">Reject</LabButton>
                </div>
                <p v-if="rowMessages[item.id]" role="alert" class="m-0 mt-2 text-[15px] font-medium text-danger">{{ rowMessages[item.id] }}</p>
            </li>
        </ul>
    </section>

    <section aria-labelledby="recent-heading" class="mt-8">
        <h2 id="recent-heading" class="m-0 text-[17px] font-semibold">Decided</h2>
        <p v-if="recent.length === 0" class="mt-2 text-[15px] text-muted">Nothing decided yet.</p>
        <ul v-else class="mt-3 grid list-none grid-cols-1 gap-2 p-0">
            <li v-for="item in recent" :key="item.id">
                <Link :href="hrefFor(item.id)" class="underline">{{ item.tool }}</Link>
                <span class="ml-2 text-[13px] text-muted">{{ item.status }}</span>
            </li>
        </ul>
    </section>
</template>
