# Manual test plan: remaining scene lessons (SL-56)

Base URL: https://ai-lab.ddev.site

## Step 1

Course and layout (AC-1, AC-4).

1. Open `/ai/learn`.
2. Open each of the eight lessons at 1440 px, and again at 1024 px.
3. Run the audit:

   ```sh
   node bench/ui-audit.mjs --only learn,learn/running-models/one-model-many-users,learn/running-models/streaming-an-answer,learn/running-models/choosing-a-model-and-provider,learn/answers-you-can-trust/structured-output-and-validation,learn/answers-you-can-trust/retrieval-with-citations,learn/answers-you-can-trust/caching-answers,learn/agents/a-tool-loop-with-caps-and-confirmation,learn/agents/mcp-and-a-research-agent
   ```

Expected:
- `/ai/learn` lists eight playable lessons, none marked "coming soon".
- Every scene is readable, with nothing overlapping or cut off.
- The audit exits 0.

## Step 2

Interaction (AC-5, NFR-2).

1. In each lesson, walk all steps using only the keyboard.
2. Turn on reduced motion and repeat on one lesson.
3. Measure the frame timing on each lesson's busiest step.

Expected:
- Steps, R and Space work in every lesson.
- With reduced motion, nothing moves.
- Progress shows 8 lessons.
- The median frame time is ≤ 20 ms.

## Step 3

Accuracy (NFR-1).

1. Read the review record in tasks.md.
2. Pick one number per lesson and find it in its result file.

Expected: every number you check matches its result file exactly.
