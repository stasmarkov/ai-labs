"""Converts the Stanford Cars parquet shards into the layout photos:import reads.

Usage (inside the pinned container, see stanford-cars.sh): python -I stanford-cars-convert.py <dataset dir>

Reads <dir>/data/*.parquet (columns image{bytes,path} and label, the class names come from the Hugging Face
feature metadata stored in the parquet schema), writes <dir>/images/<shard>-<row>.jpg and, last, <dir>/classes.csv
(file,class_name). classes.csv is renamed into place only when every shard is done, so a half run is redone.
"""

import csv
import json
import os
import sys

import pyarrow.parquet as pq


def class_names(schema):
    meta = (schema.metadata or {}).get(b"huggingface")
    if meta is None:
        raise SystemExit("parquet schema has no huggingface metadata: class names unknown")
    names = json.loads(meta)["info"]["features"]["label"]["names"]
    return names


def main(directory):
    shards = sorted(f for f in os.listdir(os.path.join(directory, "data")) if f.endswith(".parquet"))
    if not shards:
        raise SystemExit("no parquet shards in " + directory + "/data")

    images = os.path.join(directory, "images")
    os.makedirs(images, exist_ok=True)
    part = os.path.join(directory, "classes.csv.part")
    count = 0

    with open(part, "w", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["file", "class_name"])
        for shard in shards:
            parquet = pq.ParquetFile(os.path.join(directory, "data", shard))
            names = class_names(parquet.schema_arrow)
            stem = shard[: -len(".parquet")]
            row = 0
            for batch in parquet.iter_batches(batch_size=256, columns=["image", "label"]):
                for image, label in zip(batch.column("image").to_pylist(), batch.column("label").to_pylist()):
                    name = "%s-%05d.jpg" % (stem, row)
                    with open(os.path.join(images, name), "wb") as out:
                        out.write(image["bytes"])
                    writer.writerow([name, names[label]])
                    row += 1
                    count += 1

    os.replace(part, os.path.join(directory, "classes.csv"))
    print("converted %d images" % count)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: stanford-cars-convert.py <dataset dir>")
    main(sys.argv[1])
