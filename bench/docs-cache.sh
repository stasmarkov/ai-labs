#!/usr/bin/env bash
## Usage: bench/docs-cache.sh [--label <name>] [--force] [--paraphrases <file>] [--questions <n>]
## Measures the docs answer cache on the first 10 on_topic eval-set questions (bench/rag-eval/questions.v1.jsonl) that
## have a paraphrase in bench/rag-eval/cache-hitmiss-paraphrases.v1.jsonl. Each question is asked three times through
## POST /api/docs/ask: fresh (empty cache), again verbatim (exact), then as a paraphrase (semantic).
## Latency is curl time_total until [DONE]. Writes bench/results/docs-cache/<UTC date>/<label>/hit-miss.json with
## the question ids, the path each request took and the median latency per path, and appends `docs:cache --stats`.
## Empties docs_answer_cache and the cache store first. Waits when needed to stay under the ask rate limit.
## --paraphrases <file> (default cache-hitmiss-paraphrases.v1.jsonl) and --questions <n> (default 10) select the paraphrase set
## and how many questions to ask. The NFR-1 run uses cache-latency-paraphrases.v1.jsonl with --questions 18, so that at least
## 10 stored, cited entries give 10 exact and 10 semantic hits (an answer without citations is not stored).
## Env: DOCS_CACHE_BASE_URL (default: `ddev describe` project URL https://ai-lab.ddev.site)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="${DOCS_CACHE_BASE_URL:-https://ai-lab.ddev.site}"
LABEL="hit-miss"
FORCE=0
LIMIT=10
PARAPHRASE_FILE="cache-hitmiss-paraphrases.v1.jsonl"
QUESTION_COUNT=10

while [ $# -gt 0 ]; do
  case "$1" in
    --label) LABEL="$2"; shift 2 ;;
    --label=*) LABEL="${1#--label=}"; shift ;;
    --force) FORCE=1; shift ;;
    --paraphrases) PARAPHRASE_FILE="$2"; shift 2 ;;
    --paraphrases=*) PARAPHRASE_FILE="${1#--paraphrases=}"; shift ;;
    --questions) QUESTION_COUNT="$2"; shift 2 ;;
    --questions=*) QUESTION_COUNT="${1#--questions=}"; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

DAY="$(date -u +%Y-%m-%d)"
OUT="$ROOT/bench/results/docs-cache/$DAY/$LABEL"
if [ -e "$OUT/hit-miss.json" ] && [ "$FORCE" -ne 1 ]; then
  echo "$OUT/hit-miss.json exists; pass --force to overwrite" >&2
  exit 2
fi
mkdir -p "$OUT"

QUESTIONS="$ROOT/bench/rag-eval/questions.v1.jsonl"
PARAPHRASES="$ROOT/bench/rag-eval/$PARAPHRASE_FILE"
ROWS="$(mktemp)"
trap 'rm -f "$ROWS"' EXIT

(cd "$ROOT" && ddev artisan tinker --execute 'DB::table("docs_answer_cache")->delete(); Cache::store(config("ai.docs.cache.store"))->flush();' >/dev/null)

STARTS=()

pace() {
  local now oldest wait
  now="$(date +%s)"
  if [ "${#STARTS[@]}" -ge "$LIMIT" ]; then
    oldest="${STARTS[$((${#STARTS[@]} - LIMIT))]}"
    wait=$((oldest + 61 - now))
    if [ "$wait" -gt 0 ]; then sleep "$wait"; fi
  fi
  STARTS+=("$(date +%s)")
}

ask() {
  local id="$1" step="$2" text="$3" body hit kind seconds status
  pace
  body="$(mktemp)"
  read -r status seconds < <(curl -sk -N -o "$body" -w '%{http_code} %{time_total}\n' \
    -H 'Content-Type: application/json' \
    -d "$(jq -nc --arg q "$text" '{q: $q}')" "$BASE/api/docs/ask")
  hit="$(grep -c '"type":"cache_hit"' "$body" || true)"
  kind="miss"
  if [ "$hit" -gt 0 ]; then
    kind="$(grep -o '"kind":"[a-z]*"' "$body" | head -1 | cut -d'"' -f4)"
  fi
  jq -nc --arg id "$id" --arg step "$step" --arg path "$kind" --argjson status "$status" \
    --argjson ms "$(awk -v s="$seconds" 'BEGIN { printf "%d", s * 1000 + 0.5 }')" \
    --argjson done "$(grep -c '\[DONE\]' "$body" || true)" \
    '{id: $id, step: $step, path: $path, status: $status, ms: $ms, done: ($done > 0)}' >>"$ROWS"
  rm -f "$body"
}

ids="$(jq -r 'select(.type == "on_topic") | .id' "$QUESTIONS")"
count=0
while IFS= read -r id; do
  para="$(jq -r --arg id "$id" 'select(.id == $id) | .paraphrase' "$PARAPHRASES")"
  [ -n "$para" ] || continue
  question="$(jq -r --arg id "$id" 'select(.id == $id) | .question' "$QUESTIONS")"
  ask "$id" fresh "$question"
  ask "$id" exact "$question"
  ask "$id" paraphrase "$para"
  count=$((count + 1))
  [ "$count" -ge "$QUESTION_COUNT" ] && break
done <<<"$ids"

STATS="$(cd "$ROOT" && ddev artisan docs:cache --stats --no-ansi)"

jq -s --arg label "$LABEL" --arg stats "$STATS" --arg commit "$(git -C "$ROOT" rev-parse HEAD)" \
  --arg at "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)" '
  def median: sort | if length == 0 then null else (length / 2 | floor) as $i | if length % 2 == 1 then .[$i] else (.[$i - 1] + .[$i]) / 2 end end;
  {
    metadata: {label: $label, generated_at: $at, git_commit: $commit, questions: (map(.id) | unique | length), eval_set_version: "v1"},
    paths: (group_by(.step) | map({key: .[0].step, value: {
      requests: length,
      by_path: (group_by(.path) | map({key: .[0].path, value: length}) | from_entries),
      median_ms_all: (map(.ms) | median),
      median_ms_by_path: (group_by(.path) | map({key: .[0].path, value: (map(.ms) | median)}) | from_entries)
    }}) | from_entries),
    hit_rate: ((map(select(.path != "miss")) | length) / length),
    requests: .,
    stats: $stats
  }' "$ROWS" >"$OUT/hit-miss.json"

echo "Results: $OUT/hit-miss.json"
