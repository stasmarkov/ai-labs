<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Prompts',
    'summary' => 'The instructions each agent follows live in versioned files, so the wording of an answer is data you can change and compare.',
    'works' => [
        [
            'title' => 'One file per prompt and version',
            'body' => 'Each prompt is a plain text file whose name carries its version. The old version stays in the repository, so a change never rewrites history.',
            'snippet' => 'resources/prompts/car-description.v1.md',
        ],
        [
            'title' => 'A setting names the version in use',
            'body' => 'One entry per agent says which file it reads. Switching a version is an environment change, not a code change.',
            'snippet' => 'config/ai.php#prompts',
        ],
        [
            'title' => 'The agent reads its file',
            'body' => 'The agent loads the file as its instructions and derives the label `name@version` from the file name.',
            'snippet' => 'App\Ai\CarDescriptionAgent::promptVersion',
        ],
        [
            'title' => 'The version is logged with the run',
            'body' => 'The run row stores the label, so two rows made with different wording can be compared later.',
            'snippet' => 'App\Ai\AiRunRecorder::started',
        ],
    ],
    'failures' => [
        [
            'title' => 'A prompt edit changes old results',
            'body' => "**Cause:** the existing file was edited in place, so old rows no longer match the text they name.\n\n**Fix:** copy to a new version, edit the copy and point the setting at it.",
        ],
        [
            'title' => 'The app fails with a missing prompt file',
            'body' => "**Cause:** the setting names a version whose file does not exist.\n\n**Fix:** check the name against the list of prompt files; the error names the missing one.",
            'snippet' => 'config/ai.php#prompts',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Try a new wording',
            'body' => "Copy the prompt file to the next version, edit the copy and set the matching environment value. Restart the app.\n\n**Check:** the next run row shows the new version label.",
            'snippet' => 'config/ai.php#prompts',
        ],
        [
            'title' => 'Compare prompts on another model',
            'body' => 'Change the model first, then the prompt, one at a time, and read the run rows side by side so you know which change moved the result.',
            'snippet' => 'config/ai.php#provider-ollama',
        ],
    ],
    'support' => [
        [
            'name' => 'Prompt version',
            'status' => 'logged',
            'note' => 'Every run row stores `name@version` of the prompt it used.',
        ],
        [
            'name' => 'Prompt files',
            'status' => 'guarded',
            'note' => 'Tests read the real prompt files, so a renamed or missing file fails a test before it reaches a user.',
        ],
        [
            'name' => 'An answer got worse after a change',
            'status' => 'runbook',
            'note' => "1. Open the runs before and after the change and compare the prompt label.\n2. Point the setting back at the old version.\n3. Rerun the same question and compare.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Answer quality per prompt version',
            'pending' => true,
        ],
    ],
    'experiments' => [
        [
            'title' => 'Run a description',
            'predictions' => [
                ['id' => 'a', 'text' => 'The run row has no prompt information.'],
                ['id' => 'b', 'text' => 'The run row names the prompt version that wrote the text.'],
                ['id' => 'c', 'text' => 'The run row stores the full prompt text only.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'The run row names the prompt version that wrote the text.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['providers', 'run-log'],
];
