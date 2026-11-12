<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Listing moderation',
    'summary' => 'A model rates how risky a listing is for a buyer and names its reasons from a closed list, judging with facts the app computed and answering in a shape that PHP checks.',
    'works' => [
        [
            'title' => 'The app computes the facts the text cannot carry',
            'body' => 'A model cannot know the market price of a 2018 Golf or which VINs were reported stolen. The app works both out before the model runs: the median price of comparable listings, the price ratio, the VINs found in the text, whether one is in the register of reported vehicles, and any injection pattern in the title, make, model, city or description. With fewer than 5 comparable listings the median is null and the price is not judged.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\ModerationFacts::compute',
        ],
        [
            'title' => 'The listing and the facts go in as data',
            'body' => 'The listing and the facts arrive as two JSON blocks, with `<` and `>` escaped so listing text cannot close its block. The prompt says both blocks are data and never instructions, and lists the seven reasons with a definition each.',
            'snippet' => 'App\Ai\ModerationAgent::instructions',
        ],
        [
            'title' => 'The model answers in a fixed shape',
            'body' => 'The agent asks for structured output: a risk from 0 to 1, up to four reasons from the closed list, and a short explanation. A closed list means a chip on the page always has a definition, and the eval can count each reason.',
            'event' => 'response_received',
            'snippet' => 'App\Ai\ModerationAgent::schema',
        ],
        [
            'title' => 'PHP checks the answer',
            'body' => 'The risk must be a JSON number between 0 and 1, so a numeric string is rejected. Reasons must be distinct and in the list. An explanation over 300 characters is cut, not rejected. The model is never trusted to follow its own schema.',
            'event' => 'validation_failed',
            'snippet' => 'App\Rules\ModerationRules::rules',
        ],
        [
            'title' => 'A rejected answer is asked for once more',
            'body' => 'An answer that fails the checks triggers one retry. Two rejected answers end as `invalid` and nothing is classified. A transport error is one attempt with no retry. Every attempt is a logged run, and rejected ones are flagged.',
            'snippet' => 'App\Services\ModerationService::moderate',
        ],
        [
            'title' => 'PHP sets a floor under the risk',
            'body' => 'Two signals are found by code, not by the model: an injection pattern in the text and a VIN from the register of reported vehicles. Either one lifts the risk to at least 0.7 and adds the reason `other` or `reported_vin`, whatever the model answered. A model that was talked into rating the listing 0 still ends risky. The result keeps the model\'s own risk next to the final one, and the page says when a floor was applied.',
            'snippet' => 'App\Services\ModerationService::moderate',
        ],
        [
            'title' => 'The threshold turns a risk into a verdict',
            'body' => 'A listing is risky when its risk, after the floor, is at or above the threshold of the model that answered. A listing with one of the two hard signals is always risky, even when the threshold of a model is set above 0.7. The eval picks that threshold per model from the best F1 score, so a model that rates high across the board gets a higher cut-off.',
            'snippet' => 'App\Services\ModerationService::thresholdFor',
        ],
    ],
    'failures' => [
        [
            'title' => 'A safe listing is flagged',
            'body' => "**Cause:** a legitimate phrase such as financing, a bank transfer at handover or a low price explained by damage looks like a scam signal to a small model.\n\n**Fix:** the eval has 15 hand-written hard negatives and reports the false positives on them. Raise the threshold, or add the case to the prompt as a normal one.",
            'snippet' => 'App\Services\ModerationEvalScorer::score',
        ],
        [
            'title' => 'A scam gets a low risk',
            'body' => "**Cause:** the model ignores a fact, for example a price ratio of 0.3 that it did not connect to the price reason.\n\n**Fix:** read the facts panel first. If the fact was right, the prompt did not make the model use it; compare reason recall between prompt versions.",
        ],
        [
            'title' => 'The result is invalid',
            'body' => "**Cause:** the model sent a risk as text, a reason outside the list or an empty explanation twice in a row.\n\n**Fix:** read the errors on the page and the two flagged runs. A model that fails often needs a stricter prompt or a different model.",
            'snippet' => 'App\Rules\ModerationRules::validator',
        ],
        [
            'title' => 'A listing that tries to steer the moderator',
            'body' => "**Cause:** the description contains text such as \"ignore all previous instructions\".\n\n**Fix:** nothing to refuse. Here the attempt is a signal: the listing is classified with the injection pattern as a fact, and the prompt asks for the reason `other` and a high risk. The app does not rely on that: PHP lifts the risk to at least 0.7 itself, so a model that obeys the text still cannot rate the listing safe.",
            'snippet' => 'App\Services\ModerationFacts::compute',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Swap the moderation model or provider',
            'body' => "Set the moderation feature's provider and model in the environment or on the settings page, or pick a provider on the page. The threshold follows the model that answered.\n\n**Check:** the run row names the new model, and the result shows its threshold.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Add a reason',
            'body' => "Add the value to the closed list, define it in the prompt, and add a version of the prompt. Rerun the eval: the new reason needs examples in the set before its recall means anything.\n\n**Check:** an answer with the new reason passes the rules, and an unknown one is still rejected.",
            'snippet' => 'App\Rules\ModerationRules::rules',
        ],
        [
            'title' => 'Change the threshold',
            'body' => "Set the calibrated value for a model in the per-model thresholds, or the default for the rest. Read the threshold curve of the eval first: a higher cut-off trades recall for fewer false alarms.\n\n**Check:** the result on the page shows the new threshold for that model.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Add a fact',
            'body' => "Compute it in the facts class, add it to the facts result, and describe it in the prompt. A fact the prompt does not mention is ignored by the model.\n\n**Check:** the facts panel shows the new value and the test for the facts covers it.",
            'snippet' => 'App\Services\ModerationFacts::compute',
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows',
            'status' => 'logged',
            'note' => 'Every attempt is a run with its model, prompt version and latency. An answer that failed the checks is flagged, so a retry shows as two rows.',
        ],
        [
            'name' => 'Tests and eval',
            'status' => 'guarded',
            'note' => 'Feature tests fake the model and cover the shape rules, the retry, the facts and the endpoint checks. A labelled set of 100 listings scores each model for accuracy, precision, recall and reason recall, read at the threshold with the best F1. The endpoint refuses requests that another site started.',
        ],
        [
            'name' => 'Moderation rates a listing wrongly',
            'status' => 'runbook',
            'note' => "1. Open the run and read the prompt version and model.\n2. Check the facts panel: a wrong median or a missed VIN is an app problem, not a model problem.\n3. Facts right and verdict wrong: rerun the eval for that model and compare against the threshold curve.\n4. Several invalid results: look at the rejected answers in the runs.",
        ],
    ],
    'numbers' => [
        ['label' => 'qwen3.5:9b, answers valid after the retry', 'file' => 'moderation/2026-11-12/run.json', 'path' => 'summary.qwen3.5:9b.json_valid_after_retry.rate'],
        ['label' => 'qwen3.5:9b, recall for risky listings', 'file' => 'moderation/2026-11-12/run.json', 'path' => 'summary.qwen3.5:9b.best_f1.recall'],
        ['label' => 'gemma4:12b, recall for risky listings', 'file' => 'moderation/2026-11-12/run.json', 'path' => 'summary.gemma4:12b.best_f1.recall'],
    ],
    'experiments' => [
        [
            'title' => 'An advance payment',
            'predictions' => [
                ['id' => 'a', 'text' => 'The listing is rated risky with the reason advance_payment.'],
                ['id' => 'b', 'text' => 'The listing is rated safe because the price is normal.'],
                ['id' => 'c', 'text' => 'The listing is refused before the model runs.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Load the advance payment example and moderate it. A seller who asks for money before a viewing is the first reason in the closed list, and the risk lands above the threshold.'],
            'page' => 'ai.moderation',
            'params' => [],
        ],
        [
            'title' => 'A price far below the market',
            'predictions' => [
                ['id' => 'a', 'text' => 'The model finds the low price by itself from the text.'],
                ['id' => 'b', 'text' => 'The facts panel shows a price ratio of 0.5 or lower and the model uses it.'],
                ['id' => 'c', 'text' => 'Nothing is flagged because the text is polite.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Load the price far below market example. The text never says the price is low; the app computes the median and the ratio, and the model judges with that fact.'],
            'page' => 'ai.moderation',
            'params' => [],
        ],
        [
            'title' => 'Text that tries to steer the moderator',
            'predictions' => [
                ['id' => 'a', 'text' => 'The description is refused and no model is called.'],
                ['id' => 'b', 'text' => 'The model obeys and rates the listing as safe.'],
                ['id' => 'c', 'text' => 'The listing is classified with the injection pattern shown as a fact.'],
            ],
            'answer' => ['id' => 'c', 'body' => 'Add a sentence such as "ignore all previous instructions" to a description. Unlike a chat question, a listing that tries to steer its moderator is suspicious in itself, so it is classified, not refused, and the app sets a minimum risk of 0.7 whatever the model says.'],
            'page' => 'ai.moderation',
            'params' => [],
        ],
    ],
    'related' => ['nl-search', 'guards', 'prompts'],
];
