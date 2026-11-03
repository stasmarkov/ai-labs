<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LabStatusDot from '../../components/LabStatusDot.vue';
import LabTag from '../../components/LabTag.vue';

interface Capability {
    key: string;
    title: string;
    route: string;
    status: 'ready' | 'coming';
    slice: string;
}

interface Run {
    invocation_id: string;
    agent: string;
    provider: string;
    model: string;
    tokens_in: number | null;
    tokens_out: number | null;
    ttft_ms: number | null;
    latency_ms: number;
    status: 'completed' | 'cached' | 'failed' | 'refused' | 'no_context';
    created_at: string;
}

defineProps<{
    textProvider: string;
    textModel: string;
    embeddingProvider: string;
    embeddingModel: string;
    ollama: { reachable: boolean | 'not-used' };
    capabilities: Capability[];
    recentRuns: Run[];
}>();

const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

function relativeTime(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        const rounded = Math.round(seconds / size);

        // Compare the rounded value, so 59.6 minutes reads "1 hour ago", not "60 minutes ago".
        if (Math.abs(rounded) >= 1) {
            return formatter.format(rounded, unit);
        }
    }

    return formatter.format(seconds, 'second');
}

function show(value: number | null): string {
    return value === null ? '—' : String(value);
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">AI lab</h1>

    <section aria-labelledby="lab-models" class="mt-6">
        <h2 id="lab-models" class="m-0 text-[18px]">Models</h2>
        <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-3">
            <div class="flex flex-col gap-1">
                <dt class="text-[13px] text-muted">Text</dt>
                <dd class="m-0 flex flex-wrap items-center gap-2">
                    <LabTag caption="provider">{{ textProvider }}</LabTag>
                    <LabTag caption="model">{{ textModel }}</LabTag>
                </dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-[13px] text-muted">Embeddings</dt>
                <dd class="m-0 flex flex-wrap items-center gap-2">
                    <LabTag caption="provider">{{ embeddingProvider }}</LabTag>
                    <LabTag caption="model">{{ embeddingModel }}</LabTag>
                </dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-[13px] text-muted">Ollama</dt>
                <dd class="m-0">
                    <LabStatusDot
                        :state="ollama.reachable === true ? 'ok' : ollama.reachable === false ? 'down' : 'idle'"
                        :label="ollama.reachable === true ? 'reachable' : ollama.reachable === false ? 'unreachable' : 'not used'"
                    />
                </dd>
            </div>
        </dl>
    </section>

    <section aria-labelledby="lab-capabilities" class="mt-8 border-t border-rule pt-6">
        <h2 id="lab-capabilities" class="m-0 text-[18px]">Capabilities</h2>
        <ul class="m-0 mt-3 grid list-none grid-cols-1 gap-x-8 gap-y-4 p-0 sm:grid-cols-2">
            <li v-for="capability in capabilities" :key="capability.key" class="flex flex-col gap-1">
                <Link :href="capability.route" class="text-[15px] font-semibold text-ink underline">
                    {{ capability.title }}
                </Link>
                <span class="font-mono text-[13px] text-muted">
                    {{ capability.status === 'ready' ? 'ready' : 'coming' }} · {{ capability.slice }}
                </span>
            </li>
        </ul>
    </section>

    <section aria-labelledby="lab-runs" class="mt-8 border-t border-rule pt-6">
        <h2 id="lab-runs" class="m-0 text-[18px]">Recent runs</h2>
        <p v-if="recentRuns.length === 0" class="mt-3 text-[15px] text-muted">No runs yet.</p>
        <div v-else class="mt-3 overflow-x-auto" tabindex="0" role="region" aria-label="Recent runs, scrolls sideways">
            <table class="w-full border-collapse whitespace-nowrap text-left text-[13px]">
                <caption class="sr-only">The 20 newest AI runs</caption>
                <thead>
                    <tr class="border-b border-rule text-muted">
                        <th scope="col" class="py-2 pr-4 font-normal">Agent</th>
                        <th scope="col" class="py-2 pr-4 font-normal">Provider</th>
                        <th scope="col" class="py-2 pr-4 font-normal">Model</th>
                        <th scope="col" class="py-2 pr-4 text-right font-normal">Tokens in</th>
                        <th scope="col" class="py-2 pr-4 text-right font-normal">Tokens out</th>
                        <th scope="col" class="py-2 pr-4 text-right font-normal">TTFT ms</th>
                        <th scope="col" class="py-2 pr-4 text-right font-normal">Latency ms</th>
                        <th scope="col" class="py-2 pr-4 font-normal">Status</th>
                        <th scope="col" class="py-2 font-normal">Time</th>
                    </tr>
                </thead>
                <tbody class="font-mono tabular-nums">
                    <tr v-for="run in recentRuns" :key="run.invocation_id" class="border-b border-rule">
                        <td class="py-2 pr-4">{{ run.agent }}</td>
                        <td class="py-2 pr-4">{{ run.provider }}</td>
                        <td class="py-2 pr-4">{{ run.model }}</td>
                        <td class="py-2 pr-4 text-right">{{ show(run.tokens_in) }}</td>
                        <td class="py-2 pr-4 text-right">{{ show(run.tokens_out) }}</td>
                        <td class="py-2 pr-4 text-right">{{ show(run.ttft_ms) }}</td>
                        <td class="py-2 pr-4 text-right">{{ run.latency_ms }}</td>
                        <td class="py-2 pr-4" :class="run.status === 'failed' ? 'text-danger' : run.status === 'completed' || run.status === 'cached' ? 'text-success' : 'text-muted'">{{ run.status }}</td>
                        <td class="py-2"><time :datetime="run.created_at" :title="run.created_at">{{ relativeTime(run.created_at) }}</time></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
