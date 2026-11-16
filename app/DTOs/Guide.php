<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class Guide
{
    /**
     * @param  list<GuideStep>  $works
     * @param  list<GuideItem>  $failures
     * @param  list<GuideRecipe>  $recipes
     * @param  list<GuideSupport>  $support
     * @param  list<GuideNumber>  $numbers
     * @param  list<GuideExperiment>  $experiments
     * @param  list<GuideBuildStep>  $build
     * @param  list<GuideWatchPoint>  $watch
     * @param  list<GuideElsewhere>  $elsewhere
     * @param  list<string>  $plan
     * @param  list<string>  $related
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $summary,
        public string $kind,
        public bool $planned,
        public array $works,
        public array $failures,
        public array $recipes,
        public array $support,
        public array $numbers,
        public array $experiments,
        public array $build,
        public array $watch,
        public array $elsewhere,
        public array $plan,
        public array $related,
        public string $contentHash,
    ) {}

    /**
     * @return list<string>
     */
    public function snippetReferences(): array
    {
        $references = [];

        foreach ([...$this->works, ...$this->failures, ...$this->recipes] as $section) {
            if ($section->snippet !== null) {
                $references[] = $section->snippet;
            }
        }

        foreach ($this->works as $step) {
            array_push($references, ...$step->moreSnippets);
        }

        return $references;
    }
}
