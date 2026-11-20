<?php

namespace Tests\Feature;

use App\DTOs\LessonDefinition;
use App\Guides\GuideFacts;
use App\Guides\GuideRepository;
use App\Lessons\InvalidLessonException;
use App\Lessons\LessonNumbers;
use App\Lessons\LessonRepository;
use App\Lessons\LessonService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonRepositoryTest extends TestCase
{
    private const RUN = 'llm/2026-11-13/run.json';

    private const TTFT = 'models."qwen3.5:9b".speed.ttft_ms.median';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/lessons-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->directory.'/mod', 0777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_ac_5_a_valid_lesson_loads(): void
    {
        $lesson = $this->service($this->lesson())->find('mod', 'one');

        $this->assertNotNull($lesson);
        $this->assertSame('One', $lesson->title);
        $this->assertCount(8, $lesson->steps);
        $this->assertSame(['request', 'token'], $lesson->legend);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenRules')]
    public function test_ac_5_each_broken_rule_fails_with_the_file_and_the_rule(callable $break, string $message): void
    {
        $service = $this->service($break($this->lesson()));

        try {
            $service->find('mod', 'one');
            $this->fail('The lesson should not load.');
        } catch (InvalidLessonException $exception) {
            $this->assertStringContainsString('mod/one', $exception->getMessage());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{callable, string}>
     */
    public static function brokenRules(): array
    {
        return [
            'unknown node kind' => [fn (array $l): array => self::set($l, 'scene.nodes.0.kind', 'toaster'), '[kind] must be one of'],
            'node off the grid' => [fn (array $l): array => self::set($l, 'scene.nodes.0.x', 1201), '[x] must be a whole number'],
            'duplicate node id' => [fn (array $l): array => self::set($l, 'scene.nodes.1.id', 'a'), 'id is used twice'],
            'edge to unknown node' => [fn (array $l): array => self::set($l, 'scene.edges.0.to', 'nope'), 'unknown node [nope]'],
            'duplicate edge id' => [fn (array $l): array => self::set($l, 'scene.edges.1.id', 'e1'), 'id is used twice'],
            'too few steps' => [fn (array $l): array => self::set($l, 'steps', array_slice($l['steps'], 0, 7)), 'needs 8 to 13 steps, not 7'],
            'too many steps' => [fn (array $l): array => self::set($l, 'steps', array_merge($l['steps'], array_fill(0, 6, $l['steps'][0]))), 'not 14'],
            'unknown role' => [fn (array $l): array => self::set($l, 'steps.2.role', 'villain'), '[role] must be one of'],
            'summary not last' => [fn (array $l): array => self::set($l, 'steps.3.role', 'summary'), 'summary role may only be the last'],
            'unknown outcome on a step' => [fn (array $l): array => self::set($l, 'steps.1.learn', ['lo-6']), 'unknown outcome [lo-6]'],
            'outcome taught by no step' => [fn (array $l): array => self::set($l, 'steps.2.learn', []), 'taught by no step'],
            'too few outcomes' => [fn (array $l): array => self::set($l, 'learn', array_slice($l['learn'], 0, 2)), '3 to 6 outcomes'],
            'unknown node in a state patch' => [fn (array $l): array => self::set($l, 'steps.1.state.nodes.ghost', ['load' => 5]), 'unknown node [ghost]'],
            'unknown edge in a state patch' => [fn (array $l): array => self::set($l, 'steps.1.state.edges.ghost', ['visible' => true]), 'unknown edge [ghost]'],
            'unknown packet kind' => [fn (array $l): array => self::set($l, 'steps.1.state.edges.e1.packets', ['laser' => 1]), 'unknown packet kind [laser]'],
            'packet rate too high' => [fn (array $l): array => self::set($l, 'steps.1.state.edges.e1.packets', ['request' => 6]), 'must be 0 to 5'],
            'load over 100' => [fn (array $l): array => self::set($l, 'steps.1.state.nodes.a.load', 101), '[load] must be a whole number'],
            'unknown status' => [fn (array $l): array => self::set($l, 'steps.1.state.nodes.a.status', 'asleep'), '[status] must be one of'],
            'numeric kpi literal' => [fn (array $l): array => self::set($l, 'steps.1.kpis.ttft', 42), 'literal must be non-numeric'],
            'numeric string kpi literal' => [fn (array $l): array => self::set($l, 'steps.1.kpis.ttft', '42'), 'literal must be non-numeric'],
            'kpi literal with a typed number' => [fn (array $l): array => self::set($l, 'steps.1.kpis.ttft', '~6.6 s'), 'literal must be non-numeric'],
            'unknown kpi on a step' => [fn (array $l): array => self::set($l, 'steps.1.kpis.ghost', 'x'), 'unknown kpi [ghost]'],
            'step 1 without every kpi' => [fn (array $l): array => self::set($l, 'steps.0.kpis', []), 'step 1 must give a value'],
            'number literal in a change' => [fn (array $l): array => self::set($l, 'steps.1.changed', [['sign' => '+', 'text' => '4 streams']]), 'number literal'],
            'unknown change sign' => [fn (array $l): array => self::set($l, 'steps.1.changed', [['sign' => '*', 'text' => 'more']]), '[sign] must be one of'],
            'unknown placeholder kpi' => [fn (array $l): array => self::set($l, 'steps.1.changed', [['sign' => '+', 'text' => 'now {kpi:ghost}']]), 'unknown kpi [ghost]'],
            'unresolved brace' => [fn (array $l): array => self::set($l, 'steps.1.body', ['an {oops} here']), 'unresolved placeholder'],
            'more than three paragraphs' => [fn (array $l): array => self::set($l, 'steps.1.body', ['a', 'b', 'c', 'd']), 'at most 3 paragraphs'],
            'unknown callout kind' => [fn (array $l): array => self::set($l, 'steps.1.callout.kind', 'shout'), '[kind] must be one of'],
            'unknown guide' => [fn (array $l): array => self::set($l, 'guide', 'no-such-guide'), 'guide [no-such-guide] does not exist'],
            'deeper to a missing guide' => [fn (array $l): array => self::set($l, 'steps.1.deeper', 'no-such-guide#works'), '[deeper] must be'],
            'deeper to a missing tab' => [fn (array $l): array => self::set($l, 'steps.1.deeper', 'local-models#sideways'), 'unknown tab [sideways]'],
            'deeper to a tab the guide does not show' => [fn (array $l): array => self::set($l, 'steps.1.deeper', 'local-models#plan'), 'has no [plan] tab'],
            'trend that is not true or false' => [fn (array $l): array => self::set($l, 'steps.1.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT, 'trend' => 'no']), '[trend] must be true or false'],
            'callout without text' => [fn (array $l): array => self::set($l, 'steps.1.callout', ['kind' => 'useful']), 'needs a non-empty [text]'],
            'unknown level' => [fn (array $l): array => self::set($l, 'level', 'wizard'), '[level] must be one of'],
        ];
    }

    public function test_ac_4_an_unresolvable_number_fails(): void
    {
        $cases = [
            'missing file' => [['file' => 'llm/none/run.json', 'path' => self::TTFT], 'was not found'],
            'missing path' => [['file' => self::RUN, 'path' => 'models.nope.speed'], 'was not found in'],
            'not a number' => [['file' => self::RUN, 'path' => 'models."qwen3.5:9b".speed'], 'is not a number'],
            'uncommitted raw results' => [['file' => 'raw/x.json', 'path' => 'a'], 'uncommitted'],
            'outside the results' => [['file' => '../../composer.json', 'path' => 'name'], 'was not found'],
        ];

        foreach ($cases as $name => [$value, $message]) {
            $lesson = self::set($this->lesson(), 'steps.1.kpis.ttft', $value);

            try {
                $this->service($lesson)->find('mod', 'one');
                $this->fail("{$name}: the lesson should not load.");
            } catch (InvalidLessonException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
    }

    public function test_ac_4_an_unresolvable_placeholder_in_text_fails(): void
    {
        $lesson = self::set($this->lesson(), 'steps.1.changed', [['sign' => '~', 'text' => 'ttft {num:llm/none/run.json#a.b|ms}']]);

        $this->expectException(InvalidLessonException::class);

        $this->service($lesson)->find('mod', 'one');
    }

    public function test_ac_4_a_lesson_that_resolves_reads_numbers_from_the_files(): void
    {
        $lesson = $this->lesson();
        $lesson = self::set($lesson, 'steps.1.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT, 'scale' => 0.001, 'round' => 3]);
        $lesson = self::set($lesson, 'steps.2.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT, 'scale' => 10, 'round' => 0]);
        $lesson = self::set($lesson, 'steps.1.changed', [['sign' => '~', 'text' => 'first token {num:'.self::RUN.'#'.self::TTFT.'|ms|0} now {kpi:ttft}']]);
        $lesson = self::set($lesson, 'steps.1.body', ['reads **{kpi:ttft}**']);

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame('not measured', $found->steps[0]->kpis[0]['value']);
        $this->assertSame('neutral', $found->steps[0]->kpis[0]['trend']);
        $this->assertSame('0.286 s', $found->steps[1]->kpis[0]['value']);
        $this->assertSame('~', $found->steps[1]->changed[0]['sign']);
        $this->assertSame('first token 286 ms now 0.286 s', $found->steps[1]->changed[0]['text']);
        $this->assertSame(['reads **0.286 s**'], $found->steps[1]->body);
        $this->assertSame('2,861 s', $found->steps[2]->kpis[0]['value']);
        $this->assertSame('worse', $found->steps[2]->kpis[0]['trend']);
        $this->assertSame('2,861 s', $found->steps[3]->kpis[0]['value'], 'a step that omits a kpi keeps the value');
    }

    public function test_ac_4_state_is_inherited_and_a_hidden_node_hides_its_edges(): void
    {
        $lesson = $this->lesson();
        $lesson = self::set($lesson, 'steps.1.state.nodes.a', ['load' => 60, 'status' => 'busy', 'badge' => 'hot']);
        $lesson = self::set($lesson, 'steps.2.state.nodes.b', ['status' => 'hidden']);

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame(['load' => 0, 'queue' => 0, 'status' => 'ok', 'badge' => null], $found->steps[0]->nodes['a']);
        $this->assertSame(['load' => 60, 'queue' => 0, 'status' => 'busy', 'badge' => 'hot'], $found->steps[3]->nodes['a']);
        $this->assertTrue($found->steps[1]->edges['e1']['visible']);
        $this->assertFalse($found->steps[2]->edges['e1']['visible']);
        $this->assertSame(['request' => 2], $found->steps[0]->edges['e1']['packets']);
    }

    public function test_ac_5_a_broken_course_fails(): void
    {
        $this->service($this->lesson());
        $cases = [
            'a lesson with no file' => [['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => [['slug' => 'ghost', 'title' => 'G', 'summary' => 's', 'level' => 'beginner']]]]], 'has no lesson file'],
            'a coming-soon lesson with a file' => [['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => [['slug' => 'one', 'title' => 'G', 'summary' => 's', 'level' => 'beginner', 'coming_soon' => true]]]]], 'coming soon but has a lesson file'],
            'a bad level' => [['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => [['slug' => 'one', 'title' => 'G', 'summary' => 's', 'level' => 'guru']]]]], '[level] must be one of'],
            'a repeated module' => [['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => []], ['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => []]]], 'listed twice'],
        ];

        foreach ($cases as $name => [$course, $message]) {
            $this->writeFile($this->directory.'/course.php', $course);

            try {
                $this->makeRepository()->course();
                $this->fail("{$name}: the course should not load.");
            } catch (InvalidLessonException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
    }

    public function test_ac_8_unknown_lessons_and_steps_are_not_found_and_a_broken_lesson_is_unavailable(): void
    {
        $this->service($this->lesson());
        $this->writeFile($this->directory.'/mod/broken.php', ['title' => 'Broken']);
        $this->writeFile($this->directory.'/course.php', ['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => [
            ['slug' => 'one', 'title' => 'One', 'summary' => 's', 'level' => 'beginner'],
            ['slug' => 'broken', 'title' => 'Broken', 'summary' => 's', 'level' => 'beginner'],
            ['slug' => 'later', 'title' => 'Later', 'summary' => 's', 'level' => 'advanced', 'coming_soon' => true],
        ]]]]);

        $service = $this->makeService();
        $lesson = $service->find('mod', 'one');

        $this->assertNotNull($lesson);
        $this->assertNull($service->find('mod', 'later'), 'a coming-soon lesson has no page');
        $this->assertNull($service->find('mod', 'nope'));
        $this->assertNull($service->find('nope', 'one'));
        $this->assertNull($service->find('..', 'one'));
        $this->assertNull($service->step($lesson, 0));
        $this->assertNull($service->step($lesson, 9));
        $this->assertSame(8, $service->step($lesson, 8)?->number);

        $entries = $service->course()->modules[0]->lessons;
        $this->assertTrue($entries[0]->available);
        $this->assertSame(8, $entries[0]->steps);
        $this->assertFalse($entries[1]->available);
        $this->assertTrue($entries[2]->comingSoon);
        $this->assertNull($entries[2]->steps);
    }

    public function test_ac_5_every_committed_lesson_loads(): void
    {
        $repository = new LessonRepository(resource_path('lessons'), new GuideRepository(resource_path('guides')));
        $files = glob(resource_path('lessons').'/*/*.php') ?: [];

        foreach ($files as $file) {
            $this->assertInstanceOf(LessonDefinition::class, $repository->find(basename(dirname($file)), basename($file, '.php')));
        }

        $service = app(LessonService::class);
        foreach ($repository->course()->modules as $module) {
            foreach ($module->lessons as $entry) {
                if (! $entry->comingSoon) {
                    $this->assertNotNull($service->find($module->slug, $entry->slug), "{$module->slug}/{$entry->slug}");
                }
            }
        }

        $this->assertNotEmpty($files);
    }

    public function test_the_repository_and_the_service_are_scoped_and_memoize_a_lesson_for_the_request(): void
    {
        $this->assertSame(app(LessonRepository::class), app(LessonRepository::class));
        $this->assertSame(app(LessonService::class), app(LessonService::class));

        $this->service($this->lesson());
        $repository = $this->makeRepository();
        $service = new LessonService($repository, $this->numbers());

        $this->assertSame($repository->find('mod', 'one'), $repository->find('mod', 'one'));
        $this->assertSame($service->find('mod', 'one'), $service->find('mod', 'one'));
    }

    public function test_f19_the_repository_only_loads_and_validates_and_the_service_resolves(): void
    {
        $lesson = self::set($this->lesson(), 'steps.1.kpis.ttft', ['file' => 'llm/none/run.json', 'path' => self::TTFT]);
        $lesson = self::set($lesson, 'steps.1.state.nodes.a', ['load' => 60]);
        $service = $this->service($lesson);

        $definition = $this->makeRepository()->find('mod', 'one');

        $this->assertSame(['nodes' => ['a' => ['load' => 60]], 'edges' => ['e2' => ['packets' => ['token' => 1]]]], $definition->steps[1]->state);
        $this->assertSame('llm/none/run.json', $definition->steps[1]->kpis['ttft']['file'] ?? null);

        $this->expectException(InvalidLessonException::class);
        $this->expectExceptionMessage('was not found');

        $service->find('mod', 'one');
    }

    public function test_f9_a_lesson_file_missing_from_the_course_is_not_playable(): void
    {
        $service = $this->service($this->lesson());
        $this->writeFile($this->directory.'/mod/draft.php', $this->lesson());

        $this->assertNotNull($service->find('mod', 'one'));
        $this->assertNull($service->find('mod', 'draft'));
    }

    public function test_f1_deeper_takes_the_guide_page_tab_keys_and_maps_the_old_names(): void
    {
        $lesson = self::set($this->lesson(), 'steps.1.deeper', 'local-models#failures');
        $lesson = self::set($lesson, 'steps.2.deeper', 'local-models#recipes');
        $lesson = self::set($lesson, 'steps.3.deeper', 'local-models#numbers');
        $lesson = self::set($lesson, 'steps.4.deeper', 'local-models#build');

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame('local-models#support', $found->steps[1]->deeper);
        $this->assertSame('local-models#modify', $found->steps[2]->deeper);
        $this->assertSame('local-models#numbers', $found->steps[3]->deeper);
        $this->assertSame('local-models#build', $found->steps[4]->deeper);
    }

    public function test_f7_the_callout_is_optional(): void
    {
        $lesson = $this->lesson();
        unset($lesson['steps'][1]['callout']);

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertNull($found->steps[1]->callout);
        $this->assertSame(['kind' => 'useful', 'text' => 'Note.'], $found->steps[0]->callout);
    }

    public function test_f11_numeric_looking_node_and_edge_ids_work_in_state_patches(): void
    {
        $lesson = self::set($this->lesson(), 'scene.nodes.1.id', '2');
        $lesson = self::set($lesson, 'scene.edges', [['id' => '1', 'from' => 'a', 'to' => '2'], ['id' => 'e2', 'from' => '2', 'to' => 'a']]);
        $lesson = self::set($lesson, 'steps.0.state', ['nodes' => ['2' => ['load' => 30]], 'edges' => ['1' => ['packets' => ['request' => 2]]]]);
        $lesson = self::set($lesson, 'steps.1.state', []);

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame(30, $found->steps[0]->nodes['2']['load']);
        $this->assertSame(['request' => 2], $found->steps[0]->edges['1']['packets']);
    }

    public function test_f5_a_kpi_marked_trend_false_is_never_coloured(): void
    {
        $lesson = self::set($this->lesson(), 'steps.1.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT]);
        $lesson = self::set($lesson, 'steps.2.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT, 'scale' => 10, 'trend' => false]);
        $lesson = self::set($lesson, 'steps.3.kpis.ttft', ['file' => self::RUN, 'path' => self::TTFT, 'scale' => 100]);

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame('neutral', $found->steps[2]->kpis[0]['trend'], 'a scenario switch is not better or worse');
        $this->assertSame('worse', $found->steps[3]->kpis[0]['trend']);
    }

    public function test_f2_kpis_are_required_on_step_1_and_inherited_later(): void
    {
        $missing = $this->lesson();
        unset($missing['steps'][0]['kpis']);

        try {
            $this->service($missing)->find('mod', 'one');
            $this->fail('Step 1 without kpis should not load.');
        } catch (InvalidLessonException $exception) {
            $this->assertStringContainsString('step 1 must give a value', $exception->getMessage());
        }

        $found = $this->service($this->lesson())->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertArrayNotHasKey('kpis', $this->lesson()['steps'][4]);
        $this->assertSame('not measured', $found->steps[4]->kpis[0]['value']);
    }

    public function test_ac_7_the_new_node_kinds_load_and_each_has_its_own_icon_and_readable_text(): void
    {
        $this->assertSame(['tools', 'person'], array_slice(LessonRepository::NODE_KINDS, 10));

        $lesson = $this->lesson();
        $lesson['scene']['nodes'][0]['kind'] = 'tools';
        $lesson['scene']['nodes'][1]['kind'] = 'person';

        $found = $this->service($lesson)->find('mod', 'one');

        $this->assertNotNull($found);
        $this->assertSame(['tools', 'person'], array_map(fn ($node): string => $node->kind, $found->nodes));

        $icons = (string) file_get_contents(resource_path('js/ai/components/lesson/SceneIcon.vue'));
        $drawn = [];

        foreach (LessonRepository::NODE_KINDS as $kind) {
            $this->assertStringContainsString("kind === '{$kind}'", $icons, "no icon for [{$kind}]");
            preg_match("#kind === '{$kind}'\">(.*?)</template>#s", $icons, $shape);
            $this->assertNotSame('', trim($shape[1] ?? ''));
            $this->assertNotContains($shape[1], $drawn, "[{$kind}] reuses another icon");
            $drawn[] = $shape[1];
        }

        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#1a1916', '#f5f2eb'));
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $channels = array_map(function (int $value): float {
                $c = $value / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, sscanf($hex, '#%02x%02x%02x'));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        return (max($luminance($a), $luminance($b)) + 0.05) / (min($luminance($a), $luminance($b)) + 0.05);
    }

    /**
     * A lesson of 8 steps: 3 outcomes, a kpi, two nodes and two edges.
     *
     * @return array<string, mixed>
     */
    private function lesson(): array
    {
        $steps = [];

        foreach (range(1, 8) as $number) {
            $steps[] = [
                'role' => $number === 8 ? 'summary' : 'problem',
                'title' => "Step {$number}",
                'body' => ['Text.'],
                'callout' => ['kind' => 'useful', 'text' => 'Note.'],
                'state' => [],
                ...($number === 1 ? ['kpis' => ['ttft' => 'not measured']] : []),
                'changed' => [],
                'learn' => match ($number) {
                    1 => ['lo-1'],
                    2 => ['lo-2'],
                    3 => ['lo-3'],
                    default => [],
                },
            ];
        }

        $steps[0]['state'] = ['edges' => ['e1' => ['packets' => ['request' => 2]]]];
        $steps[1]['state'] = ['edges' => ['e2' => ['packets' => ['token' => 1]]]];

        return [
            'title' => 'One',
            'summary' => 'A lesson.',
            'minutes' => 5,
            'level' => 'beginner',
            'learn' => [
                ['id' => 'lo-1', 'text' => 'First.'],
                ['id' => 'lo-2', 'text' => 'Second.'],
                ['id' => 'lo-3', 'text' => 'Third.'],
            ],
            'guide' => 'local-models',
            'scene' => [
                'nodes' => [
                    ['id' => 'a', 'kind' => 'browser', 'label' => 'A', 'x' => 100, 'y' => 100],
                    ['id' => 'b', 'kind' => 'model', 'label' => 'B', 'x' => 600, 'y' => 300],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'a', 'to' => 'b'],
                    ['id' => 'e2', 'from' => 'b', 'to' => 'a'],
                ],
            ],
            'kpis' => [['id' => 'ttft', 'label' => 'First token', 'unit' => '', 'format' => 's', 'better' => 'lower']],
            'steps' => $steps,
        ];
    }

    /**
     * Writes the lesson as mod/one, listed in a course of its own, and returns a fresh service.
     *
     * @param  array<string, mixed>  $lesson
     */
    private function service(array $lesson): LessonService
    {
        $this->writeFile($this->directory.'/mod/one.php', $lesson);

        if (! is_file($this->directory.'/course.php')) {
            $this->writeFile($this->directory.'/course.php', ['modules' => [['slug' => 'mod', 'title' => 'M', 'summary' => 's', 'lessons' => [
                ['slug' => 'one', 'title' => 'One', 'summary' => 's', 'level' => 'beginner'],
            ]]]]);
        }

        return $this->makeService();
    }

    private function makeService(): LessonService
    {
        return new LessonService($this->makeRepository(), $this->numbers());
    }

    private function makeRepository(): LessonRepository
    {
        return new LessonRepository($this->directory, new GuideRepository(resource_path('guides')));
    }

    private function numbers(): LessonNumbers
    {
        return new LessonNumbers(new GuideFacts(base_path('bench/results')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeFile(string $path, array $data): void
    {
        file_put_contents($path, '<?php return '.var_export($data, true).';');
    }

    /**
     * Sets a dotted path in a nested array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function set(array $data, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $cursor = &$data;

        foreach ($keys as $key) {
            $cursor = &$cursor[$key];
        }

        $cursor[$last] = $value;

        return $data;
    }
}
