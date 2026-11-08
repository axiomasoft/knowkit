# Hybrid reference discovery

`HybridSearch` accepts a fresh `AccessContext`, trusted `SearchProfile`, query binding,
and `SearchCorpus` for each exact catalog generation. The host authorizes each scope
and loads each inventory before calling it. Both corpora must share the exact snapshot,
eligible versions and policy binding. Hybrid requires the pinned full embedding space
and a pgvector generation. Query text, exact identifiers and filters are preserved.

Default `lexical-v1` makes no vector or reranker request. RRF uses supplied 1-based
ranks with k=60, sorts score descending then chunk ID ascending, removes exact duplicate
refs per channel and omits overlapping canonical units without merging their citations.
Contributions are accumulated in sorted rank order, independent of channel iteration.
Zero lexical scores contribute nothing; negative vector distances remain valid ranks.
The result labels PostgreSQL FTS through `lexical_backend:postgres-fts-simple-v1`;
hosts using another lexical backend must configure its truthful label explicitly.

`allowDegrade` only permits an unavailable optional vector/reranker stage to return
lexical candidates with `degraded=true`, `effectiveProfile=lexical-v1` and the failed
stage in warnings. Empty degraded results keep the same metadata. Denied, unknown,
incompatible, missing pinned inventory and space mismatches never become lexical
success. Mandatory lexical failures and required rerank failures remain failures.

`ConstrainedRerank` bounds whole canonical chunks using the query's selection limits.
It constructs the complete query/evidence egress manifest and calls `PolicyChecks::transmit`
for fresh processing and egress authorization immediately before each transport call.
The transport returns only `{candidateId, score?}` entries; extra fields, invented IDs,
duplicates and nonfinite scores are incompatible. Output order is authoritative and
becomes sequential ranks. Refs and canonical spans are always taken from the input.
Transports must not internally retry and must report timeouts as typed unavailable
failures. No external transport is included by this item.

Results contain references only. The host must pass the result to bounded selection
and the existing fresh disclosure/usage protocol before returning any document text.
This stage does not mint Selection tokens or authorize disclosure. P5.4 can consume
these ordered candidate refs and explicit outcome/profile metadata for evaluation.
