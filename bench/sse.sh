#!/usr/bin/env bash
## Usage: bench/sse.sh [listing-id]
##        bench/sse.sh --analyse <file>
## Streams GET /listings/<listing-id>/describe (default 1) from the active server (`ddev server --url`) with
## curl --no-buffer and timestamps every received line in milliseconds since the curl request started.
## A text event is a line containing "type":"text_delta". Prints one line:
##   status=<http> first_text_ms=<n> last_text_ms=<n> text_events=<n> distinct_ms=<n>
## The stream is unbuffered (AC-8) when the first text event arrives within 1000 ms, the last at 9500 ms or
## later, there are exactly 200 text events (AI_FAKE_TOKENS) and at least 150 distinct arrival times.
## Exit 0 when that holds, 1 when it does not, 2 on a non-200 status or no data.
## --analyse <file> applies the same checks to a saved log with one "<ms><TAB><line>" per line (status 200).
## Env: SSE_BASE_URL (default: `ddev server --url`), SSE_INSECURE=1 adds -k to curl.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

analyse() {
  perl -e '
    my ($status, $file) = @ARGV;
    open(my $fh, "<", $file) or die "cannot read $file: $!\n";
    my (@times, %seen, $lines);
    while (my $row = <$fh>) {
      chomp $row;
      my ($ms, $line) = split /\t/, $row, 2;
      next unless defined $line && $ms =~ /^\d+$/;
      $lines++;
      next unless $line =~ /"type":"text_delta"/;
      push @times, $ms;
      $seen{$ms} = 1;
    }
    my $events = scalar @times;
    my ($first, $last) = $events ? ($times[0], $times[-1]) : ("none", "none");
    my $distinct = scalar keys %seen;
    print "status=$status first_text_ms=$first last_text_ms=$last text_events=$events distinct_ms=$distinct\n";
    exit 2 if $status ne "200" || !$lines;
    exit 0 if $events == 200 && $first <= 1000 && $last >= 9500 && $distinct >= 150;
    exit 1;
  ' "$1" "$2"
}

if [[ "${1:-}" == "--analyse" ]]; then
  [[ -n "${2:-}" && -r "${2:-}" ]] || {
    echo "Usage: $0 --analyse <file>" >&2
    exit 2
  }
  analyse 200 "$2"
  exit $?
fi

ID="${1:-1}"
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
analyse "$STATUS" "$WORK/stream.log"
exit $?
