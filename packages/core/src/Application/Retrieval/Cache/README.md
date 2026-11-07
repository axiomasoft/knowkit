# Reference cache

Inject `ReferenceCache` as the optional last `Selections` collaborator. The shipped
`InMemoryReferenceCache` is bounded and process local; no external cache adapter is
qualified here. Keys adapt AgenTools Query binding and DeepTutor's generation-scoped
corpus cache. See `tests/retrieval/cache/evidence/P4.4-reuse.json` for pins and decisions.

The versioned key covers trusted tenant/KB/actor/audience/business purpose/grants,
the verbatim hostScopeHash, policy epochs and binding digest, current eligible source
universe, immutable snapshot digest and generation, original/normalized query,
normalization revision, filters, effective profile revision/configuration, space
fingerprint, limits and top-k. Only the key digest, tenant and hostScopeHash are retained.
Entries retain immutable refs and integer ranks, with a fixed 30-second expiry;
scores, text, selection tokens, grants, decisions and EvidenceSets are excluded.
Hits never extend the original lifetime. Failed/cancelled producers publish nothing.
There is no shared in-flight producer or waiter capability to replay.

Every retrieval obtains a fresh QueryGrant before lookup, loads the authoritative
exact snapshot inventory, validates every cached candidate against current eligibility
and immutable manifest identity, applies deterministic rank/chunk-ID ordering and
bounded selection, checks host continuity, and issues a new selection/token. Backend
ranking still applies its authorized universe before LIMIT on misses. Every delivery
must call fresh disclosure at the actual send boundary; a selection/cache hit cannot
return canonical bytes. Historical EvidenceSets and Usage replay are facts only.

Wrap the durable `EventInbox` passed to `Events\Consumer` with `InvalidatingInbox`
to conservatively evict that tenant before applying or reconciling invalidation.
Duplicates, gaps and delayed events can only evict. A failed eviction prevents durable
acknowledgement; durable inbox semantics remain with the wrapped implementation.
This hook affects the injected cache instance. Hosts with several processes must
deliver invalidations to each instance or implement the port with shared storage.
Delayed/missing delivery and a refill racing eviction remain safe because current
query/membership checks and the serialized fresh disclosure boundary are mandatory.
