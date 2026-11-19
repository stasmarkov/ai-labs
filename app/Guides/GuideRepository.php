<?php

declare(strict_types=1);

namespace App\Guides;

use App\DTOs\Guide;
use App\DTOs\GuideBuildStep;
use App\DTOs\GuideElsewhere;
use App\DTOs\GuideExperiment;
use App\DTOs\GuideItem;
use App\DTOs\GuideNumber;
use App\DTOs\GuideRecipe;
use App\DTOs\GuideStep;
use App\DTOs\GuideSupport;
use App\DTOs\GuideWatchPoint;

/**
 * Loads and validates the guide files. Bound per request (scoped), so a page that asks for the same guide twice parses it once.
 */
final class GuideRepository
{
    /** The guide kinds; capability guides back a live lab page, a planned guide only explains what is coming. */
    public const KINDS = ['capability', 'foundation', 'planned'];

    /** The guide tab each teach row can link on to, keyed by the name a guide file uses. */
    private const TAB_KEYS = ['recipes' => 'modify', 'failures' => 'support', 'support' => 'support'];

    /** How many rows each teach section holds when a guide has it. */
    private const BUILD_ROWS = [4, 8];

    private const WATCH_ROWS = [3, 6];

    private const ELSEWHERE_ROWS = [2, 3];

    /** A guide that teaches a lab page shows every works step there, so it holds at most this many. */
    public const TEACH_WORKS_MAX = 6;

    /** The hosts a build row may link to for public docs. */
    private const DOC_HOSTS = ['laravel.com', 'frankenphp.dev', 'ollama.com', 'github.com'];

    /** @var array<string, Guide> */
    private array $loaded = [];

    public function __construct(private readonly string $directory) {}

