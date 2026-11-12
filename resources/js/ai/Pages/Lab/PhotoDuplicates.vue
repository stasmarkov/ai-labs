<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabButton from '../../components/LabButton.vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSimilarityBar from '../../components/LabSimilarityBar.vue';
import LabTag from '../../components/LabTag.vue';
import type { GuideStep } from '../../composables/useGuideSteps';

interface GroupPhoto {
    id: number;
    url: string;
    title: string;
    similarity: number;
    variant: string | null;
    source: 'original' | 'augmented';
    reviewStatus: 'kept' | 'marked_for_removal' | null;
}

interface Judgement {
    status: string;
    provider: string;
    model: string;
    promptVersion: string;
    attempts: number;
    latencyMs: number | null;
    bestPhotoId: number | null;
    reasons: string[];
    issues: Array<{ photo_id: number; problem: string }>;
    errors: Record<string, string[]> | null;
    runHref: string | null;
}

interface Review {
    keptPhotoId: number;
    source: 'model' | 'override';
    reviewedAt: string;
}

interface Group {
    id: number;
    wasSplit: boolean;
    photos: GroupPhoto[];
    judgement: Judgement | null;
    review: Review | null;
}

interface Summary {
    groups: number;
    judged: number;
    reviewed: number;
    markedForRemoval: number;
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    label: string;
    cutoff: number;
    summary: Summary;
    groups: Group[];
    pagination: { page: number; lastPage: number; total: number };
    pageUrl: string;
    keepUrl: string;
    undoUrl: string;
    idPlaceholder: string;
    commands: string[];
}>();

const cards = ref<Group[]>(props.groups);
const totals = ref<Summary>(props.summary);
const busyGroup = ref<number | null>(null);
const messages = ref<Record<number, string>>({});

watch(() => props.groups, (next) => {
    cards.value = next;
    messages.value = {};
});

watch(() => props.summary, (next) => {
    totals.value = next;
});

function urlFor(template: string, id: number): string {
    return template.replace(props.idPlaceholder, String(id));
}

function pageHref(page: number): string {
    return `${props.pageUrl}?${new URLSearchParams({ page: String(page) }).toString()}`;
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function removeCount(group: Group): number {
    return group.photos.length - 1;
}

function problemOf(group: Group, photoId: number): string | null {
    return group.judgement?.issues.find((issue) => issue.photo_id === photoId)?.problem.replace('_', ' ') ?? null;
}

function tagOf(photo: GroupPhoto): string {
    return photo.variant ? `${photo.source} · ${photo.variant}` : photo.source;
}

function statusText(photo: GroupPhoto): string | null {
    if (photo.reviewStatus === 'kept') {
        return 'kept';
    }

    return photo.reviewStatus === 'marked_for_removal' ? 'marked for removal' : null;
}

function replace(next: Group): void {
    cards.value = cards.value.map((card) => (card.id === next.id ? next : card));
}

async function send(group: Group, url: string, body: Record<string, number> | null): Promise<void> {
    if (busyGroup.value !== null) {
        return;
    }

    busyGroup.value = group.id;
    const { [group.id]: _dropped, ...rest } = messages.value;
    messages.value = rest;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
                ...(body === null ? {} : { 'Content-Type': 'application/json' }),
            },
            body: body === null ? undefined : JSON.stringify(body),
        });
        const payload = (await response.json()) as { group?: Group; summary?: Summary; message?: string };

        if (!response.ok || !payload.group || !payload.summary) {
            messages.value = { ...messages.value, [group.id]: payload.message ?? `Could not save the review (server error ${response.status}).` };

            return;
        }

        replace(payload.group);
        totals.value = payload.summary;
    } catch {
        messages.value = { ...messages.value, [group.id]: 'Could not save the review (connection lost).' };
    } finally {
        busyGroup.value = null;
    }
}

function keep(group: Group, photo: GroupPhoto): Promise<void> {
    return send(group, urlFor(props.keepUrl, group.id), { photo_id: photo.id });
}

