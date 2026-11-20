# Manual test plan: one learning area (SL-57)

Base URL: https://ai-lab.ddev.site

The reported problem: guides and lessons lived in two separate places, so a visitor could not tell where to start or move between a lesson and its guide. Now `/ai/guides` is gone as an entry point and everything starts at `/ai/learn`.

## Preconditions

- `ddev start`, then `ddev npm run build` so the frontend is current.
- No login, no seeding. Lesson progress lives only in the browser (`ai-lab:learn:v1`).
- Use a normal browser window at 1440 px wide unless a step says otherwise.
- Rollback: revert the feature commit; nothing is written to the database. Step 7 edits one file on purpose, and its last line restores it.

## Step 1

Index, redirect and course page (AC-1, AC-3, AC-8). This is the originally reported case: the old guides entry must land in the one area.

1. Run `curl -skI https://ai-lab.ddev.site/ai/guides | head -5`.
2. Run `curl -skI https://ai-lab.ddev.site/ai/guides/ | head -5`.
3. Open https://ai-lab.ddev.site/ai/learn.
4. Open https://ai-lab.ddev.site/ and click the guides link.

Expected:
- Both curl calls print `301` and a `location` header that ends in `/ai/learn`.
- `/ai/learn` lists five modules: Running models, Answers you can trust, Agents, Search and photos, Running the server.
- Each lesson topic shows a "Walk through" link and, under "Reference", at least one guide link. Each reference-only topic (for example Describe, Semantic) shows a "reference" tag and a "Reference" link, and no "Walk through".
- A "How these lessons work" link opens `/ai/guides/lessons`.
- The home page guides link opens `/ai/learn`.

## Step 2

Lesson page header (AC-4, MNC-1).

1. Open https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/retrieval-with-citations.
2. Click the breadcrumb module name "Answers you can trust".
3. Go back, click "Reference", then go back again.
4. Press ArrowRight twice.

Expected:
- Breadcrumb reads "Learn / Answers you can trust / Retrieval with citations", with a meta line such as "intermediate, 10 min, 10 steps" (the step count may differ).
- "Walk through" is the current segment (not a link); "Reference" opens a guide that names this lesson (`/ai/guides/docs`).
- The module link goes to `/ai/learn#answers-you-can-trust` and scrolls to that module.
- ArrowRight moves "Step 1 of N" to "Step 2 of N", then "Step 3 of N". Progress survives a reload (browser storage key `ai-lab:learn:v1`).

## Step 3

Guide page header, sidebar and tabs (AC-5, AC-6, MNC-2).

1. Open https://ai-lab.ddev.site/ai/guides/streaming.
2. Open the "Course" rail, then click "Walk through".
3. Open https://ai-lab.ddev.site/ai/guides/semantic.
4. On https://ai-lab.ddev.site/ai/guides/streaming click each tab in turn (works, implementation, modify, support, numbers, experiments, build, watch, elsewhere), then open https://ai-lab.ddev.site/ai/guides/streaming#numbers.

Expected:
- Streaming: "Reference" is current; "Walk through" opens `/ai/learn/running-models/streaming-an-answer`. The rail lists all modules, lesson topics and reference topics, with the lesson "Streaming an answer" marked current.
- Semantic: "Reference" is current; "Walk through · lesson coming" is plain text, not a link. The rail marks "Search by meaning" current. The meta line shows "reference".
- All nine tabs open with their usual content, and `#numbers` opens the numbers tab directly.

## Step 4

Grouped lab navigation and keyboard use (AC-7, NFR-2).

1. Open https://ai-lab.ddev.site/ai/describe and look at the left navigation at 1440 px, then narrow the window to 1024 px.
2. Click "Course" or any Learn item, then open any guide page.
3. Starting at the top of a guide page, press Tab repeatedly through the navigation, the "Walk through / Reference" switch and the course rail.

Expected:
- Labelled groups: Learn, Text, Search, Photos, Agents, Setup (Setup only where those pages are available locally). No empty group appears.
- The Learn group's current item is highlighted on learn and guide pages.
- Every Tab stop shows a visible outline. A screen reader (or the accessibility tree in dev tools) reports current items as "current page" and the group labels as headings.

## Step 5

Audit and bench (AC-9, MNC-6).

```sh
node bench/ui-audit.mjs; echo "exit=$?"
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity; git checkout -- bench/parity
```

Expected:
- Audit prints `lessons 8 from /ai/learn`, `guides 25 from /ai/learn`, `120 page(s) checked, 0 failing`, `exit=0`.
- Fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The parity diff shows only the `captured=` line changing.

## Step 6

Docs, references and dependencies (AC-10, MNC-5).

1. Read the "Lessons" section of README.md, "Scene lessons" in docs/learning-guide.md, and https://ai-lab.ddev.site/ai/guides/lessons.
2. Search backlog.md for `SL-57` and `SL-58`.
3. Skim a few pages for stray slice ids, `.md` file names or repo paths (try /ai/learn, /ai/guides/lessons).
4. Run `git diff --stat master -- composer.json composer.lock package.json package-lock.json database`.

Expected:
- All three docs describe one learning area, "Walk through" versus "Reference" and reference topics.
- backlog.md has entries for SL-57 and SL-58.
- No slice ids, `.md` names or paths show in the UI.
- The git diff prints nothing.

## Step 7

A guide owned twice or by nobody is rejected (AC-2). This edits a file; restore it at the end.

1. In `resources/lessons/course.php`, delete `'describe'` from the `'references' => ['describe']` line of the running-models module.
2. Open https://ai-lab.ddev.site/ai/learn.
3. Put it back, then in the same list add a guide a lesson already owns: `'references' => ['describe', 'streaming']`. Open https://ai-lab.ddev.site/ai/learn again.
4. Undo both edits by hand so `'references' => ['describe']` is back as it was (check with `git diff resources/lessons/course.php`).
5. Reload https://ai-lab.ddev.site/ai/learn.

Expected:
- After 1-2: a server error page (500) whose message names the guide key `describe` as not placed.
- After 3: a server error naming `streaming` as owned twice (by a lesson and by a module's references).
- After 4-5: the course loads again with five modules.

## Step 8

Format trial, payload size and untouched pages (MNC-3, MNC-4, NFR-1).

1. Open https://ai-lab.ddev.site/ai/guides/photos and click "Format trial" in the header.
2. Open each of these and check the breadcrumb link: https://ai-lab.ddev.site/ai/guides/photos/formats/build-along, /trace, /quiz, /debug, /tour.
3. Open https://ai-lab.ddev.site/ai/describe, /ai/playground, /ai/settings, /ai/changes and /ai/assistant.
4. Run `curl -sk https://ai-lab.ddev.site/ai/learn | perl -0ne 'print length($1) if /<script data-page="app" type="application\/json">(.*?)<\/script>/s'; echo`.
5. Repeat step 4 for `/ai/guides/local-models` and `/ai/guides/research`.

Expected:
- "Format trial" goes to `/ai/guides/photos/formats`, which lists five formats. Each format page answers 200 and its breadcrumb goes to "Learn" (`/ai/learn`), not to a guides list.
- The lab pages open as before; playground, admin assistant, changes and settings appear only where they were available before.
- The `/ai/learn` count is far below 60 000 (about 11 500 characters). `local-models` is under 60 000. `research`, the largest guide page, is about 53 500.

## Tick after the manual steps pass

Tick task 9 (`- [ ] 9.` and `- [ ] 9.1` in .ai/specs/spec-20261120-1558-one-learning-area/tasks.md).