    public function has(string $key): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $key) === 1 && is_file($this->path($key));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_map(fn (string $file): string => basename($file, '.php'), glob($this->directory.'/*.php') ?: []);
        sort($keys);

        return $keys;
    }

    public function find(string $key): Guide
    {
        return $this->loaded[$key] ??= $this->load($key);
    }

    private function load(string $key): Guide
    {
        if (! $this->has($key)) {
            throw new InvalidGuideException("Unknown guide [{$key}].");
        }

        $path = $this->path($key);
        $data = require $path;

        if (! is_array($data)) {
            throw new InvalidGuideException("Guide [{$key}] must return an array.");
        }

        return $this->build($key, $data, (string) sha1_file($path));
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.$key.'.php';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function build(string $key, array $data, string $hash): Guide
    {
        $kind = $this->string($key, $data, 'kind');

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidGuideException("Guide [{$key}] has unknown kind [{$kind}].");
        }

        $planned = $kind === 'planned';

        $guide = new Guide(
            key: $key,
            title: $this->string($key, $data, 'title'),
            summary: $this->string($key, $data, 'summary'),
            kind: $kind,
            planned: $planned,
            works: $this->rows($key, $data, 'works', fn (array $row): GuideStep => $this->step($key, $row)),
            failures: $this->rows($key, $data, 'failures', fn (array $row): GuideItem => new GuideItem(
                $this->string($key, $row, 'title'),
                $this->string($key, $row, 'body'),
                $this->optional($row, 'snippet'),
            )),
            recipes: $this->rows($key, $data, 'recipes', fn (array $row): GuideRecipe => new GuideRecipe(
                $this->string($key, $row, 'title'),
                $this->string($key, $row, 'body'),
                $this->optional($row, 'snippet'),
            )),
            support: $this->rows($key, $data, 'support', fn (array $row): GuideSupport => new GuideSupport(
                $this->string($key, $row, 'name'),
                $this->string($key, $row, 'status'),
                $this->string($key, $row, 'note'),
            )),
            numbers: $this->rows($key, $data, 'numbers', function (array $row) use ($key): GuideNumber {
                $pending = (bool) ($row['pending'] ?? false);
                if (! $pending && (! isset($row['file'], $row['path']))) {
                    throw new InvalidGuideException("Guide [{$key}] number needs file and path unless pending.");
                }

                return new GuideNumber(
                    $this->string($key, $row, 'label'),
                    $this->optional($row, 'file'),
                    $this->optional($row, 'path'),
                    $pending,
                    $this->optional($row, 'unit'),
                );
            }),
            experiments: $this->rows($key, $data, 'experiments', fn (array $row): GuideExperiment => $this->experiment($key, $row)),
            build: $this->counted($key, $data, 'build', self::BUILD_ROWS, fn (array $row): GuideBuildStep => new GuideBuildStep(
                $this->string($key, $row, 'step'),
                $this->string($key, $row, 'detail'),
                $this->tab($key, $row, ['recipes', 'support'], false),
                $this->doc($key, $row),
            )),
            watch: $this->counted($key, $data, 'watch', self::WATCH_ROWS, fn (array $row): GuideWatchPoint => new GuideWatchPoint(
                $this->string($key, $row, 'point'),
                $this->string($key, $row, 'detail'),
                (string) $this->tab($key, $row, ['failures', 'support'], true),
            )),
            elsewhere: $this->counted($key, $data, 'elsewhere', self::ELSEWHERE_ROWS, fn (array $row): GuideElsewhere => new GuideElsewhere(
                $this->string($key, $row, 'domain'),
                $this->string($key, $row, 'problem'),
                $this->string($key, $row, 'how'),
            )),
            plan: $this->plan($key, $data, $planned),
            related: array_values(array_map('strval', is_array($data['related'] ?? null) ? $data['related'] : [])),
            lessons: $this->lessons($key, $data),
            contentHash: $hash,
        );

        $this->assertTeachable($guide);

        return $guide;
    }

    /**
     * A guide with teach sections feeds a lab page: every works step is shown there, so there may be no more than six,
     * and every build or watch row must link to a tab the guide page really shows.
     */
    private function assertTeachable(Guide $guide): void
    {
        if ($guide->build === [] && $guide->watch === [] && $guide->elsewhere === []) {
            return;
        }

        if (count($guide->works) > self::TEACH_WORKS_MAX) {
            throw new InvalidGuideException("Guide [{$guide->key}] teaches a lab page and may hold at most ".self::TEACH_WORKS_MAX.' works steps, not '.count($guide->works).'; merge steps instead.');
        }

        $tabs = [
            ...array_map(fn (GuideBuildStep $row): ?string => $row->tab, $guide->build),
            ...array_map(fn (GuideWatchPoint $row): string => $row->tab, $guide->watch),
        ];

        foreach (array_unique(array_filter($tabs, fn (?string $tab): bool => $tab !== null)) as $tab) {
            $shown = match ($tab) {
                'modify' => $guide->recipes !== [],
                default => $guide->failures !== [] || $guide->support !== [],
            };

            if (! $shown) {
                throw new InvalidGuideException("Guide [{$guide->key}] links a teach row to the [{$tab}] tab, which the guide page does not show.");
            }
        }
    }

    /**
     * A works step; its snippet is one reference or a list of them when the step covers more than one piece of code.
     *
     * @param  array<string, mixed>  $row
     */
    private function step(string $key, array $row): GuideStep
    {
        $snippets = $row['snippet'] ?? null;

        if (is_array($snippets)) {
            $references = array_values(array_filter($snippets, fn (mixed $reference): bool => is_string($reference) && $reference !== ''));

            if ($references === [] || count($references) !== count($snippets)) {
                throw new InvalidGuideException("Guide [{$key}] step snippet list must hold non-empty strings only.");
            }
        } else {
            $single = $this->optional($row, 'snippet');
            $references = $single === null ? [] : [$single];
        }

        return new GuideStep(
            $this->string($key, $row, 'title'),
            $this->string($key, $row, 'body'),
            $this->optional($row, 'event'),
            $references[0] ?? null,
            array_slice($references, 1),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function plan(string $key, array $data, bool $planned): array
    {
        $plan = $data['plan'] ?? [];

        if (! is_array($plan)) {
            throw new InvalidGuideException("Guide [{$key}] field [plan] must be a list.");
        }

        $bullets = [];
        foreach ($plan as $bullet) {
            if (! is_string($bullet) || trim($bullet) === '') {
                throw new InvalidGuideException("Guide [{$key}] field [plan] holds a bullet that is not a non-empty string.");
            }
            $bullets[] = $bullet;
        }

        if ($planned && $bullets === []) {
            throw new InvalidGuideException("Guide [{$key}] is planned and needs at least one [plan] bullet.");
        }

        return $bullets;
    }

    /**
     * The lessons a guide links to, each written <module>/<lesson>. Only the shape is checked here: the guide page asks
     * the course which of them are playable, so guides never read lesson files.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function lessons(string $key, array $data): array
    {
        $lessons = $data['lessons'] ?? [];

        if (! is_array($lessons)) {
            throw new InvalidGuideException("Guide [{$key}] field [lessons] must be a list.");
        }

        $keys = [];
        foreach ($lessons as $lesson) {
            if (! is_string($lesson) || preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $lesson) !== 1) {
                throw new InvalidGuideException("Guide [{$key}] field [lessons] holds an entry that is not <module>/<lesson>.");
            }

            $keys[] = $lesson;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function experiment(string $key, array $row): GuideExperiment
    {
        $predictions = $this->rows($key, $row, 'predictions', fn (array $prediction): array => [
            'id' => $this->string($key, $prediction, 'id'),
            'text' => $this->string($key, $prediction, 'text'),
        ]);
        $ids = array_column($predictions, 'id');

        if (count($predictions) < 2 || count($predictions) > 4 || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidGuideException("Guide [{$key}] experiment needs 2 to 4 predictions with distinct ids.");
        }

        $answer = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $answerId = $this->string($key, $answer, 'id');

        if (! in_array($answerId, $ids, true)) {
            throw new InvalidGuideException("Guide [{$key}] experiment answer [{$answerId}] is not one of its predictions.");
        }

        $numbers = $this->optional($answer, 'numbers');

        return new GuideExperiment(
            $this->string($key, $row, 'title'),
            $predictions,
            ['id' => $answerId, 'body' => $this->string($key, $answer, 'body')] + ($numbers === null ? [] : ['numbers' => $numbers]),
            $this->string($key, $row, 'page'),
            array_map('strval', is_array($row['params'] ?? null) ? $row['params'] : []),
        );
    }

    /**
     * A teach section: absent is fine, present must hold a number of rows inside the range.
     *
     * @template T
     *
     * @param  array<string, mixed>  $data
     * @param  array{int, int}  $range
     * @param  callable(array<string, mixed>): T  $make
     * @return list<T>
     */
    private function counted(string $key, array $data, string $field, array $range, callable $make): array
    {
        $rows = $this->rows($key, $data, $field, $make);

        if ($rows !== [] && (count($rows) < $range[0] || count($rows) > $range[1])) {
            throw new InvalidGuideException("Guide [{$key}] field [{$field}] needs {$range[0]} to {$range[1]} rows.");
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $allowed
     */
    private function tab(string $key, array $row, array $allowed, bool $required): ?string
    {
        $tab = $this->optional($row, 'tab');

        if ($tab === null && ! $required) {
            return null;
        }

        if ($tab === null || ! in_array($tab, $allowed, true)) {
            throw new InvalidGuideException("Guide [{$key}] row tab must be one of [".implode(', ', $allowed).'].');
        }

        return self::TAB_KEYS[$tab];
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): T  $make
     * @return list<T>
     */
    private function rows(string $key, array $data, string $field, callable $make): array
    {
        $rows = $data[$field] ?? [];

        if (! is_array($rows)) {
            throw new InvalidGuideException("Guide [{$key}] field [{$field}] must be a list.");
        }

        $built = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidGuideException("Guide [{$key}] field [{$field}] holds a row that is not an array.");
            }
            $built[] = $make($row);
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(string $key, array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidGuideException("Guide [{$key}] needs a non-empty [{$field}].");
        }

        return $value;
    }

    /**
     * A public docs link must be https on an allowed host, so it is safe to bind as an href.
     *
     * @param  array<string, mixed>  $row
     */
    private function doc(string $key, array $row): ?string
    {
        $doc = $this->optional($row, 'doc');

        if ($doc !== null && (parse_url($doc, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($doc, PHP_URL_HOST), self::DOC_HOSTS, true))) {
            throw new InvalidGuideException("Guide [{$key}] build doc must be an https link on an allowed host.");
        }

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optional(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
