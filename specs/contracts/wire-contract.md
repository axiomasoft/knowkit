# KnowKit wire contract 1.0

Status: normative for P1.4 and every later item that reads or writes a KnowKit wire document.
Sources: `knowledge/contracts`, `document-ir`, `access`, `locks`, `publication`, `disclosure`,
`jobs`, `deletion`, `storage`, `lifecycle`, `events`, `api`, `retrieval`, `citations`, `semantic`,
`worker` and `extensibility`. `specs/architecture/architecture.md` stays the higher authority. This
document defines how the bundled schemas, the canonical bytes and the golden corpus fit together. It
adds no new architecture.

| Artifact | Path |
|---|---|
| JSON Schemas (2020-12, offline) | `schemas/<name>.schema.json`, `$id` `urn:knowkit:schema:<name>:1.0` |
| Hash-domain table | `specs/contracts/hash-domains.v1.json` |
| Error registry | `specs/contracts/error-codes.v1.json` |
| PHP reference (vendor-free core) | `packages/core/src/Domain/Contract/` (`KnowKit\Domain\Contract`) |
| Python reference (stdlib only) | `tests/contract/schemas/knowkit_contract.py` |
| Golden corpus | `tests/fixtures/contracts/manifest.v1.json`, `tests/fixtures/contracts/v1/**` |
| Required cases | `tests/fixtures/check-groups/contract-schemas.json` (QA group `contract:schemas`) |

## 1. Schema set

The bundle has 29 schemas. `common` holds the shared `$defs`; it is never a document root.

- Identity and authority: `scope`, `query-grant`, `authorized-search-scope`, `decision-receipt`,
  `mutation-context`.
- Commands and responses: `result` (the `Result<T>` envelope), `disclosure-request`, `egress-request`.
- Content: `document-ir` (IR 1.0), `citation-ref`, `legacy-evidence-ref`, `evidence-set`.
- Retrieval and disclosure: `selection`, `usage`.
- Manifests and publication: `index-manifest`, `publish-receipt`, `embedding-space`.
- Jobs: `job` (the durable Job record), `job-envelope`, `worker-result`.
- Events and recovery: `domain-event`, `restore-receipt`.
- Deletion: `deletion-task`, `deletion-attempt` (the per-resource deletion receipt).
- Corpus infrastructure: `fixture-manifest`, `hash-vectors`, `locator-vectors`, `binding-vectors`.

Field names follow the knowledge records exactly. Aliases such as `inputFile`, `ir{path,digest,size}`
and `assets[].size` are unknown fields. Where the records left a shape open, P1.4 settled it as
follows:

- `Page` and `Segment` carry `textDigest` = SHA256 of their raw UTF-8 text. A citation span is a
  `TextLocator` plus that `textDigest` (`knowledge/citations`).
- `CitationRef` also binds `tenantId` and `sourceId` (ARCH-CITATION). When `origin=generated` it
  needs `supportingRefs`.
- `Block.table` holds tables: `rows`, `columns`, and `cells[]` with
  `{cellId,row,column,rowSpan,colSpan,header,headerRefs,unit,noteRefs,spans}`. Image blocks carry
  `assetRefs`.
- Generated text (for example image descriptions) is stored in a `segment`, never as a page quote.
- `Selection`, `Usage` and `EvidenceSet` store `hostScopeHash` verbatim. `Selection` also stores
  `tenantId` so the binding check works.
- `Selection.runRef` is the trusted run binding: the `usageContext.runRef` that the host or bridge
  supplied when the selection was created, or `null` for a direct library call. It is required (the
  binding cannot be dropped), stored verbatim, and kept outside the `scopeBinding` payload, so
  `scopeBindingDigest` and `hostScopeHash` are unchanged by it (section 10).
- `DomainEvent.payload` has one closed shape per event type (section 9a).
- `Job` follows the `knowledge/jobs` record: `jobId`, `tenantId`, `kbId`, `sourceId`,
  `sourceVersionId`, nullable `generationId`, `type`, `state`
  (`pending|leased|succeeded|failed|cancelled`), `attempt` (`0..5`), decimal-string `fence`, nullable
  `leaseTokenHash`, `leaseUntil`, `attemptDeadline` and `checkpointDigest`, and `availableAt`. Every
  member is required; nullable members are written as `null`. A `leased` job has `attempt >= 1` and
  non-null `leaseTokenHash`, `leaseUntil` and `attemptDeadline`. Only the token hash is stored. `type`
  is a bounded lower snake_case code; P1.4 does not fix a job type enum. Extraction `partial` is not a
  job state. The queue runtime is out of scope.
