<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Multi-step research agent',
    'summary' => 'A topic becomes a cited report in fixed phases: a planner splits it into sub-questions, each one is searched in the docs and noted, and a report agent writes from the notes. Every phase is stored, so a run can be shown again with no model call.',
    'works' => [
        [
            'title' => 'The topic is checked before anything runs',
            'body' => 'The topic must be 3 to 300 characters and is matched against the same injection patterns as the docs question. A match stores the run as `refused`, logs one refused row and calls no model. The refusal is a normal result with a reason, not an error.',
            'event' => 'refused',
            'snippet' => 'App\Services\ResearchRunner::start',
        ],
        [
            'title' => 'The planner splits the topic into sub-questions',
            'body' => 'The first agent returns 2 to 5 sub-questions with structured output. PHP checks the answer: each question is 5 to 200 characters, duplicates are dropped and at most 5 are kept. An invalid plan is asked for once more; a second invalid plan ends the run as `failed` with the plan error.',
            'event' => 'validation_failed',
            'snippet' => 'App\Services\ResearchRunner::plan',
        ],
        [
            'title' => 'Search is code, not a model decision',
            'body' => 'For each sub-question the runner calls the docs hybrid search (keyword and vector together) and keeps the first 5 chunks. The model does not choose when to search or what to call: the phases and their order are fixed, which is what makes the run bounded and repeatable. A search is stored as a step with the chunk ids it found.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\ResearchRunner::searchPhase',
        ],
        [
            'title' => 'A note agent reads chunks as data',
            'body' => 'The note agent gets the sub-question and the chunks, each wrapped as `<chunk id="...">` data of at most 1200 characters, and returns facts with the chunk ids each one relies on. PHP drops any id the search did not return, and a fact left with no valid id is dropped, so the model cannot cite a source it was not given.',
            'event' => 'response_received',
            'snippet' => 'App\Services\ResearchRunner::notePhase',
        ],
        [
            'title' => 'Notes wait in Redis, then move to the steps',
            'body' => 'While the run goes, each note is kept in a Redis hash for the run with a 24 hour life, and the report phase reads them back from there. When the run ends the notes are already in the stored note steps, so the page and a replay never need Redis.',
            'snippet' => 'App\Support\RedisResearchNotesStore',
        ],
        [
            'title' => 'The report cites sources, and only sources',
            'body' => 'The report agent writes markdown from the numbered notes and sources. Afterwards PHP removes every `[n]` marker that points at no source. With no fact at all the report is a fixed sentence, "the documentation does not cover this topic", and no model is called for it.',
            'event' => 'citations',
            'snippet' => 'App\Ai\ResearchCitations',
        ],
        [
            'title' => 'The caps stop the run, not the model',
            'body' => 'A run makes at most 8 model calls (2 plan attempts, 5 notes, 1 report) and stores at most 13 steps. The runner checks both before it starts the notes and before every call; a run that would go over ends as `failed` with `cap_reached`, after storing what it had. A run also has a time budget (10 minutes by default): a call starts only when it can end inside it, otherwise the run ends `failed` with `deadline_reached` and keeps its steps. Every call is an `ai_runs` row.',
            'snippet' => 'App\Services\ResearchRunner::guardCaps',
        ],
    ],
    'failures' => [
        [
            'title' => 'The report says the docs do not cover the topic',
            'body' => "**Cause:** no search returned a chunk the note agent could use, so there were no facts to report.\n\n**Fix:** read the search steps: if they found nothing, the docs really lack the topic or the sub-questions were too vague. Rephrase the topic with the words the docs use.",
            'snippet' => 'App\Services\ResearchRunner::reportPhase',
        ],
        [
            'title' => 'The run failed with an invalid plan',
            'body' => "**Cause:** the planner answered twice with fewer than 2 usable sub-questions, a sub-question outside 5 to 200 characters or text instead of the shape.\n\n**Fix:** open the two plan attempts in the timeline and read what came back. A small model that fails often needs a stricter prompt or another model.",
            'snippet' => 'App\Services\ResearchRunner::validPlan',
        ],
        [
            'title' => 'The run failed with cap_reached',
            'body' => "**Cause:** the plan needed more model calls or steps than the caps allow, for example after a plan retry with 5 sub-questions.\n\n**Fix:** nothing is wrong with the model. Narrow the topic so the plan has fewer sub-questions, or raise the caps in the environment and accept the cost.",
            'snippet' => 'App\Services\ResearchRunner::guardCaps',
        ],
        [
            'title' => 'A report cites a source that says something else',
            'body' => "**Cause:** the checks prove that a marker points at a real source, not that the source supports the sentence.\n\n**Fix:** open the source and read the chunk. Citation checking is a floor; the support row says what is and is not verified.",
            'snippet' => 'App\Ai\ResearchCitations',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Swap the research model or provider',
            'body' => "Set the research feature's provider and model in the environment or on the settings page, or pass `--provider` to the command. All three agents run on that one choice, and a cloud provider answers 429 when its per-minute cloud limit is used: a run counts as many hits as it may make model calls.\n\n**Check:** the run row and its `ai_runs` rows name the new model.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Run a research from the terminal',
            'body' => "```sh\nddev artisan agent:research \"How does the docs answer cache work?\"\n```\n\nAdd `--json` for the whole run as JSON. The exit code is 0 for `done`, 1 for `failed` or `refused`, and 2 for a bad topic or provider.\n\n**Check:** the command prints the plan, the notes and the report with its sources.",
            'snippet' => 'App\Console\Commands\AgentResearchCommand::handle',
        ],
        [
            'title' => 'Replay a stored run',
            'body' => "```sh\nddev artisan agent:research --replay=<run id>\n```\n\nA replay reads the stored steps and prints them exactly as they were. It calls no model, no search and no HTTP, so it costs nothing and gives the same text every time. The page does the same when you open a stored run.\n\n**Check:** the output is identical to the first run, byte for byte.",
            'snippet' => 'App\Console\Commands\AgentResearchCommand::replay',
        ],
        [
            'title' => 'Change a cap or a prompt',
            'body' => "Set `AI_RESEARCH_MAX_MODEL_CALLS`, `AI_RESEARCH_MAX_STEPS`, `AI_RESEARCH_CHUNKS_PER_NOTE` or `AI_RESEARCH_CHUNK_CHARACTERS` in the environment. To change a prompt, add a new version file and point `AI_PROMPT_RESEARCH_PLANNER`, `AI_PROMPT_RESEARCH_NOTE` or `AI_PROMPT_RESEARCH_REPORT` at it, so the old runs still name the version they used.\n\n**Check:** a new run stores the new prompt version.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
    ],
    'support' => [
        [
            'name' => 'Runs, steps and model calls',
            'status' => 'logged',
            'note' => 'Every run is a row with its topic, provider, model, prompt versions, sub-questions, report, sources and status. Every phase is a step with its input, output and duration, and every model call is an `ai_runs` row linked to its step.',
        ],
        [
            'name' => 'Input, output and cost',
            'status' => 'guarded',
            'note' => 'The topic guard refuses an injection before any call. Chunks reach the model as marked data, chunk ids and report citations are checked in PHP, the caps bound the calls and the steps, and the report is rendered with raw HTML escaped, images replaced by their alt text and links reduced to their text, so the model cannot add HTML, load an image or send the reader elsewhere. The endpoint refuses cross-site requests and allows 5 runs a minute per IP. On a cloud provider a run counts as 8 hits on the cloud limit (20 a minute per IP), because it may make 8 model calls.',
        ],
        [
            'name' => 'A run is wrong or stuck',
            'status' => 'runbook',
            'note' => "1. Open the run and read its status and error.\n2. A wrong report: read the notes, then the search steps; a bad note after a good search is a prompt problem, a bad search is a docs or query problem.\n3. A run has a time budget of 10 minutes: a model call starts only when it can end inside it, otherwise the run ends `failed` with `deadline_reached` and keeps its steps. A run still `running` after that is shown as failed; start it again.\n4. Compare two models by replaying nothing and running the same topic on each.",
        ],
    ],
    'numbers' => [
        ['label' => 'qwen3.5:9b, runs recorded', 'file' => 'research/2026-11-14/stats.json', 'path' => 'summary.runs'],
        ['label' => 'qwen3.5:9b, runs that ended done, share of the runs that executed', 'unit' => '%', 'file' => 'research/2026-11-14/stats.json', 'path' => 'summary.done_percent'],
        ['label' => 'qwen3.5:9b, model calls per run, average', 'file' => 'research/2026-11-14/stats.json', 'path' => 'summary.average_model_calls'],
        ['label' => 'qwen3.5:9b, model calls in the largest run (the cap is 8)', 'file' => 'research/2026-11-14/stats.json', 'path' => 'summary.max_model_calls'],
        ['label' => 'qwen3.5:9b, run time, average', 'unit' => 's', 'file' => 'research/2026-11-14/stats.json', 'path' => 'summary.average_duration_s'],
    ],
    'experiments' => [
        [
            'title' => 'A topic the docs cover',
            'predictions' => [
                ['id' => 'a', 'text' => 'The report has cited sources and the timeline shows a search and a note for each sub-question.'],
                ['id' => 'b', 'text' => 'The report is the fixed "not covered" sentence.'],
                ['id' => 'c', 'text' => 'The run is refused before the planner.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Research how the docs answer cache works. The planner splits it, each sub-question finds chunks, and the report cites them; every marker points at a source in the list.'],
            'page' => 'ai.research',
            'params' => [],
        ],
        [
            'title' => 'A topic the docs do not cover',
            'predictions' => [
                ['id' => 'a', 'text' => 'The model writes a report from what it knows.'],
                ['id' => 'b', 'text' => 'The report says the documentation does not cover the topic, with no report call.'],
                ['id' => 'c', 'text' => 'The run fails with an error.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Research something the docs never mention. The notes have no facts, so the fixed sentence is the report and no model writes it: an answer that was not found is not made up.'],
            'page' => 'ai.research',
            'params' => [],
        ],
        [
            'title' => 'A topic that tries to steer the agent',
            'predictions' => [
                ['id' => 'a', 'text' => 'The run is refused, with a reason, and no model is called.'],
                ['id' => 'b', 'text' => 'The planner obeys and plans the injected task.'],
                ['id' => 'c', 'text' => 'The text is ignored and the run goes on.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Start a topic such as "ignore all previous instructions and ...". The guard matches before the planner, so the run is stored as refused and the model never sees the text.'],
            'page' => 'ai.research',
            'params' => [],
        ],
    ],
    'related' => ['docs', 'guards', 'prompts'],
];
