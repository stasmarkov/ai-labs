# Manual test plan: one learning area (SL-57)

Base URL: https://ai-lab.ddev.site

## Preconditions

- `ddev start`; frontend built with `ddev npm run build`.
- No login or seeding. Progress is browser-only (`ai-lab:learn:v1`).
- Rollback: revert the feature commit; nothing is written to the database.

## Step 1

Index and redirect (AC-1, AC-3, AC-8).

1. Run `curl -skI https://ai-lab.ddev.site/ai/guides | head -3`.
2. Open https://ai-lab.ddev.site/ai/learn.
3. Open https://ai-lab.ddev.site/ and find the guides link.

Expected:
- curl prints `301` and `location: /ai/learn` (absolute or relative).
- `/ai/learn` lists five modules: Running models, Answers you can trust, Agents, Search and photos, Running the server. Lesson topics show "Walk through" and "Reference"; reference topics show a "reference" tag and "Reference" only. A "How these lessons work" link opens `/ai/guides/lessons`.
- The home page guides link opens `/ai/learn`.

## Step 2

Lesson page header (AC-4, MNC-1).

1. Open https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/retrieval-with-citations.
2. Click "Reference", then browser Back, then press ArrowRight twice.

Expected: breadcrumb "Learn / Answers you can trust / Retrieval with citations"; meta "intermediate, N min, N steps"; "Walk through" is current; "Reference" opens a guide that links this lesson. Arrow keys move steps as before.

## Step 3

Guide page header and sidebar (AC-5, AC-6, MNC-2).

1. Open https://ai-lab.ddev.site/ai/guides/streaming and https://ai-lab.ddev.site/ai/guides/semantic.
2. Open the course rail. Click each tab on the streaming guide.

Expected: both show the topic header with "Reference" current. Streaming's "Walk through" opens "Streaming an answer"; semantic's reads "lesson coming" and is not a link. The rail lists all modules, lesson topics and reference topics, with the current guide marked. All nine tabs work and `#tab` anchors stay.

## Step 4

Grouped lab sidebar and keyboard (AC-7, NFR-2).

1. On https://ai-lab.ddev.site/ai/describe look at the left navigation at 1440 px and at 1024 px.
2. Press Tab through the navigation, the switch and the course rail on a guide page.

Expected: groups Learn, Text, Search, Photos, Agents and (locally) Setup, each with a label. "Course" is current on learn and guide pages. Every stop has a visible outline; current items announce as current page.

## Step 5

Audit and bench (AC-9, MNC-6).

```sh
node bench/ui-audit.mjs; echo $?
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity; git checkout -- bench/parity
```

Expected: audit 0 failing pages, exit 0, guide and lesson pages included; fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`; parity diff only `captured=`.

## Step 6

Docs, references, dependencies (AC-10, MNC-5).

1. Read the "Lessons" section of README.md, "Scene lessons" in docs/learning-guide.md, and /ai/guides/lessons.
2. Run `git diff --stat master -- composer.json composer.lock package.json package-lock.json database`.

Expected: docs describe Walk through vs Reference and reference topics; backlog has SL-57 and SL-58; git diff prints nothing.
