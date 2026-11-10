#!/usr/bin/env bash
## Usage: bench/datasets/stanford-cars.sh --accept-terms
## Downloads the Stanford Cars dataset (Krause et al., 2013) into storage/app/datasets/stanford-cars/ (gitignored
## by storage/app/.gitignore). Without --accept-terms it prints the terms and exits 1 without touching the network.
## Source: the Hugging Face mirror tanganke/stanford_cars, pinned to one commit (the original Stanford URL is no
## longer served). The train and test parquet shards are fetched; each file is verified against the sha256 recorded
## below. A file that is already present and verified is skipped, so a second run downloads nothing.
## Terms, source, checksums, size and date checked: docs/datasets/stanford-cars.md.
## After the checksum loop the shards are converted into classes.csv (file,class_name) and images/<file>.jpg, the
## layout photos:import reads, by stanford-cars-convert.py in a pinned python:3.12-slim container (digest below,
## pyarrow installed inside it; nothing is installed on the host). A target that already has classes.csv and
## images/ is not converted again.
## Env: STANFORD_CARS_DIR (target folder), STANFORD_CARS_MANIFEST (a file of "<sha256> <size> <path>" lines that
##      replaces the built-in list; used by tests), STANFORD_CARS_BASE_URL (replaces the pinned mirror URL).
## Exit: 0 done, 1 terms not accepted, 2 download or checksum failure, 3 conversion failure.

set -Eeuo pipefail

APPROOT="${BENCH_APPROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
TARGET="${STANFORD_CARS_DIR:-$APPROOT/storage/app/datasets/stanford-cars}"
COMMIT=9abf6cf7d6dfa7b95152a0d6e791ea9435b47a40
BASE_URL="${STANFORD_CARS_BASE_URL:-https://huggingface.co/datasets/tanganke/stanford_cars/resolve/$COMMIT}"
TERMS_URL=https://ai.stanford.edu/~jkrause/cars/car_dataset.html
PYTHON_IMAGE=python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b
PYARROW=pyarrow==21.0.0
CONVERTER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

manifest() {
  if [[ -n "${STANFORD_CARS_MANIFEST-}" ]]; then
    cat "$STANFORD_CARS_MANIFEST"
    return
  fi
  cat <<'LIST'
b35f21fdeda40973f3b3e1d17bd7a0d2baf8ef249777fc0a0ea7c33c63fa718e 503707028 data/train-00000-of-00002.parquet
b8fc512aac709672c09f17a1b5ee7ae47585894ca52be753cd4e3af726fdef77 484975142 data/train-00001-of-00002.parquet
ddb15e6a44573203cc1be170be2f05d1e34b22532d37579a6d53cfd05c21485a 513268211 data/test-00000-of-00002.parquet
22c54046e4beaeb01d4a6daa72a39c5af50feda6850dbf2b0f784cabf865ccaa 473601440 data/test-00001-of-00002.parquet
LIST
}

print_terms() {
  cat <<TERMS
Stanford Cars dataset - terms of use
  Source of the data: $TERMS_URL
  The dataset is provided for research and non-commercial use. The images belong to their owners.
  Cite: Krause et al., "3D Object Representations for Fine-Grained Categorization", 2013.
  Download mirror: $BASE_URL
  Nothing downloaded is ever committed to git.
Run again with --accept-terms to confirm you accept these terms and start the download.
TERMS
}

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

accepted=0
for arg in "$@"; do
  case "$arg" in
    --accept-terms) accepted=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 1 ;;
  esac
done

if [[ $accepted -ne 1 ]]; then
  print_terms
  exit 1
fi

mkdir -p "$TARGET"

while read -r sum size path; do
  [[ -z "$sum" ]] && continue
  dest="$TARGET/$path"
  if [[ -f "$dest" ]] && [[ "$(sha256_of "$dest")" == "$sum" ]]; then
    echo "skip $path (verified)"
    continue
  fi
  mkdir -p "$(dirname "$dest")"
  echo "download $path ($size bytes)"
  if ! curl -fL --retry 3 --max-time 3600 -o "$dest.part" "$BASE_URL/$path"; then
    rm -f "$dest.part"
    echo "download failed: $path" >&2
    exit 2
  fi
  if [[ "$(sha256_of "$dest.part")" != "$sum" ]]; then
    rm -f "$dest.part"
    echo "checksum mismatch: $path" >&2
    exit 2
  fi
  mv "$dest.part" "$dest"
  echo "verified $path"
done < <(manifest)

if [[ -f "$TARGET/classes.csv" && -d "$TARGET/images" ]]; then
  echo "skip conversion (classes.csv and images/ present)"
  exit 0
fi

echo "convert parquet to images/ and classes.csv ($PYTHON_IMAGE)"
if ! docker run --rm \
  -v "$CONVERTER_DIR:/convert:ro" \
  -v "$TARGET:/dataset" \
  "$PYTHON_IMAGE" \
  sh -c "pip install --quiet --no-cache-dir --disable-pip-version-check $PYARROW && python -I /convert/stanford-cars-convert.py /dataset"; then
  echo "conversion failed" >&2
  exit 3
fi
