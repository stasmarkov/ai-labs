<?php

declare(strict_types=1);

/**
 * Each figure reads the newest run folder that has its file. The search figures show the engine's pending note until the
 * search-under-load file exists, so the page never breaks on a run that has not been made yet.
 */
$latest = static function (string $directory, string $file): ?string {
    $paths = glob(base_path("bench/results/{$directory}/*/{$file}")) ?: [];
    rsort($paths);

    return $paths === [] ? null : "{$directory}/".basename(dirname($paths[0]))."/{$file}";
};

$run = $latest('llm', 'run.json') ?? 'llm/missing/run.json';
$concurrency = $latest('llm-load', 'concurrency.json') ?? 'llm-load/missing/concurrency.json';
$search = $latest('llm-load', 'search-under-load.json');
$servers = ['classic frankenphp', 'frankenphp worker', 'frankenphp octane'];

$numbers = [];

foreach (['qwen3.5:9b', 'gemma4:12b'] as $model) {
    $numbers[] = ['label' => "{$model}, generation speed (tokens per second)", 'file' => $run, 'path' => "models.{$model}.speed.generation_tokens_per_s.median"];
    $numbers[] = ['label' => "{$model}, time to first token (ms)", 'file' => $run, 'path' => "models.{$model}.speed.ttft_ms.median"];
    $numbers[] = ['label' => "{$model}, load time of a cold model (ms)", 'file' => $run, 'path' => "models.{$model}.speed.load_ms"];
}

foreach (['qwen3.5:9b' => 0, 'gemma4:12b' => 1] as $model => $index) {
    $numbers[] = ['label' => "{$model}, 16 parallel descriptions, time to first token p50 (ms)", 'file' => $concurrency, 'path' => "models.{$index}.levels.2.ttft_p50_ms"];
    $numbers[] = ['label' => "{$model}, 16 parallel descriptions, aggregate text chunks per second", 'file' => $concurrency, 'path' => "models.{$index}.levels.2.events_per_s_aggregate"];
}

foreach ($servers as $index => $server) {
    if ($search === null) {
        $numbers[] = ['label' => "Search p95 with and without open description streams, {$server}", 'pending' => true];

        continue;
    }

    $numbers[] = ['label' => "Search p95 without description streams, {$server} (ms)", 'file' => $search, 'path' => "servers.{$index}.without_load.p95_ms"];
    $numbers[] = ['label' => "Search p95 with 4 description streams open, {$server} (ms)", 'file' => $search, 'path' => "servers.{$index}.with_load.p95_ms"];
    $numbers[] = ['label' => "Search p95 change under the streams, {$server} (ms)", 'file' => $search, 'path' => "servers.{$index}.p95_delta_ms"];
}

