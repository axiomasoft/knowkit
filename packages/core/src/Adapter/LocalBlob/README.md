# LocalBlob v0.1

Provision a private directory (0700) owned by the service account on one local
filesystem. Inject its absolute path at runtime; store no machine path or secret
in repository configuration. The root and descendants must be exclusively
writable by that account. Paths from API requests never enter this adapter.
LocalBlob requires working file and directory fsync and atomic hardlink creation;
unsupported filesystem durability fails explicitly. No SDK or vendor runtime is
added to core.

Construct a tenant/storage-bound `PdoResourceLedger` with the existing schema-bound
`Connection`, `PdoCatalogTransaction` and `Clock`. Pass its bound methods as the
`LocalBlobStore` ledger callbacks: `inspectWriter`, `finalizeIdentity`,
`closeWriter`, `markUncertain`, `requireAccepted`, `listUnreferenced`, `quarantine`,
`recoveryRef`, `reconcileWriter`. Inject the ledger's `deletionBinding`, the host's
`IdGenerator`, offline contract validator and an explicit positive byte ceiling.
There is no implicit unlimited upload configuration.

`Application\Resources\Upload` calls the durable `reserveWriter` before invoking
`BlobStore::putImmutable`. Hosts authorize ingest before this internal operation.
Reservation and final acceptance use short tenant-first/source/job-guarded SQL
transactions. Streaming, hashing, promotion, fsync, verification and owner I/O run
outside SQL. An external adapter must honor this same reservation/accepted-ref
contract; a timeout leaves an uncertain writer, including when digest is unknown.
The provider doubles in tests qualify those fault semantics only. No production
external object-storage adapter is included.

Local keys contain tenant/source/sha256/digest/resource identity. The final
resource ID is the immutable objectVersion, so an accepted BlobRef resolves to one
ledger row. Different resources do not share physical files. Concurrent writers
of one resource serialize promotion and compare digest/length; they close their
writers outside the file lock and wait for acceptance without treating an expired
lease as process termination. Closed retries compare their incoming stream with
the accepted immutable digest without spooling unreserved bytes.
Acceptance retains one counted reference on the immutable resource identity,
including across a crash before SourceVersion metadata commit. It is an
idempotent acceptance claim, not the count of historical SourceVersion rows.
Lifecycle/deletion must explicitly release that claim under the source guard
after resolving consumers and pending acceptance; age or a closed writer alone
never makes the accepted object eligible for GC. P3.6 owns this release/aggregation.

Staging writes use exclusive creation. Promotion uses atomic link without target
replacement, fsyncs the destination directory, unlinks staging and fsyncs its
directory, then fsyncs the immutable final file. Every failure leaves a ledger
obligation and never returns durability success. A crash may leave a two-link
promotion. `reconcileWriter` requires the host's proof that the exact old runner
has stopped; it then locks the resource, validates/reconstructs the exact private
promotion, rechecks digest/length and fsync, closes the writer and accepts only
under current source/job guards. Lease expiry and provider listing cannot supply
that proof. Orphan inventory is retained as unknown with a retention hold and is
never deleted just because a catalog reference is missing.

Bind `ReadAuthority::requireAllow` to the store's read callback using a trusted
host `Scope`, current owner `AccessPolicy`, `Clock` and ledger `readBinding`.
Owner deny and unknown remain distinct and supersede a stale local allow. Every
returned buffer is verified against the whole bounded object and checked against
fresh policy plus authoritative accepted/source/tombstone/tenant-serving state.
Symlinks, external hardlinks and paths outside the private tree fail closed.
`CanonicalContent` supplies bounded private artifact buffers after a host verifies
their exact source/version/artifact relation and usability. Full retrieval,
projection backends, exact corrected-version authorization and public disclosure
with Usage commit remain with their owning workflows; index hit text is never
accepted by this canonical boundary.

Deletion requires the durable in-flight attempt, frozen task inventory and exact
storage coordinates. An owned resource must already be deleting, unreferenced,
unheld and free of active/uncertain writers. External ownership yields unsupported
without an owner-authorized capability. Unlink plus directory fsync gives deleted;
verified and fsynced absence gives absent; I/O/durability failure gives unknown.
P3.6 owns storing these receipts and aggregating deletion tasks. A returned receipt
does not itself mark the ledger deleted.

Reuse: AgenTools transaction/idempotency and separate-process test patterns at
2054acda51eb69eca1579c78900858c5ff3de01e are privately adapted under
`licenses/local-reuse-authorization.md`, copyright 2026 Dmitry Vostrikov.
DeepTutor's streamed SHA256 pattern at f7aed0744c36c5afef16a4261c31ebee1208cac2
is adapted to bounded PHP streams. Its replacement/soft-fsync and path-collision
implementations are rejected. The retained Apache-2.0 license/notices and exact
qualification decisions are referenced by `tests/storage/blobs/evidence/P2.2-reuse.json`.