function undo(group: Group): Promise<void> {
    return send(group, urlFor(props.undoUrl, group.id), null);
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Duplicate photos</h1>
    <p class="mt-2 max-w-[65ch] text-[15px] text-muted">
        Photos whose image vectors are nearly the same are joined into groups. A vision model suggests the best photo of each group, and you decide. Keeping a photo only marks the
        others; nothing is deleted.
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <LabTag caption="vectors">{{ label }}</LabTag>
        <LabTag caption="cutoff">{{ cutoff.toFixed(2) }}</LabTag>
    </div>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="[]" state="idle" />

    <section v-if="pagination.total === 0" aria-labelledby="duplicates-empty-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="duplicates-empty-title" class="m-0 text-[18px]">No duplicate groups yet</h2>
        <p class="mt-2 max-w-[65ch] text-[15px]">
            The imported photos hold no near-duplicates, so the lab makes some, finds the groups and asks the model for a pick. Run these commands, then reload:
        </p>
        <pre class="m-0 mt-2 max-w-[65ch] overflow-auto rounded-sm border border-rule p-3 font-mono text-[13px]">{{ commands.join('\n') }}</pre>
        <p class="mt-2 max-w-[65ch] text-[15px]">
            The last one needs a worker on the vision queue. The
            <Link :href="guideHref" class="font-semibold text-ink underline">guide</Link> explains each step.
        </p>
    </section>

    <template v-else>
        <section aria-labelledby="duplicates-summary-title" class="mt-8 border-t border-rule pt-6">
            <h2 id="duplicates-summary-title" class="m-0 text-[18px]">Summary</h2>
            <dl class="m-0 mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <LabMetric label="Groups" :value="totals.groups" />
                <LabMetric label="Judged" :value="totals.judged" />
                <LabMetric label="Reviewed" :value="totals.reviewed" />
                <LabMetric label="Marked for removal" :value="totals.markedForRemoval" />
            </dl>
        </section>

        <ul class="m-0 mt-8 grid list-none gap-8 p-0">
            <li v-for="group in cards" :key="group.id" class="min-w-0 border-t border-rule pt-6">
                <section :aria-labelledby="`duplicates-group-${group.id}`">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 :id="`duplicates-group-${group.id}`" class="m-0 text-[18px]">Group {{ group.id }}</h2>
                        <LabTag caption="photos">{{ group.photos.length }}</LabTag>
                        <LabTag v-if="group.wasSplit" caption="note">split from a larger group</LabTag>
                        <LabTag v-if="group.review" caption="review">{{ group.review.source === 'model' ? 'model pick kept' : 'your pick kept' }}</LabTag>
                    </div>

                    <ul class="m-0 mt-3 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 xl:grid-cols-6">
                        <li v-for="photo in group.photos" :key="photo.id" class="min-w-0">
                            <div
                                class="rounded-sm border p-1"
                                :class="group.judgement?.bestPhotoId === photo.id ? 'border-ink' : 'border-rule'"
                            >
                                <img :src="photo.url" :alt="`${photo.title}, ${tagOf(photo)}`" loading="lazy" class="aspect-[4/3] w-full rounded-sm bg-rule object-cover" />
                                <span class="mt-1 block truncate text-[13px]">{{ photo.title }}</span>
                                <LabSimilarityBar :value="photo.similarity" />
                                <span class="block text-[13px] text-muted">{{ tagOf(photo) }}</span>
                                <span v-if="group.judgement?.bestPhotoId === photo.id" class="block text-[13px] font-semibold">model's pick</span>
                                <span v-if="problemOf(group, photo.id)" class="block text-[13px] text-muted">{{ problemOf(group, photo.id) }}</span>
                                <span
                                    v-if="statusText(photo)"
                                    class="block text-[13px] font-semibold"
                                    :class="photo.reviewStatus === 'kept' ? 'text-success' : 'text-danger'"
                                >{{ statusText(photo) }}</span>
                            </div>
                            <div class="mt-2">
                                <LabButton
                                    :variant="group.judgement?.bestPhotoId === photo.id || group.judgement?.bestPhotoId == null ? 'primary' : 'secondary'"
                                    :disabled="busyGroup !== null || group.review?.keptPhotoId === photo.id"
                                    @click="keep(group, photo)"
                                >
                                    {{ group.judgement?.bestPhotoId == null || group.judgement.bestPhotoId === photo.id ? `Keep this one, remove ${removeCount(group)}` : 'Pick another' }}
                                </LabButton>
                            </div>
                        </li>
                    </ul>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <LabButton v-if="group.review" variant="secondary" :disabled="busyGroup !== null" @click="undo(group)">Undo</LabButton>
                        <span v-if="group.review" aria-live="polite" class="text-[13px] text-muted">
                            {{ removeCount(group) }} marked for removal; no file or row was deleted.
                        </span>
                    </div>
                    <p v-if="messages[group.id]" role="alert" class="mt-2 text-[15px] font-medium text-danger">{{ messages[group.id] }}</p>

                    <div class="mt-4">
                        <h3 class="m-0 text-[15px]">Model's judgement</h3>
                        <p v-if="group.judgement === null" class="mt-1 text-[13px] text-muted">
                            Not judged yet by the current model. Run <code class="font-mono">ddev artisan photos:duplicates --judge --wait</code> with a worker on the vision queue.
                        </p>
                        <template v-else>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <LabTag caption="model">{{ group.judgement.model }}</LabTag>
                                <LabTag caption="prompt">{{ group.judgement.promptVersion }}</LabTag>
                                <LabTag caption="status">{{ group.judgement.status }}</LabTag>
                                <LabTag v-if="group.judgement.latencyMs !== null" caption="latency">{{ group.judgement.latencyMs }} ms</LabTag>
                                <LabTag v-if="group.judgement.attempts > 0" caption="attempts">{{ group.judgement.attempts }}</LabTag>
                            </div>
                            <ul v-if="group.judgement.reasons.length > 0" class="m-0 mt-2 list-disc pl-5 text-[15px]">
                                <li v-for="reason in group.judgement.reasons" :key="reason">{{ reason }}</li>
                            </ul>
                            <ul v-if="group.judgement.errors" class="m-0 mt-2 list-disc pl-5 text-[15px] text-danger">
                                <li v-for="(lines, field) in group.judgement.errors" :key="field">{{ field }}: {{ lines.join(' ') }}</li>
                            </ul>
                            <p v-if="group.judgement.runHref" class="mt-2 text-[15px]">
                                <a :href="group.judgement.runHref" class="font-semibold text-ink underline">Logged run of the last attempt</a>
                            </p>
                        </template>
                    </div>
                </section>
            </li>
        </ul>

        <nav v-if="pagination.lastPage > 1" aria-label="Group pages" class="mt-6 flex items-center gap-4 text-[15px]">
            <Link v-if="pagination.page > 1" :href="pageHref(pagination.page - 1)" class="font-semibold text-ink underline">Previous</Link>
            <Link v-if="pagination.page < pagination.lastPage" :href="pageHref(pagination.page + 1)" class="font-semibold text-ink underline">Next</Link>
        </nav>
    </template>
</template>
