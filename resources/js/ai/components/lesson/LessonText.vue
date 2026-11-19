<script setup lang="ts">
import { computed } from 'vue';
import { textParts } from '../../composables/lessonText';

const props = defineProps<{ text: string }>();

const parts = computed(() => textParts(props.text));
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <strong v-if="part.kind === 'bold'">{{ part.text }}</strong>
        <code v-else-if="part.kind === 'code'" class="rounded-sm bg-[#ebe6da] px-1 font-mono text-[0.93em]">{{ part.text }}</code>
        <template v-else>{{ part.text }}</template>
    </template>
</template>
