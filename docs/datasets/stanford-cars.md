# Stanford Cars dataset

Photos for the lab's vision extraction (SL-27). The dataset is downloaded by the learner, never committed.

## Terms

- Dataset: Stanford Cars, Krause, Stark, Deng, Fei-Fei, "3D Object Representations for Fine-Grained Categorization", 2013.
- Terms page: https://ai.stanford.edu/~jkrause/cars/car_dataset.html
- Use: research and non-commercial; the images belong to their owners.
- The Hugging Face mirror's dataset card declares no licence of its own, so the original terms apply.
- `bench/datasets/stanford-cars.sh` prints these terms and refuses to run without `--accept-terms`.

## Source

- The original Stanford download URL is no longer served.
- Mirror: https://huggingface.co/datasets/tanganke/stanford_cars, pinned to commit `9abf6cf7d6dfa7b95152a0d6e791ea9435b47a40` (2024-05-02).
- Date checked: 2026-11-11.
- Files fetched (parquet, images embedded, class names as labels such as `Ford Fiesta Sedan 2012`):

| File | Size (bytes) | sha256 |
|------|--------------|--------|
| `data/train-00000-of-00002.parquet` | 503707028 | `b35f21fdeda40973f3b3e1d17bd7a0d2baf8ef249777fc0a0ea7c33c63fa718e` |
| `data/train-00001-of-00002.parquet` | 484975142 | `b8fc512aac709672c09f17a1b5ee7ae47585894ca52be753cd4e3af726fdef77` |
| `data/test-00000-of-00002.parquet` | 513268211 | `ddb15e6a44573203cc1be170be2f05d1e34b22532d37579a6d53cfd05c21485a` |
| `data/test-00001-of-00002.parquet` | 473601440 | `22c54046e4beaeb01d4a6daa72a39c5af50feda6850dbf2b0f784cabf865ccaa` |

- Total: 1,975,551,821 bytes (about 1.9 GB).
- The checksums are the Git LFS object ids published by the mirror for that commit.

## Conversion

- After the checksum loop the script runs `bench/datasets/stanford-cars-convert.py` in a container, so nothing is installed on the host.
- Image: `python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b` (multi-arch index digest, checked 2026-11-11); `pyarrow==21.0.0` is installed inside the container, which needs network access.
- Output in the target folder: `images/<shard>-<row>.jpg` (the embedded image bytes) and `classes.csv` (`file,class_name`), the layout `photos:import` reads.
- Class names come from the Hugging Face feature metadata in the parquet schema (`label.names`), so the integer labels map to names such as `Ford Fiesta Sedan 2012`.
- `classes.csv` is written last, so an interrupted conversion is redone. When `classes.csv` and `images/` exist the conversion is skipped.
- Exit code 3 means the conversion failed.

## Storage

- Target: `storage/app/datasets/stanford-cars/`, ignored by `storage/app/.gitignore` (`*`).
- Idempotent: a verified file is skipped on a second run; a failed or mismatched download is removed.
- The build and the test suite never download it; tests use generated fixtures.
