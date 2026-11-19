<script setup lang="ts">
/**
 * One small line icon per node kind, drawn on a 24 × 24 grid with plain shapes. The parent places and scales it.
 */
defineProps<{ kind: string }>();
</script>

<template>
    <g fill="none" stroke="#1a1916" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="kind === 'browser'">
            <rect x="2" y="4" width="20" height="16" rx="2.5" />
            <path d="M2 9h20" />
            <circle cx="5.5" cy="6.5" r="0.6" fill="#1a1916" />
            <circle cx="8" cy="6.5" r="0.6" fill="#1a1916" />
            <path d="M6 14h8M6 17h5" />
        </template>
        <template v-else-if="kind === 'users'">
            <circle cx="9" cy="8" r="3.5" />
            <path d="M3 20c0-3.6 2.7-6 6-6s6 2.4 6 6" />
            <circle cx="17" cy="9" r="2.6" />
            <path d="M16.5 14.2c2.6.2 4.5 2.4 4.5 5.3" />
        </template>
        <template v-else-if="kind === 'app'">
            <path d="M12 2.5 21 7.5v9L12 21.5 3 16.5v-9Z" />
            <path d="M3 7.5 12 12.5l9-5M12 12.5v9" />
        </template>
        <template v-else-if="kind === 'threads'">
            <path d="M3 6h18M3 12h18M3 18h18" />
            <circle cx="8" cy="6" r="1.6" fill="#1a1916" />
            <circle cx="15" cy="12" r="1.6" fill="#1a1916" />
            <circle cx="11" cy="18" r="1.6" fill="#1a1916" />
        </template>
        <template v-else-if="kind === 'workers'">
            <rect x="3" y="3" width="8" height="8" rx="1.5" />
            <rect x="13" y="3" width="8" height="8" rx="1.5" />
            <rect x="3" y="13" width="8" height="8" rx="1.5" />
            <rect x="13" y="13" width="8" height="8" rx="1.5" />
            <path d="M6 7h2M16 7h2M6 17h2M16 17h2" />
        </template>
        <template v-else-if="kind === 'queue'">
            <rect x="2" y="7" width="5" height="10" rx="1" />
            <rect x="9" y="7" width="5" height="10" rx="1" />
            <rect x="16" y="7" width="5" height="10" rx="1" stroke-dasharray="2 1.6" />
            <path d="M4 21h15l-2-2" />
        </template>
        <template v-else-if="kind === 'model'">
            <rect x="6" y="6" width="12" height="12" rx="2" />
            <path d="M9.5 12h5M12 9.5v5" />
            <path d="M9 2.5v3M15 2.5v3M9 18.5v3M15 18.5v3M2.5 9h3M2.5 15h3M18.5 9h3M18.5 15h3" />
        </template>
        <template v-else-if="kind === 'vector-db'">
            <ellipse cx="12" cy="5.5" rx="8" ry="3" />
            <path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13" />
            <path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" />
        </template>
        <template v-else-if="kind === 'cache'">
            <path d="M13.5 2.5 5 13.5h6l-1.5 8 8.5-11h-6Z" />
        </template>
        <template v-else-if="kind === 'cloud'">
            <path d="M7 19h10.5a4 4 0 0 0 .6-8A6 6 0 0 0 6.6 9.6 4.7 4.7 0 0 0 7 19Z" />
        </template>
        <template v-else>
            <rect x="4" y="4" width="16" height="16" rx="3" />
        </template>
    </g>
</template>