return [
    'kind' => 'foundation',
    'title' => 'Local models and their limits',
    'summary' => 'How fast a model that runs on your own machine is, what it costs in memory, what happens when a prompt is too long, when the server is busy or down, and why a running model can make the rest of the site slower.',
    'works' => [
        [
            'title' => 'Read tokens per second and time to first token separately',
            'body' => 'Two numbers describe speed, and they answer different questions. **Time to first token** (TTFT) is the wait before anything appears: the model must read the whole prompt first, so it grows with prompt length and includes the load time when the model was not in memory. **Tokens per second** is how fast the text grows once it started: the model writes one token at a time, so this rate depends on the model size and the hardware, not on the prompt. A short answer is dominated by TTFT, a long one by the rate. The bench measures TTFT in the client, at the first chunk with text, and takes both rates from the counts and durations Ollama reports at the end of the stream.',
            'event' => 'request_sent',
            'snippet' => 'App\Support\LlmSpeedBench::measure',
        ],
        [
            'title' => 'The first request pays the load, the next ones do not',
            'body' => 'Ollama keeps a model in memory for the `keep_alive` time after its last request, 10 minutes here. The first request after that loads the weights from disk, which takes seconds for a 9B model, and Ollama reports it as `load_duration`. The bench unloads every model first, makes one cold request to read that load time, and leaves it out of the warm statistics. It reports the median, the minimum and the maximum of the warm runs, because one slow run (another app on the machine, a thermal limit) should not decide the table.',
            'event' => 'response_received',
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'The context window is a fixed budget for prompt and answer',
            'body' => '`num_ctx` is the number of tokens the model can hold at once: the prompt, the history and the answer. Memory grows with it, because the model keeps a cache entry for every token in the window. When a prompt is longer than the window, Ollama does not refuse it: it answers anyway, and what it did with the extra text depends on the runner, so the model may never see part of the prompt. The context check hides a six-character code in the first sentence of a prompt about 1.5 times the window, and asks for it at the configured window and at twice that. It reads the window the server really loaded for the model, and reports the tokens that were counted, the window, whether the code came back and a context shift: more tokens counted than the window can hold, with the same count at both sizes. A shift means the window was not enlarged by the request or the text was slid, so the count alone cannot tell a cut from a fit.',
            'snippet' => 'App\Support\LlmContextBench::measure',
        ],
        [
            'title' => 'Parallel requests share one model and one memory pool',
            'body' => '`OLLAMA_NUM_PARALLEL` is a setting of the Ollama server on the host, not of this app, so the app can only note the value it was given. With several requests at once the server either runs them side by side, each with its own slice of the window, or queues the rest. A queued request shows up as a longer TTFT, not as an error. Four people reading generated descriptions at the same time therefore feel the slowest part of the line, and the aggregate speed of the server rises less than the number of streams.',
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'A failed call is logged and masked for the page',
            'body' => "Every feature has the same failure path: the provider call throws, the run row records a failed status with a redacted message, and the page gets a generic error instead of the provider's text. The failover check sends a real request to each entry point (the description stream, the natural-language search, the moderation endpoint and the assistant) with the server unreachable, with a model that is not pulled and with a one second timeout, and records the status, what the client sees and how long it took to fail. It only measures: no request is redirected to another model.",
            'event' => 'request_failed',
            'snippet' => 'App\Support\LlmFailoverBench::measure',
        ],
    ],
    'failures' => [
        [
            'title' => 'The first answer after a pause takes many seconds',
            'body' => "**Cause:** the model was unloaded after the keep-alive time, so the request pays the load time before the first token.\n\n**Fix:** raise `OLLAMA_KEEP_ALIVE` if the memory can stay taken, or accept one cold start and compare only warm runs. The bench reports the load time apart for this reason.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'The model ignores the start of a long prompt',
            'body' => "**Cause:** the prompt is longer than `num_ctx`, and Ollama cut it to fit without an error. A question about the first paragraph is then answered from a text that no longer has it.\n\n**Fix:** keep prompts well inside the window, or raise `OLLAMA_NUM_CTX` and check the memory column afterwards. A prompt token count that stops growing while the text grows is the sign.",
            'snippet' => 'App\Support\LlmContextBench::measure',
        ],
        [
            'title' => 'The page shows a generic error while Ollama is down',
            'body' => "**Cause:** the server is stopped or the URL points to the wrong host, so the call is refused at once.\n\n**Fix:** start Ollama on the host and check that the container can reach it. The run row holds the real message, and the page never shows it.",
            'snippet' => 'App\Ai\OllamaServerInfo::reachable',
        ],
        [
            'title' => 'The request waits and then times out',
            'body' => "**Cause:** a cold model plus a long prompt takes longer than the provider timeout, which is 120 seconds by default.\n\n**Fix:** warm the model first, shorten the prompt, or raise `AI_TIMEOUT` for slow hardware. A timeout is slower to fail than a refused connection, and the failover table shows by how much.",
        ],
        [
            'title' => 'Ollama answers that the model was not found',
            'body' => "**Cause:** the name is not pulled on the machine that runs the server, or has a typo.\n\n**Fix:** pull it on the host (`ollama pull <name>`) and repeat the call. The smoke tests skip with this exact command in their message.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Compare two models on your machine',
            'body' => "Run the bench with the two model names, `llm:bench --models=a,b`, after pulling both. It writes one result file per day with the speed, the memory, the context check, the failover table and the quality figures copied from the latest evals.\n\n**Check:** the summary table has one row per model, and each quality figure names the run it came from.",
            'snippet' => 'App\Support\LlmSpeedBench::measure',
        ],
        [
            'title' => 'Change the context window or the keep-alive time',
            'body' => "Set `OLLAMA_NUM_CTX` or `OLLAMA_KEEP_ALIVE` in the environment and restart the workers. Rerun the context check to see the memory and the truncation at the new size.\n\n**Check:** the memory reported while the model is loaded grows with the window, and a prompt that was cut before now keeps its first sentence.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Add a fallback chain',
            'body' => "The app does not switch models by itself, on purpose: a silent switch would hide the fault and could send a prompt to a paid provider the feature was not configured for. To add one, put the order in the configuration (for example the local model, then a second local model, then nothing), try the next entry only on a transport error or a timeout and never on a rejected answer, log every attempt as its own run row with the provider that answered, and keep the cloud limiter in front of any paid entry.\n\n**Check:** with the first model stopped, the run rows show one failed attempt and one answered by the next entry, and the page shows which model answered.",
            'snippet' => 'App\Ai\AiProviders::resolve',
        ],
        [
            'title' => 'Run the smoke tests against a tiny model',
            'body' => "Pull `qwen3:0.6b` on the host, then run the group with `LOCAL_LLM_SMOKE=1`. Three tests call the real describe, natural-language search and moderation code and check the shape of the result, not its quality. Without the switch they are skipped, so the normal test run never calls a model.\n\n**Check:** three tests pass, and with Ollama stopped they skip with the pull command.",
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows',
            'status' => 'logged',
            'note' => 'Every model call, including a failed one, is a run row with the provider, the model, the prompt version, the tokens and the latency. A failed row keeps a redacted message.',
        ],
        [
            'name' => 'Secrets and results',
            'status' => 'guarded',
            'note' => 'The bench strips URL credentials, Authorization values and keys from everything it writes. Committed evaluation results are only read. The normal test run makes no request to Ollama, and the smoke group is skipped unless it is switched on.',
        ],
        [
            'name' => 'Why the site slows while a model answers',
            'status' => 'runbook',
            'note' => "1. A streamed answer holds one PHP thread for its whole length, and a classic server has a fixed number of them. A few open streams can take all of them, and pages without any AI then wait in the queue.\n2. A worker server keeps fewer, longer-lived processes, and a stream still occupies one for its duration. Measured here, 4 open description streams took all 4 workers: search p95 went from about 64 ms to 2.7 s on both worker servers, while the classic server, with more threads, only slowed from 132 to 237 ms.\n3. The model also uses the machine: memory pressure and a busy GPU or CPU slow the database and the web server running next to it.\n4. To check, run the search benchmark with and without a few open description streams and compare the p95 latency.\n5. To reduce it, keep fewer open streams than workers (or add workers), move long answers off the request workers with a queue and live updates, use a smaller model, or run the model on another machine.",
        ],
        [
            'name' => 'The model is slow or missing',
            'status' => 'runbook',
            'note' => "1. Check that the server answers from the container.\n2. List the models it has and compare the name with the one in use.\n3. Read the load time and the memory in the latest bench result: a model larger than the free memory spills to the CPU and slows down.\n4. Read the failed run row for the real message.",
        ],
    ],
    'numbers' => $numbers,
    'experiments' => [
        [
            'title' => 'A cold start against a warm one',
            'predictions' => [
                ['id' => 'a', 'text' => 'Both runs start answering after about the same time.'],
                ['id' => 'b', 'text' => 'The first run after a long pause waits for the model to load; the second starts much sooner.'],
                ['id' => 'c', 'text' => 'The second run is slower because the model is already busy.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Start a description, wait for it to finish, then start another one at once and compare the time to first token on the run pages. If the model was unloaded before the first, the difference is the load time.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
        [
            'title' => 'Two descriptions at the same time',
            'predictions' => [
                ['id' => 'a', 'text' => 'Both start at once and write at the speed of a single run.'],
                ['id' => 'b', 'text' => 'One of them waits, or both write more slowly, because they share the model.'],
                ['id' => 'c', 'text' => 'The second one is refused.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Open the page in two tabs and start both together. The server shares one model between the streams, so the time to first token or the speed of at least one of them gets worse.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['providers', 'streaming', 'run-log'],
];
