<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import CourseSidebar from '../../components/lesson/CourseSidebar.vue';
import KpiStrip from '../../components/lesson/KpiStrip.vue';
import LearnList from '../../components/lesson/LearnList.vue';
import SceneCanvas from '../../components/lesson/SceneCanvas.vue';
import StepBar from '../../components/lesson/StepBar.vue';
import StepPanel from '../../components/lesson/StepPanel.vue';
import TopicHeader from '../../components/lesson/TopicHeader.vue';
import LabTag from '../../components/LabTag.vue';
import { lessonActionFor } from '../../composables/lessonKeys';
import type { Course, Lesson } from '../../composables/lessonTypes';
import { useLessonProgress } from '../../composables/useLessonProgress';

const props = defineProps<{
    lesson: Lesson;
    step: number;
    course: Course;
    guides: Array<{ key: string; title: string; href: string }>;
}>();

const progress = useLessonProgress();

const paused = ref(false);
const replayToken = ref(0);

const topicModule = computed(() => {
    const found = props.course.modules.find((module) => module.slug === props.lesson.module);

    return found ? { slug: found.slug, title: found.title } : null;
});

const key = computed(() => `${props.lesson.module}/${props.lesson.slug}`);
const total = computed(() => props.lesson.steps.length);
const current = computed(() => props.lesson.steps[props.step - 1]);
const previous = computed(() => (props.step > 1 ? props.lesson.steps[props.step - 2] : null));

const ticked = computed(() => {
    const viewed = progress.viewedSteps(key.value);

    return props.lesson.steps.filter((step) => viewed.includes(step.number)).flatMap((step) => step.learn);
});

const announcement = computed(() => {
    const values = current.value.kpis.map((kpi) => `${kpi.label} ${kpi.value}`).join(', ');

    return `Step ${props.step} of ${total.value}: ${current.value.title}. ${values}`;
});

/**
 * The lesson came with the page, so a step change is a client-side visit: a history entry with the new step, no request.
 */
function go(number: number): void {
    if (number < 1 || number > total.value || number === props.step) {
        return;
    }

    router.push({
        url: `/ai/learn/${props.lesson.module}/${props.lesson.slug}/${number}`,
        props: (current) => ({ ...current, step: number }),
        preserveState: true,
        preserveScroll: true,
    });
}

function togglePause(): void {
    paused.value = !paused.value;
}

function replay(): void {
    paused.value = false;
    replayToken.value += 1;
}

function onKeydown(event: KeyboardEvent): void {
    const action = lessonActionFor(event);

    if (action === null) {
        return;
    }

    if (action === 'pause') {
        event.preventDefault();
    }

    ({
        next: () => go(props.step + 1),
        previous: () => go(props.step - 1),
        replay,
        pause: togglePause,
    })[action]();
}

watch(() => [key.value, props.step], () => progress.markViewed(key.value, props.step, total.value), { immediate: true });

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <TopicHeader
        :module="topicModule"
        :title="lesson.title"
        :meta="null"
        view="walk"
        :walk-href="null"
        :reference-href="guides[0]?.href ?? null"
        :also="guides.slice(1)"
    />
    <h1 class="mt-2 text-[28px] font-semibold">{{ lesson.title }}</h1>
    <p class="mt-2 flex flex-wrap items-center gap-2 text-[15px] text-muted">
        <span>{{ lesson.summary }}</span>
        <LabTag>{{ lesson.level }}, {{ lesson.minutes }} min, {{ total }} steps</LabTag>
    </p>

    <div class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</div>

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <CourseSidebar :course="course" :current="key" />

        <div class="grid min-w-0 flex-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px] min-[1440px]:grid-cols-[minmax(0,1fr)_340px]">
            <div class="min-w-0">
                <SceneCanvas
                    :nodes="lesson.nodes"
                    :edges="lesson.edges"
                    :legend="lesson.legend"
                    :step="current"
                    :previous="previous"
                    :paused="paused"
                    :replay-token="replayToken"
                >
                    <KpiStrip :kpis="current.kpis" />
                </SceneCanvas>
                <StepBar :steps="lesson.steps" :current="step" :paused="paused" @go="go" @replay="replay" @pause="togglePause" />
            </div>
            <div class="grid min-w-0 content-start gap-4 lg:grid-cols-2 xl:grid-cols-1">
                <StepPanel :step="current" :total="total" />
                <LearnList :outcomes="lesson.learn" :ticked="ticked" />
            </div>
        </div>
    </div>
</template>
