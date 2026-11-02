#!/usr/bin/env bash
## Usage: bench/sse.sh [--real] [listing-id]
##        bench/sse.sh [--real] --analyse <file>
## Streams GET /listings/<listing-id>/describe (default 1) from the active server (`ddev server --url`) with
## curl --no-buffer and timestamps every received line in milliseconds since the curl request started.
## A text event is a line containing "type":"text_delta". Prints one line:
##   status=<http> first_text_ms=<n> last_text_ms=<n> text_events=<n> distinct_ms=<n>
## The stream is unbuffered (AC-8) when the first text event arrives within 1000 ms, the last at 9500 ms or
## later, there are exactly 200 text events (AI_FAKE_TOKENS) and at least 150 distinct arrival times.
## Exit 0 when that holds, 1 when it does not, 2 on a non-200 status or no data.
## --analyse <file> applies the same checks to a saved log with one "<ms><TAB><line>" per line (status 200).
## --real (any position) applies the thresholds for a real model instead: status 200, first non-empty text event
## within 5000 ms, at least 20 text events, at least 40 % of them with a timestamp different from the previous
## text event, and at least 1000 ms between the first and the last. Same exit codes.
## Env: SSE_BASE_URL (default: `ddev server --url`), SSE_INSECURE=1 adds -k to curl.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

analyse() {
  perl -e '
    my ($status, $file, $real) = @ARGV;
    open(my $fh, "<", $file) or die "cannot read $file: $!\n";
    my (@times, %seen, $lines);
    while (my $row = <$fh>) {
      chomp $row;
      my ($ms, $line) = split /\t/, $row, 2;
      next unless defined $line && $ms =~ /^\d+$/;
      $lines++;
      next unless $line =~ /"type":"text_delta"/;
      next if $real && $line =~ /"delta":""/;
      push @times, $ms;
      $seen{$ms} = 1;
    }
    my $events = scalar @times;
    my ($first, $last) = $events ? ($times[0], $times[-1]) : ("none", "none");
    my $distinct = scalar keys %seen;
    my ($changes, $prev) = (0, -1);
    for my $t (@times) { $changes++ if $t != $prev; $prev = $t; }
    # Fake mode: distinct_ms counts different timestamps. Real mode: new_ms counts events whose timestamp differs from
    # the previous text event (Ollama sends up to 3 tokens in the same millisecond, see the guide).
    my $metric = $real ? "new_ms=$changes" : "distinct_ms=$distinct";
    print "status=$status first_text_ms=$first last_text_ms=$last text_events=$events $metric\n";
    exit 2 if $status ne "200" || !$lines;
    if ($real) {
      exit 0 if $events >= 20 && $first <= 5000 && $changes >= 0.4 * $events && $last - $first >= 1000;
      exit 1;
    }
    exit 0 if $events == 200 && $first <= 1000 && $last >= 9500 && $distinct >= 150;
    exit 1;
  ' "$1" "$2" "$3"
}

REAL=0
ANALYSE=""
ID=""
ARGS=("$@")
i=0
while ((i < ${#ARGS[@]})); do
  case "${ARGS[$i]}" in
    --real) REAL=1 ;;
    --analyse)
      i=$((i + 1))
      ANALYSE="${ARGS[$i]:-}"
      [[ -n "$ANALYSE" && -r "$ANALYSE" ]] || {
        echo "Usage: $0 [--real] --analyse <file>" >&2
        exit 2
      }
      ;;
    *)
      [[ "${ARGS[$i]}" =~ ^[1-9][0-9]*$ && -z "$ID" ]] || {
        echo "Usage: $0 [--real] [listing-id] | $0 [--real] --analyse <file>" >&2
        exit 2
      }
      ID="${ARGS[$i]}"
      ;;
  esac
  i=$((i + 1))
done

if [[ -n "$ANALYSE" ]]; then
  analyse 200 "$ANALYSE" "$REAL"
  exit $?
fi

ID="${ID:-1}"
BASE_URL="${SSE_BASE_URL:-$(ddev server --url)}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

CURL_ARGS=(-sSN --max-time 60)
[[ "${SSE_INSECURE:-}" == "1" ]] && CURL_ARGS+=(-k)

START="$(perl -MTime::HiRes=time -e 'printf "%d", time * 1000')"
curl "${CURL_ARGS[@]}" -w '\n__status=%{http_code}\n' "$BASE_URL/listings/$ID/describe" 2>"$WORK/curl.err" |
  START="$START" perl -MTime::HiRes=time -e '
    $| = 1;
    while (my $line = <STDIN>) {
      chomp $line;
      printf "%d\t%s\n", time * 1000 - $ENV{START}, $line;
    }
  ' >"$WORK/raw.log" || true

STATUS="$(sed -n 's/^[0-9]*\t__status=//p' "$WORK/raw.log" | tail -1)"
STATUS="${STATUS:-000}"
grep -v $'^[0-9]*\t__status=' "$WORK/raw.log" >"$WORK/stream.log" || true

if [[ "$STATUS" != "200" && -s "$WORK/curl.err" ]]; then
  cat "$WORK/curl.err" >&2
fi

set +e
analyse "$STATUS" "$WORK/stream.log" "$REAL"
exit $?
