<?php

declare(strict_types=1);

function topicSource(string $path): string
{
    return file_get_contents(base_path('resources/js/ai/'.$path));
}

it('AC-4 the lesson page shows the topic header in place of the breadcrumb and the guide link', function (): void {
    $page = topicSource('Pages/Lab/Lesson.vue');

    expect($page)->toContain("import TopicHeader from '../../components/lesson/TopicHeader.vue'")
        ->toContain('<TopicHeader')
        ->not->toContain('Read the guide')
        ->not->toContain('<Link href="/ai/learn"');
});

it('AC-5 the guide page shows the topic header in place of the bottom lessons nav', function (): void {
    $page = topicSource('Pages/Lab/Guide.vue');

    expect($page)->toContain("import TopicHeader from '../../components/lesson/TopicHeader.vue'")
        ->toContain('<TopicHeader')
        ->not->toContain('aria-label="Lessons"');
});

it('NFR-2 the topic header is a labelled nav whose current item carries aria-current and a visible focus outline', function (): void {
    $header = topicSource('components/lesson/TopicHeader.vue');

    expect($header)->toContain('<nav aria-label="This topic"')
        ->toContain('aria-current="page"')
        ->toContain('focus-visible:outline');
});

it('AC-4 AC-5 the topic header shows the breadcrumb, the Walk through and Reference switch, the lesson-coming state, Also and the meta line', function (): void {
    $header = topicSource('components/lesson/TopicHeader.vue');

    expect($header)->toContain('Learn')
        ->toContain('`/ai/learn#${')
        ->toContain('Walk through')
        ->toContain('Reference')
        ->toContain('lesson coming')
        ->toContain('aria-disabled="true"')
        ->toContain('Also:')
        ->toContain('meta');

    expect(topicSource('Pages/Lab/Guide.vue'))->toContain('How these lessons work')->toContain('meta="reference"');
});

it('MNC-1 MNC-2 the lesson keys and the guide tabs stay in their pages', function (): void {
    expect(topicSource('Pages/Lab/Lesson.vue'))->toContain('lessonActionFor')->toContain('onKeydown');
    expect(topicSource('composables/useLessonProgress.ts'))->toContain('ai-lab:learn:v1');

    $guide = topicSource('Pages/Lab/Guide.vue');

    expect($guide)->toContain('role="tablist"')->toContain('aria-label="Guide sections"')->toContain('aria-label="Related guides"');
});