- `EgressRequest.items[].category` takes one of `QUESTION`, `HISTORY`, `EVIDENCE`, `ASSET`,
  `SYSTEM_INSTRUCTION`, `TOOL_OUTPUT`, `QUERY`, `DOCUMENT_CONTENT`.

## 2. Required and unknown fields

- Every object lists its required members explicitly.
- Every boundary object is closed with `unevaluatedProperties: false`. A member that no applicable
  subschema evaluated is rejected as `unknown_field` at its own path.
- Discriminated unions use `if`/`then` on a `const` discriminator. The page/segment `TextLocator` is
  an example. A member that a branch forbids is set to `false` and is rejected as `forbidden_field`:
  a segment locator with `physicalNo`, `pageId`, `printedLabel` or `bbox`; a page locator with
  `segmentId`; a legacy reference with a renamed digest.
- Optional members are simply not listed in `required`. `null` is accepted only where the record says
  `|null`.
- `metadata` is a bounded namespaced map:
  - at most 32 keys;
  - each key matches `^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){1,3}$`;
  - each value is a string of up to 256 characters, an integer or a boolean.

## 3. Primitive encodings

| Kind | Encoding |
|---|---|
| Object IDs | Canonical uppercase ULID `^[0-7][0-9A-HJKMNP-TV-Z]{25}$`; each ID type is distinct |
| Local IDs (block, page, segment, cell, asset) | `^[A-Za-z0-9._:-]{1,128}$` |
| SHA-256 | 64 lowercase hex characters |
| `hostScopeHash` | Trusted opaque 64 lowercase hex characters; never recomputed or retagged |
| Epoch, fence, pointer version, sequence | Decimal string without leading zero, `<= 9223372036854775807` (`decimal_out_of_range`) |
| `uint64` sizes | Decimal string `<= 18446744073709551615` |
| Byte offsets, counts | JSON integer `0..4294967295` |
| Instants | UTC `YYYY-MM-DDTHH:MM:SS[.ffffff]Z`; offsets are rejected |
| Bbox | `{space:"page-millionths-top-left-v1",origin:"top-left",view:"rotated-page",x0,y0,x1,y1}`, integers `0..1000000`, `x0<x1`, `y0<y1` (`invalid_bbox`) |
| Error codes (`Result.error.code`) | Approved codes in their original case: lower `snake_case` (`scope_mismatch`) or `UPPER_SNAKE_CASE` (`POLICY_CHANGED`, `RETRYABLE_LOCK_TIMEOUT`, `POINTER_CONFLICT`, `HOLD_TOO_LATE`); mixed case and hyphens are rejected and no case conversion is applied. The approved codes and their outcomes are listed in `error-codes.v1.json#outcomeCodes` |
| Business purpose | Namespaced `lesson.read`. A bare `Operation` value such as `retrieve` is rejected, which keeps the purpose separate from the `Operation` enum |

## 4. Strict JSON

Decoding happens before any schema check. It follows these rules, in this order. Both reference
implementations report the same code; for decode errors the path is `""`.

1. More bytes than the schema's `x-knowkit-maxDocumentBytes` gives `document_too_large`. When a
   schema sets no limit, the default is 1 MiB.
2. Bytes that are not well-formed UTF-8 give `invalid_utf8`. CESU-encoded surrogates count as
   ill-formed, and a BOM gives `invalid_json`.
3. Bracket nesting deeper than 64 levels gives `depth_exceeded`.
4. A syntax error, `NaN`, `Infinity`, a raw control character in a string, a leading zero or a
   trailing comma gives `invalid_json`.
5. A number with a fraction or exponent gives `non_integer_number`. An integer outside ±(2^53−1)
   gives `unsafe_integer`. Larger values travel as decimal strings.
6. An object member name that repeats after unescaping gives `duplicate_key`. Duplicates are checked
   when the object closes.
7. A lone UTF-16 surrogate escape gives `invalid_surrogate`. This is checked after a successful parse.

## 5. Canonical bytes and digests

JCS (RFC 8785):

