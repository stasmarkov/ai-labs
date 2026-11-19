<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Metrics: watching the thread pool',
    'summary' => 'What the server reports about itself while it works: busy and total threads and workers, the queue, requests per second, latency and memory, drawn second by second in Grafana. Opt-in, local only.',
    'works' => [
        [
            'title' => 'Where the numbers come from',
            'body' => "FrankenPHP and Caddy keep counters in memory and publish them as Prometheus text on a small listener, port 9180 inside the project network, that serves nothing else. The Caddy admin API stays on localhost. `ddev metrics on` starts two extra containers: **Prometheus** reads the listener every second and keeps two days of samples, and **Grafana** draws them on one read-only dashboard at `https://ai-lab.ddev.site:3030`. A plain `ddev start` starts neither.\n\nA **gauge** is a value right now (busy threads). A **counter** only grows (requests served); a graph shows its rate per second. A **histogram** counts requests per latency bucket, and percentiles are estimated from the buckets.",
        ],
        [
            'title' => 'Threads: busy against total',
            'body' => "`frankenphp_total_threads` is how many PHP threads were started. With autoscale it stayed at the starting 14 in the lab's measurements while up to 64 threads ran, so do not read it as the live pool size. `frankenphp_busy_threads` counts the requests inside PHP, the ones running **and** the ones waiting for a thread. So when the pool is full busy climbs above total, and busy minus queue depth is what really runs. In classic mode one request holds one thread until it ends, so the health check waits behind slow requests although it does no work.\n\nIn worker and Octane mode a worker thread counts as busy while it waits for its next request, so busy reads 4 with 4 idle workers and does not move under load. Read the workers panel there.",
            'snippet' => 'App\Http\Controllers\HealthzController',
        ],
        [
            'title' => 'Workers and queue depth',
            'body' => '`frankenphp_busy_workers`, `frankenphp_ready_workers` and `frankenphp_total_workers` exist only in worker and Octane mode; classic shows "No data". Busy workers counts the requests handed to the workers, running or waiting for one. When it is above total the workers are saturated, and busy minus total are the requests waiting. `frankenphp_queue_depth` counts regular requests waiting for a free thread. It is the number that rises in classic mode while the health check gets slow. It does **not** count requests waiting for a worker, so in worker and Octane mode it stays at 0 while the workers are saturated.',
        ],
        [
            'title' => 'Requests, latency, status codes and memory',
            'body' => "Requests per second is the rate of `caddy_http_requests_total`. In worker mode Caddy counts one request once per handler it passes (three), so the panels count one handler per mode: `vars` classic, `php` worker, `subroute` Octane. Status codes come from the latency histogram's count, because the request counter has no code label.\n\np50 and p95 come from `caddy_http_request_duration_seconds`. Its highest bucket is 10 s, so a p95 above 10 s is drawn as 10 s: a 6 s answer stream that waited for a thread hits that cap. `process_resident_memory_bytes` is the memory of the FrankenPHP process, all threads together.",
        ],
        [
            'title' => 'One load on the graphs: slow calls on 14 threads',
            'body' => "100 users each call a page that waits 300 ms for an outside VIN service, while a probe asks `/healthz` 5 times a second.\n\n1. Busy threads passes 14: all 14 threads wait on the outside service, and every request behind them is counted too.\n2. Queue depth rises with it, to busy minus 14: new requests, the health probe among them, wait for a thread.\n3. Requests per second flattens at about threads divided by 0.3 s, and p95 climbs to seconds.\n4. The load ends, busy threads fall, the queue drains to 0 and the graphs return to idle.\n\nWith autoscale the server adds threads (up to 64 here): requests per second goes up about five times and the queue gets shorter, while the total line stays at 14.",
            'snippet' => 'App\Services\VinReportService::reportFor',
        ],
        [
            'title' => 'The same load on worker mode, and with answer streams',
            'body' => 'On worker mode busy threads stays flat at 4 and queue depth stays at 0, so those two panels look calm while the site is slow. The workers panel tells the truth: busy workers climbs far above the 4 workers. An AI answer stream holds its thread or worker for the whole answer (about 6 s here), so 30 open streams fill the pool faster than 100 short slow calls.',
            'snippet' => 'App\Http\Controllers\DescribeListingController',
        ],
    ],
    'failures' => [
        [
            'title' => 'The site is slow but queue depth is 0',
            'body' => "**Cause:** worker or Octane mode. The queue only counts requests waiting for a regular thread, and busy threads counts idle workers too.\n\n**Fix:** read busy workers against total workers. Above total means saturated, and the difference is the requests waiting.",
        ],
        [
            'title' => 'p95 is a flat line at 10 s',
            'body' => "**Cause:** Caddy's latency histogram has no bucket above 10 s, so any slower percentile is reported as the top bucket. The snapshot writes such a value as `>10 (bucket cap)`.\n\n**Fix:** read long requests and streams from the load generator's own timings, which time every request.",
        ],
        [
            'title' => 'Requests per second is three times the real rate',
            'body' => "**Cause:** a query sums every handler. In worker mode one request passes three handlers and each counts it.\n\n**Fix:** select one handler per mode (`handler=~\"php|vars|subroute\"`), as the dashboard does, and leave the scrape's own `metrics` handler out.",
        ],
        [
            'title' => 'The graph has a gap during a benchmark',
            'body' => "**Cause:** a project restart removes the Prometheus and Grafana containers, and the benchmark restarts the project before it measures.\n\n**Fix:** the metrics matrix brings them back as soon as the web container is healthy. A snapshot whose window was scraped less than 80 % of the time fails instead of saving empty graphs.",
        ],
        [
            'title' => 'A one-second scrape hides what happens between scrapes',
            'body' => "**Cause:** a gauge is read once a second; a spike that comes and goes inside that second is never seen, and a panel draws averages over its interval.\n\n**Fix:** use counters and histograms for anything short (they keep counting between scrapes), and take the maximum over the window, not the average.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Watch one load yourself',
            'body' => "```sh\nddev metrics on                # prints the Grafana URL\nddev metrics status            # prometheus, grafana and the target state\n```\n\nOpen the URL, set the time range to the last 5 minutes, then start a load, for example 30 answer streams with the slow-load bench. Watch busy threads and queue depth move together. `ddev metrics off` stops both containers; the samples stay in their volume.\n\n**Check:** `ddev metrics status` prints `target: up`.",
        ],
        [
            'title' => 'Add a panel',
            'body' => "1. Read the live metric names: `ddev exec curl -s localhost:9180/metrics`.\n2. Copy a panel in the dashboard JSON and change its title, description and query, for example `frankenphp_worker_request_count` as a rate.\n3. Recapture the three per-mode fixtures, so the test that checks every panel query against a captured metric name passes.\n4. Grafana re-reads the dashboard folder on its own; reload the page.\n\n**Check:** the panel shows data on the mode it is meant for, and \"No data\" (not an error) on the others.",
        ],
        [
            'title' => 'Save a window as numbers and a picture',
            'body' => "`metrics-snapshot.sh <cell> <from-ms> <to-ms>` asks Prometheus for the maxima (busy threads, busy and total workers, queue depth, memory) and the p95 over that window, and saves a PNG of the dashboard rendered in a pinned browser container. The metrics matrix calls it for every cell and passes the time the load actually ran.\n\n**Check:** the JSON has `scrape_coverage` near 1.",
        ],
        [
            'title' => 'Change the model or provider and watch the pool',
            'body' => 'The pool does not care which model answers; it cares how long each answer holds a thread. Switch the describe page to a slower provider or a local model and the busy line stays up longer for the same number of users. The same graphs then show whether the pool, not the model, is the limit.',
        ],
        [
            'title' => 'The same pattern in other places',
            'body' => "The dashboard is one pattern: **capacity, in use, waiting, and how long it took**. It reads the same elsewhere:\n\n- **Queue workers:** workers busy against workers running, jobs waiting, and job duration. Jobs pile up while every worker is busy.\n- **Database connection pool:** connections in use against the pool size, and clients waiting for a connection.\n- **A call centre:** agents on a call against agents on shift, callers on hold, and the wait time.\n- **Checkout lanes:** lanes busy against lanes open, the line length, and time to pay. Opening lanes is autoscale.\n- **A hospital ward:** beds taken against beds, patients waiting for admission.\n\nIn every one, \"waiting\" rises only after \"in use\" has hit \"capacity\", and the wait time is what the people outside feel.",
        ],
    ],
    'support' => [
        [
            'name' => 'Samples and bench snapshots',
            'status' => 'logged',
            'note' => 'Prometheus keeps every one-second sample for two days in its own volume. Each metrics bench cell writes one JSON with the window, the scrape coverage and the maxima, and one PNG of the dashboard for the same window.',
        ],
        [
            'name' => 'Exposure and footprint',
            'status' => 'guarded',
            'note' => 'Tests check that every image is pinned by digest, that the stack runs only under its own profile, that port 9180 and the admin port are never published, that Grafana is anonymous read-only with password login, basic auth and sign-up off, that each container has a 256 MB memory limit, and that every panel query names a metric found in the captured fixtures.',
        ],
        [
            'name' => 'A panel is empty or the dashboard does not open',
            'status' => 'runbook',
            'note' => "1. Run `ddev metrics status`. Stopped containers: run `ddev metrics on` again (a restart or a server switch removes them).\n2. `target: down`: check the web container answers `curl -s localhost:9180/metrics` inside it.\n3. Only the workers panels are empty: you are on classic mode, which has no workers.\n4. Everything is flat: check the time range covers the load.",
        ],
    ],
    'numbers' => [
        ['label' => '100 slow calls on 14 fixed threads: most requests inside PHP at once (busy threads)', 'file' => 'metrics/2026-11-16/http-frankenphp-14.json', 'path' => 'metrics.max_busy_threads'],
        ['label' => '100 slow calls on 14 fixed threads: most requests waiting for a thread (queue depth)', 'file' => 'metrics/2026-11-16/http-frankenphp-14.json', 'path' => 'metrics.max_queue_depth'],
        ['label' => '100 slow calls on 14 fixed threads: total threads reported', 'file' => 'metrics/2026-11-16/http-frankenphp-14.json', 'path' => 'metrics.max_total_threads'],
        ['label' => '100 slow calls on autoscale 14 to 64: total threads reported (the gauge does not count added threads)', 'file' => 'metrics/2026-11-16/http-frankenphp-auto.json', 'path' => 'metrics.max_total_threads'],
        ['label' => '100 slow calls on worker mode: most busy workers', 'file' => 'metrics/2026-11-16/http-frankenphp-worker-4.json', 'path' => 'metrics.max_busy_workers'],
        ['label' => '100 slow calls on worker mode: workers', 'file' => 'metrics/2026-11-16/http-frankenphp-worker-4.json', 'path' => 'metrics.total_workers'],
        ['label' => '100 slow calls on worker mode: most requests in the thread queue', 'file' => 'metrics/2026-11-16/http-frankenphp-worker-4.json', 'path' => 'metrics.max_queue_depth'],
        ['label' => '30 answer streams on 14 threads: p95 from the histogram', 'file' => 'metrics/2026-11-16/sse-frankenphp-14.json', 'path' => 'metrics.p95_seconds'],
        ['label' => 'Share of seconds scraped during the load, worker mode', 'file' => 'metrics/2026-11-16/http-frankenphp-worker-4.json', 'path' => 'scrape_coverage'],
    ],
    'experiments' => [
        [
            'title' => 'Watch one answer stream on the dashboard',
            'predictions' => [
                ['id' => 'a', 'text' => 'Nothing moves: one stream is too small to see.'],
                ['id' => 'b', 'text' => 'Busy threads goes up by one for the whole answer and drops when it ends.'],
                ['id' => 'c', 'text' => 'Queue depth rises to one while the answer streams.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'An answer stream holds one thread from the first byte to the end marker, so the busy line steps up by one for those seconds. Nothing waits, because 13 more threads are free, so the queue stays at 0. Run `ddev metrics on` first and keep the dashboard open next to the describe page.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['streaming', 'local-models'],
    'lessons' => ['running-models/one-model-many-users'],
    'build' => [
        ['step' => 'Expose server counters', 'detail' => 'Enable the server metrics endpoint, so threads, workers, queue and memory can be scraped.', 'tab' => 'recipes', 'doc' => 'https://frankenphp.dev/docs/'],
        ['step' => 'Scrape every second', 'detail' => 'Run a time series store with a short interval and keep it local for labs.', 'tab' => 'recipes'],
        ['step' => 'Draw the pool', 'detail' => 'Build a dashboard of busy against total threads, queue depth, requests and latency.', 'tab' => 'recipes'],
        ['step' => 'Run a known load', 'detail' => 'Drive a fixed number of slow calls and read the graph, so you can predict and compare.', 'tab' => 'support'],
        ['step' => 'Save a window as numbers', 'detail' => 'Export a time window as a table and a picture for the record.', 'tab' => 'recipes'],
    ],
    'watch' => [
        ['point' => 'Averages hide queues', 'detail' => 'Look at queue depth and p95, not only the mean.', 'tab' => 'failures'],
        ['point' => 'Capped percentiles', 'detail' => 'A flat p95 can be the histogram cap, not the real value.', 'tab' => 'failures'],
        ['point' => 'Scrape interval', 'detail' => 'A one second scrape misses what happens between samples.', 'tab' => 'failures'],
        ['point' => 'Metrics are opt-in', 'detail' => 'Do not expose the endpoint on a public host.', 'tab' => 'support'],
    ],
    'elsewhere' => [
        ['domain' => 'Capacity planning', 'problem' => 'A team must size a PHP pool for a model call.', 'how' => 'Run the load, watch busy threads and queue depth, and set the pool from the graph.'],
        ['domain' => 'Incident review', 'problem' => 'A site was slow and nobody knows why.', 'how' => 'Compare queue depth and latency graphs around the incident.'],
    ],
];
