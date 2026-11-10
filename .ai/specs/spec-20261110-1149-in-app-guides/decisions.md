# Decisions: in-app guides (SL-48)

Self-decided under the owner's standing approval. Each entry lists the alternatives and the critique.

## D-1: Where the guide content lives

**Chosen:** `resources/guides/<key>.php` arrays, rendered by the app.

**Rejected:**
- Parsing `docs/learning-guide.md` sections at runtime. The prose is not split into works, implementation, modify and support, and anchors would turn into a hidden API.
- YAML or Markdown front matter. That needs a parser dependency and is untyped.

**Critique:** PHP arrays are less pleasant to write than Markdown. They are mitigated by Markdown inside the string fields and a strict schema test that names the broken field.

## D-2: How code is shown

**Chosen:** snippets resolved at render time through Reflection (PHP symbols), whole small files, or text anchors.

**Rejected:**
- Copied code blocks, which drift.
- Line ranges, which break on any edit.

**Critique:** anchors can match the wrong place if the text repeats. The first match is used, and the content test asserts each anchor is unique in its file.

## D-3: The file caption is allowed

The owner banned references that replace content. A caption above code that is shown is context. Without it a learner could not find the code in a clone.

## D-4: Experiments keep no server state

Predictions are kept in `localStorage`. There is no table and no auth, so it fits a study lab that anyone can clone.

## D-5: The SL-48 slice is reused

SL-48 ("study-focused UI") already planned guided experiments and how-it-works panels. This request replaces its "link to guide and code" wording, so no new slice id is created. The backlog entry is updated, with an intake row.

## D-6: Duplication with `docs/learning-guide.md` is accepted for now

SL-44 turns the guide's AI sections into short pages that point to the guide files.
