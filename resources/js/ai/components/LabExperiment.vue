<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    title: string;
    predictions: Array<{ id: string; text: string }>;
    answer: { id: string; html: string };
    href: string;
}>();

const storageKey = `ai-lab:experiment:${props.href}:${props.title}`;
const picked = ref('');
const measured = ref('');

onMounted(() => {
    try {
        const saved = JSON.parse(window.localStorage.getItem(storageKey) ?? '{}');
        picked.value = typeof saved.picked === 'string' ? saved.picked : '';
        measured.value = typeof saved.measured === 'string' ? saved.measured : '';
    } catch {
        picked.value = '';
        measured.value = '';
    }
});

watch([picked, measured], () => {
    try {
        window.localStorage.setItem(storageKey, JSON.stringify({ picked: picked.value, measured: measured.value }));
    } catch {
        // storage can be unavailable; the card still works without it
    }
});

const pickedPrediction = computed(() => props.predictions.find((prediction) => prediction.id === picked.value) ?? null);
const matched = computed(() => picked.value === props.answer.id);
</script>

<template>
    <article class="flex min-w-0 flex-col gap-2 border-b border-rule pb-5">
        <h3 class="m-0 text-[16px] font-semibold">{{ title }}</h3>
        <fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 p-0">
            <legend class="mb-1 text-[13px] font-medium text-muted">Your prediction</legend>
            <label v-for="prediction in predictions" :key="prediction.id" class="flex items-start gap-2 text-[15px]">
                <input v-model="picked" type="radio" :name="storageKey" :value="prediction.id" class="mt-1" />
                <span>{{ prediction.text }}</span>
            </label>
        </fieldset>
        <Link :href="href" class="self-start text-[15px] font-semibold text-signal underline">Run it</Link>
        <label :for="`${storageKey}-measured`" class="text-[13px] font-medium text-muted">What you measured</label>
        <textarea :id="`${storageKey}-measured`" v-model="measured" rows="2" class="w-full min-w-0 border border-rule bg-transparent p-2 text-[15px]"></textarea>
        <div v-if="pickedPrediction" class="grid gap-1 text-[15px]">
            <p class="m-0 break-words"><span class="text-[13px] text-muted">You predicted: </span>{{ pickedPrediction.text }}</p>
            <p class="m-0 font-semibold">{{ matched ? 'Your prediction matched.' : 'Your prediction did not match.' }}</p>
            <div class="break-words" v-html="answer.html"></div>
        </div>
        <p v-if="measured.trim() !== ''" class="m-0 break-words text-[15px]"><span class="text-[13px] text-muted">Measured: </span>{{ measured }}</p>
    </article>
</template>
