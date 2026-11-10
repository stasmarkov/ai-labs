<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    file: string;
    startLine: number;
    endLine: number;
    code: string;
}>();

// The server HTML-escapes the code, so each line is safe to render as markup.
const lines = computed(() => props.code.replace(/\n$/, '').split('\n'));
</script>

<template>
    <figure class="m-0 min-w-0 rounded-sm border border-rule">
        <figcaption class="border-b border-rule px-3 py-1 font-mono text-[13px] text-muted">
            {{ file }}, lines {{ startLine }} to {{ endLine }}
        </figcaption>
        <pre class="m-0 overflow-x-auto p-3 font-mono text-[13px] leading-[1.6]" tabindex="0"><code class="block"><span
            v-for="(line, index) in lines"
            :key="index"
            class="flex"
        ><span class="w-10 shrink-0 select-none pr-3 text-right tabular-nums text-muted" aria-hidden="true">{{ startLine + index }}</span><span class="whitespace-pre" v-html="line || ' '" /></span></code></pre>
    </figure>
</template>
