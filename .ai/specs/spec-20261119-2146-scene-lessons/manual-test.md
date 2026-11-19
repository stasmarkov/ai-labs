# Manual test plan: AI lab scene lessons (SL-55)

Base URL: https://ai-lab.ddev.site

## Step 1

Course and pilot (AC-1, AC-2, AC-3, AC-6).

1. Open `/ai/learn`.
2. Open the pilot lesson.
3. Walk all 10 steps with Next, then with → and ←.
4. Press R on step 4 and Space twice.
5. Reload on step 6.
6. Finish the lesson, return to `/ai/learn`, and press "reset progress".

Expected:
- The course page shows 3 modules and 8 lessons, with only the pilot playable.
- Each step changes the URL, the scene (queue badges and node loads), the KPI strip and the panel.
- Step 4 shows a long queue at the model. Step 5 shows search p95 jumping on worker mode.
- R replays the animation, and Space pauses and resumes it.
- After the reload the learn list keeps its ticks.
- The finished lesson shows as done in the sidebar. After the reset nothing is ticked.

## Step 2

Accessibility (AC-7, NFR-3).

1. Walk the lesson with the keyboard only.
2. Turn on reduced motion in the OS or the browser's emulation.
3. Listen with VoiceOver, or check the `aria-live` text.

Expected:
- Every control is reachable and has visible focus.
- With reduced motion the packets are static.
- Each step title is announced.

## Step 3

Smoothness (NFR-1). In the browser console on step 4, run a 5 s `requestAnimationFrame` frame-time sample.

Expected: the median frame interval is ≤ 20 ms (≥ 50 fps).

## Step 4

Closure and checks (AC-9, MNC-3).

```sh
grep -n "scene" .ai/specs/spec-20261117-2243-guide-format-variants/decisions.md
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
node bench/ui-audit.mjs --only learn
```

Expected:
- The pick is recorded.
- The fingerprint is unchanged.
- Parity differs only in `captured=`.
- The audit exits 0.
