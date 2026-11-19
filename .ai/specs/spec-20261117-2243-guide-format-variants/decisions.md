# Decisions: guide format variants (SL-54)

All self-decided under the owner's standing preference (recommended options), open for change at approval.

- **D-1 One pilot topic, five formats.** The photos guide is the pilot: it has a pipeline (command → queue → job → agent → model → checks → row), a schema, a validation loop and clear failure modes, so every format has material. Comparing formats on one topic isolates the format from the content.
- **D-2 Shared learning outcomes (LO-1 to LO-5).** Each format must cover the same five outcomes (AC-5). Without a shared goal the comparison would reward whichever format happens to say more.
- **D-3 Content as PHP data files, one schema per format.** This is the same pattern as `resources/guides/*.php`: reviewed in git, validated at load, with snippets taken from the real source through `SnippetResolver`, so the code shown cannot drift from the app. There is no database table and no CMS.
- **D-4 Client-side interactivity only.** Progress lives in `localStorage` and the quiz score in the session. There is no ratings storage: the owner's pick is a human decision recorded here (task 8), and storing feedback would add a table for one person's choice.
- **D-5 No new dependencies.** The trace is SVG/CSS and the code tour reuses `LabCode`. Diagram, quiz or tour libraries would need the owner's approval (product rule) and would bias the comparison toward library polish.
- **D-6 Baseline untouched, additive entry.** The photos guide and the guide engine output for existing guides stay identical (MNC-1). The only change to existing pages is one card on `/ai/guides` (MNC-2).

## Owner pick (task 8)

- Chosen format: scene lessons. None of the five formats is rolled out as it stands. The owner asked on 2026-11-19 for interactive guides like designed-to-scale.vercel.app: an animated scene with moving requests, a KPI strip of measured numbers, a list of what changed and a step panel, one step at a time.
- Why, against the rubric: clarity, because each step moves from a problem to a solution and shows the change in the scene next to the numbers; retention, because the learner watches the queue fill and the first token slow down before reading the explanation; time to first working change, which the scene lessons do not aim at, so the guides stay the place for build-along work; effort to author, which is one PHP data file per lesson with every number read from `bench/results`.
- Follow-up slice: SL-55 (AI lab scene lessons, `/ai/learn`). The five format pages stay as built until the owner says otherwise. The "Follow one photo" content may be reused as a later lesson.