- Keys are sorted by UTF-16 code units.
- Strings are written literally, except `"`, `\` and C0 controls. Those use `\b \t \n \f \r` or
  lowercase `\u00XX`. `/` and U+2028 are not escaped.
- There is no Unicode normalization: `é` and `e`+U+0301 give different bytes and different digests.
- Arrays keep their order. Spans and reading order are never sorted.
- The KnowKit profile has no floats, so number serialization reduces to decimal integers.

Digest kinds:

- `irDigest` = `SHA256(UTF8(JCS(IR)))`, written as plain hex with no prefix. The IR never contains its
  own digest.
- Typed fingerprints = `SHA256(ASCII(domain) || 0x00 || UTF8(JCS(payload)))`. The only allowed domains
  are the 23 in `hash-domains.v1.json`.
- A self-digest field (`resultDigest`, `receiptDigest`, `evidenceSetDigest`) is removed from the
  top-level payload before hashing. `payloadDigest` covers only `payload`.
- Raw digests cover the exact bytes: source bytes, page and segment text, assets, and emitted content.
  `contentDigest` and `fragmentDigest` are computed over the bytes that were actually emitted,
  including any joiners.
- `scopeBindingDigest` uses domain `scopeBinding` over
  `{tenantId,kbId,actorId,purpose,hostScopeHash,policyBindingDigest}`.
- `policyBindingDigest` uses domain `policyBinding` over the `PolicyBinding` object.

Legacy hashes, for example the AgenTools release hash computed as `SHA256(json_encode(...))`, keep
their original type in `legacy-evidence-ref` (`legacyHashType`, `legacyReleaseHash`,
`provenance=legacy_unverified`, `freshlyValidated=false`). They are never renamed into `sourceDigest`,
`irDigest`, `contentDigest` or a fingerprint, and they have no hash domain.

## 6. Version negotiation

- Versioned documents carry `schemaVersion`: `ir-1.0`, `index-manifest-1.0`, `job-envelope-1.0`,
  `worker-result-1.0`, `event-1.0`, `fixture-manifest-1.0`, `*-vectors-1.0`.
- A consumer supports an explicit set, which is `{1.0}` in this release. A string `schemaVersion`
  outside that set (another major or minor version) is answered with `unsupported_schema_version` at
  `/schemaVersion`, class `incompatible`, and nothing else is validated. A newer minor version is not
  treated as a superset, because closed objects would otherwise accept fields silently.
- A missing or non-string `schemaVersion` is a normal schema error (`required` or `type`).
- Unversioned DTOs (`Scope`, `DisclosureRequest`, `Result`, and so on) are versioned by their schema
  `$id`. The transport selects the `$id`, and any change creates a new `$id` major version.
- Unsupported capabilities, such as a space mismatch or a missing tokenizer, are outcomes of class
  `incompatible`. They are never a fallback.

## 7. Size limits

| Document | `x-knowkit-maxDocumentBytes` |
|---|---|
| document-ir | 64 MiB (page text ≤ 4 Mi code points, ≤ 1000 pages, ≤ 200000 blocks, ≤ 2000 assets) |
| index-manifest | 16 MiB |
| deletion-task, fixture-manifest, hash-vectors | 4 MiB |
| result, decision-receipt, egress-request, selection, evidence-set, worker-result, locator/binding vectors | 1 MiB |
| query-grant, authorized-search-scope, disclosure-request, citation-ref, usage | 256 KiB |
| job-envelope, domain-event | 64 KiB |
| job | 16 KiB |
| legacy-evidence-ref, restore-receipt, deletion-attempt | 16 KiB |
| scope, mutation-context, publish-receipt, embedding-space | 4 KiB |

Collection bounds:

- `allowedSourceVersionIds` and policy sources: ≤ 4096.
- Chunk refs per request: ≤ 64.
- Selection candidates: ≤ 256.
- Fragments: ≤ 64, and `maxBytes` ≤ 262144.
- Diagnostics: ≤ 1024.
- Job limits: `wallSeconds` and `cpuSeconds` ≤ 900, `maxPages` ≤ 1000, `maxAssets` ≤ 2000. These are
  global ceilings; the effective values come from the minimum rule in `knowledge/jobs`.

## 8. Schema subset and extensions

Both evaluators accept only these keywords:

- Core: `$schema $id $defs $ref $comment title description`.
- Assertions: `type const enum required properties additionalProperties propertyNames min/maxProperties
  unevaluatedProperties(false only) items min/maxItems uniqueItems contains min/maxLength pattern
  minimum maximum`.
- Applicators: `allOf if then else`.

Any other keyword is a schema-definition error. The rules that keep PHP and Python identical:

- Patterns are fully anchored `^…$`, with no top-level alternation. PHP matches with `D` and `u`;
  Python uses `fullmatch`.
- Lengths count code points.
- A failed `type` stops evaluation of that schema object.
- `properties` and `additionalProperties` mark members as evaluated whether or not the member's value
  is valid. A passing `if` contributes its annotations; `then`/`else`, `allOf` and `$ref` merge theirs.

Annotations (ignored by standard validators, enforced by both references):

- `x-knowkit-decimalMax`: `decimal_out_of_range`.
- `x-knowkit-lessThan {code,pairs,orEqual?}`: `invalid_bbox`, `invalid_span`, `limit_exceeded`.
- `x-knowkit-uniqueBy`: `duplicate_identifier`.
- `x-knowkit-sortedBy`: strictly increasing tuples, otherwise `unsorted_members`.
- `x-knowkit-schemaVersion`: see section 6.
- `x-knowkit-maxDocumentBytes`: see section 7.

Errors are deduplicated and sorted by `(instance path bytes, code bytes)`. The first error is the
primary error, and the corpus states it exactly.

<a id="event-payloads"></a>
## 9a. Event payloads

`DomainEvent.payload` is closed for each fixed v1 type. Each shape is a projection of the normative
record named below, with the exact field names of that record. Members already carried by the
envelope (`tenantId`, `eventId`) are not repeated. Payloads carry refs, epochs and digests only; an
unknown member such as `documentText`, `credentials` or a signed URL is `unknown_field` at its path,
even when `payloadDigest` was recomputed. A payload that does not match its `type` fails the same way.

| `type` | Payload members | Source record |
|---|---|---|
| `source.registered` | `sourceId`, `lifecycleState=active`, `lifecycleEpoch`, `sourcePolicyEpoch`, `retentionHold` | lifecycle `Source` |
| `source_version.accepted` | `sourceId`, `sourceVersionId`, `sourceDigest` | contracts `ExactSourceRef` |
| `document_revision.accepted` | `sourceId`, `sourceVersionId`, `sourceDigest`, `documentRevisionId`, `irDigest`, `parserSignature` | contracts `DocumentRevisionRef` |
| `generation.sealed` | `kbId`, `generationId`, `manifestDigest` | publication `IndexManifest` identity |
| `snapshot.activated` | `kbId`, `snapshotId`, `manifestDigest`, `pointerVersion` | contracts `SnapshotRef` + `PublishReceipt` |
| `source_version.corrected` | `sourceVersionId`, `usability=corrected`, `correctedByVersionId` | lifecycle `SourceVersionState` |
| `source.revoked` | `sourceId`, `reason`, `lifecycleEpoch`, `appliesTo=future_versions` | lifecycle `Tombstone` |
| `source.deletion_requested` | `sourceId`, `lifecycleState=deletion_requested`, `lifecycleEpoch` | lifecycle `Source` |
| `retention_hold.changed` | `sourceId`, `retentionHold` | lifecycle `Source` |
| `deletion.attempted` | the whole `deletion-attempt` record | deletion `DeletionAttempt` |
| `deletion.completed` | `taskId`, `sourceId`, `inventoryVersion`, `inventoryDigest`, `status` (`done|partial|unknown|unsupported`) | deletion `DeletionTask` |
| `usage.prepared_disclosure` | `usageId`, `disclosureAttemptId`, `selectionId`, `actorId`, `purpose`, `hostScopeHash`, `runRef`, `fragmentRefs`, `checkedAt`, `policyBindingDigest` | disclosure `Usage` |
| `usage.provider_accepted` | `usageId`, `disclosureAttemptId` | identity of the existing `Usage` |

<a id="semantic-rules"></a>
## 9. Semantic rules

These rules run only after schema validation succeeds.

**document-ir**

- Each page and segment `textDigest` matches its text (`digest_mismatch`).
- `ok` requires non-empty text. `empty`, `failed` and `skipped` require `""`
  (`page_status_mismatch`). A failure is never shown as `empty`.
- `physicalNo` is strictly increasing and block `order` is strictly increasing (`order_violation`).
- `parentId` must exist (`unknown_reference`), must not point to the block itself (`self_reference`)
  and must not form a cycle (`reference_cycle`).
- `supportingRefs` point to blocks or assets. `assetRefs`, `headerRefs` (to header cells),
  `noteRefs` and `asset.pageId` must resolve.
- Table cells stay inside `rows` × `columns` (`table_cell_out_of_range`).
- Every locator matches its block (`binding_mismatch`) and a page or segment that exists. Its
  `physicalNo` and `printedLabel` equal the page's (`binding_mismatch`). `byteEnd` is within the text
  length (`span_out_of_range`), and both offsets sit on UTF-8 code-point boundaries
  (`utf8_boundary`). Code-point boundaries are not grapheme boundaries: a span may end before a
  combining mark.
- Coverage counts are derived from unit statuses: `processed` = `ok`+`empty`, plus `empty`, `failed`
  and `skipped`, and `expected` equals the total. `complete` is not allowed when anything failed or
  was skipped, a feature is missing, or an error diagnostic exists (`coverage_mismatch`).

**decision-receipt, worker-result, domain-event, evidence-set**

- Self digests and `payloadDigest` must match (`digest_mismatch`).

**evidence-set**

- `fragmentDigest` is SHA256 of `text` and equals `citation.contentDigest`.
- A fragment with more than one span has a non-null `joiner`; a single-span fragment has `null`.
- Tenants match.
- `totalBytes` is the sum of the fragments' UTF-8 bytes (`count_mismatch`).
- Immutable identity is consistent across fragments: one `(tenantId, sourceVersionId)` has one
  `sourceDigest`, and one `(tenantId, sourceVersionId, documentRevisionId)` has one `irDigest`
  (`binding_mismatch` at the later citation's `sourceDigest` or `irDigest`). Two different IRs always
  have two different `documentRevisionId` values.

The corpus loaders apply the same rule across all valid corpus records (`identityConflicts` in the
contract report must be empty): every object that names a `documentRevisionId` with an `irDigest`,
or a `sourceVersionId` with a `sourceDigest`, agrees with every other record.

**job-envelope**

- `profileFingerprint` equals `parserSignature.profileDigest`.

**index-manifest**

- `counts` match the members, resources and the sum of chunks.

**deletion-task**

`status` must equal the aggregate of the resource states (`status_mismatch`), evaluated in this order:

1. Every resource is `deleted` or `absent`: `done`.
2. Some resource is completed: `partial`.
3. Every resource is `unsupported`: `unsupported`.
4. Some resource is `unknown` or `unsupported`: `unknown`.
5. Otherwise: `pending`.

**selection, usage**

- Every chunk ref belongs to the record's tenant.

## 10. Binding checks (cross-document)

- `scope × selection` returns `denied(scope_mismatch)` when the tenant, `hostScopeHash` or
  `scopeBindingDigest` differs. A different actor or purpose changes the digest and is caught the same
  way. It returns `denied(POLICY_CHANGED)` when `Scope.policyEpoch` differs from
  `policyBinding.kbPolicyEpoch` (the approved code, in its original case).
- `disclosure-request × selection` returns `denied(selection_ref_mismatch)` for another
  `selectionId` or for any chunk ref that is not one of the selection's candidates.
- `disclosure-request × selection` also compares the run binding. The request run is
  `usageContext.runRef`, or `null` when `usageContext` is absent (a direct library call). It must equal
  `Selection.runRef` exactly; otherwise the result is `denied(scope_mismatch)` without evidence. So a
  request from another run, a run-bound selection disclosed without `usageContext`, and a run using a
  direct-call selection are all denied, while a direct call against a direct-call selection succeeds.
  The order of checks is `selectionId`, run binding, chunk refs. Uniqueness of `hostScopeHash` per run is
  not assumed.

Persistence-level uniqueness of `disclosureAttemptId` (`UNIQUE(tenant_id, disclosure_attempt_id)`,
replay returns only metadata) belongs to the disclosure items. At the wire level the corpus rejects a
missing attempt, a duplicate JSON member and a non-ULID attempt.

## 11. Locator conversion

- `pageIndexToLocator` turns a zero-based donor `page_index` into `physicalNo = index+1`.
  `printedLabel` stays an independent nullable string, so `page_index=0` with label `xii` becomes
  physical page 1 with label `xii`.
- Bbox input uses decimal strings with at most 6 integer and 6 fractional digits. It requires an
  explicit `coordinateOrigin` (`top-left` or `bottom-left`) and the page size. Without the origin the
  result is `missing_coordinate_system`; the origin is never inferred.
- Values become integer millionths with exact integer half-even rounding, so PHP and Python agree
  without any floating point. Coordinates outside the page, or boxes that are degenerate after
  rounding, give `invalid_bbox`.

## 12. Golden corpus

Each entry of `manifest.v1.json` has these fields: `{id,path,sha256,schemaId,expected,
expectedErrorCode?,expectedErrorPath?,rights,tags,generatorVersion?}`. `expectedErrorPath` is required
for negatives, so the exact path is part of the contract.

Both loaders reject the manifest when any of these holds:

- it fails the schema;
- an ID is duplicated (`duplicate_identifier`);
- it references an unknown or `common` schema (`unknown_schema`);
- a file is missing or escapes the root (`missing_file` or `pattern`);
- a SHA does not match (`digest_mismatch`);
- a `requiredTags` group is empty (`empty_case_group`).

PHP and Python then produce a `contract-report-1.0` covering corpus errors, hash, locator and binding
vectors, IR round trips and the domain table. The two reports must be byte-identical after JCS.

A change to any golden digest or expected outcome needs a new `corpusVersion` and check-group
`fixtureVersion`, each with a substantive rationale. Updating them only to make a test pass is not
allowed.
