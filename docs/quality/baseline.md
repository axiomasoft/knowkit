# Retrieval quality baseline

Corpus **1.0.1**, annotation **1.0.1**, schema `evaluation-corpus:1` pins 18 original
project-authored TXT/Markdown sources, IR/chunkset fingerprints, and 18 questions.
Rights are approved for repository-local use under `LicenseRef-KnowKit-Private`;
redistribution is not granted. Every source has license, origin and SHA-256 in
`tests/fixtures/evaluation/manifest.v1.json`. Donor code decisions and verified
hashes are in `tests/evaluation/evidence/P5.4-reuse.json`; donor scores are not used.

Run from the development checkout with Composer development dependencies and
the disposable PostgreSQL 16/17 test database described by the existing tooling:

```sh
php tools/evaluate.php --corpus tests/fixtures/evaluation/manifest.v1.json --profile postgres-fts-simple-v1 --output .phpunit.cache/P5.4/baseline.json
```

The command creates a private random schema, applies the real migration, builds
the actual PostgreSQL lexical index, runs production `Selections` and
`Disclosures`, and drops the schema. The output directory must exist. Test DB
configuration comes from the existing `TEST_PG_*` environment; credentials and
machine paths never enter reports. Missing backend/dependencies, invalid corpus,
unknown/unqualified profile, failed threshold or failed output write exits
nonzero. Mandatory cases are never skipped. This is development tooling, not a
production query endpoint.

The lexical profile uses `pg_catalog.simple`, exact technical identifiers and
phrases, and literal CJK matching. It promises neither RU/EN stemming nor CJK
morphology. Cross-language cases use bilingual canonical text and exact literals;
they do not measure automatic translation or semantic cross-language retrieval.
The wrong-version phrase exists only in an excluded immutable source-version
fixture. The denied hard negative and the foreign tenant contain stronger lexical
overlap than allowed evidence. They must yield no citations.

Each question pins source/version/revision, document, segment or physical page,
UTF-8 byte span, digests and relevance grade. Matching compares these identities
and canonical bytes exactly; case folding, substring matching or normalized text
cannot establish provenance. Unrelated but canonical evidence earns no relevance
gain. Forged identity/locator/bytes is a provenance violation. An explicit hard
negative fails qualification even if recall/ranking would pass. Recall counts each
gold evidence once; repeated citations cannot replace missing evidence. nDCG uses
`(2^grade - 1) / log2(rank + 1)`, with zero gain for repeated evidence, at cutoff
10. These are metrics of delivered evidence after the production selection byte
and fragment budgets, not unbounded backend candidates. Source recall deduplicates
source-version IDs; fragment recall uses the annotated evidence records.

| Acceptance measure | Required |
| --- | ---: |
| Fragment recall@10 | ≥ 0.90 |
| nDCG@10 | ≥ 0.80 |
| Exact ID/phrase recall@10 | 1.00 |
| Source/version/revision/locator accuracy | 1.00 |
| UTF-8 boundary validity | 1.00 |
| Persisted declared coverage matches annotated IR | 1.00 |
| Markdown table header/unit/note retention | ≥ 0.95 |
| Refusal/no-evidence outcome correctness | ≥ 0.95 |
| Tenant leaks, forged provenance, post-revoke disclosure | 0 |
| Denied/unknown or unavailable citations, unexpected errors | 0 |

Expected `empty`, `denied`, `unknown`, and `unavailable` remain distinct. Missing
canonical objects, unknown/denied authority, and a committed local revoke are
exercised at the real disclosure boundary. Revocation is committed between
selection and disclosure and remains committed for the warm attempt. Failed
cases stay in the report and fail qualification; they are never excluded to
improve an average. The report includes sample counts per metric so null metrics
on legitimate no-evidence cases are visible.

The eight-page original `sources/structure.pdf` includes licensed text, scan,
mixed content, columns, rotation, blank and multipage table cases. Its separate
`extraction.v1.json` pins expected page text/order/spans, original-page references,
units/notes, coverage and uncertainty. The scanner image is a synthetic pattern,
not a promise of readable OCR text. PDF/OCR and external semantic profiles are
explicitly **unqualified** here. Baseline coverage measures persistence of the
preannotated IR; it is not a PDF/OCR accuracy measurement. The Markdown table
metric covers retained source bytes, not inferred PDF table structure.

Each question runs twice in the same connection/schema: cold is its first
execution, warm the immediate repeat. Setup is excluded; OS and database caches
are not flushed. Reports include corpus/config/implementation fingerprints,
seed 54, PHP/PostgreSQL versions, Composer lock digest, model/tokenizer absence,
dataset size, per-question and per-language/type/phase metrics, p50/p95 total and
selection/disclosure latency. Provider calls/tokens are zero for this local path;
infrastructure monetary cost is uninstrumented and reported as null, not free.
Timing is informational on this unnamed local workload, with no portable SLA or
population confidence claim.

The executor's initial measurement on PHP 8.4.1 / PostgreSQL 16.14 executed all
18 questions twice: source/fragment recall@10, nDCG@10, exact recall, locator and
UTF-8 accuracy, coverage, table retention and refusal correctness were 1.0, with
all violation/error counters zero. Local p50/p95 were approximately 170/220 ms;
reproduce timings on the intended reference workload before setting a performance
target. The local report is `.phpunit.cache/P5.4/baseline.json`.

`quality:retrieval` already selects the Evaluation PHPUnit suite. Its required-case
manifest maps every corpus scenario plus `R24-A01`, `R24-A02`, `R24-A03` and
`DT-Q09` to actual tests. The evaluator also emits these four audit IDs with their
measured outcomes. Runner regression tests reject unknown groups, missing files,
empty/skipped/failed/duplicate execution and failed nested commands. New gold
digests require a corpus/annotation version change with a behavior rationale.
Version 1.0.1 corrects an invented nullable bbox annotation: the existing locator
serializer omits absent bbox; texts, IDs, byte ranges and grades are unchanged.
