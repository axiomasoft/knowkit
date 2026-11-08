# Subprocess extraction acceptance

`WorkerParserClient` takes a current durable lease, exact immutable source bytes,
explicit parser profile, full processing identity and host-selected limits.
Register a profile in `WorkerProfiles` only after fixture qualification. Its
capabilities are checked against the request and against the subprocess response.
Revoking a registered profile or capability denies later output acceptance.
Docling/PDF/OCR remain unqualified; the synthetic acceptance parser qualifies
only the transport and PHP acceptance boundary.

Bind `WorkerRunner` to `ProcessWorkerSupervisor`. It establishes bwrap/seccomp
and cgroup limits, stages readonly input and a private output directory, probes
the sandbox, negotiates the exact profile, then runs the fixed worker argv.
Missing sandbox support is unavailable. No HTTP transport or fallback exists.
`worker-negotiation-1.0` is a private staged response containing the subprocess,
job/result/IR versions, exact profile/signature, explicit capability list and
`network: false`; PHP removes it before validating the terminal output inventory.

Bind `WorkerJobs` to the tenant/KB-specific `PdoJobQueue`. Supply a fresh owner
authorization callback to the client; it must preserve denied, unknown and
unavailable outcomes. The client checks it and the authoritative lease context
during execution and before each checkpoint/result acceptance. The queue checks
the actual token hash, fencing token, epoch, expiry and current lifecycle again
inside its short SQL transaction. A multi-stage ingest accepts the extraction
checkpoint and leaves chunk/index pending; only a terminal extraction job is
completed by this client. The receipt says whether the job was completed.

`WorkerArtifactImporter` composes the existing immutable `Upload` boundary.
Bind its reservation/acceptance/uncertainty callbacks to the tenant-specific
resource ledger, with the lease's job ID. Bind checkpoint storage to durable,
immutable manifest writes and configure the queue's `VerifiedCheckpoints` reader
to reopen those manifests and resources. All blob/process I/O occurs outside
SQL acceptance transactions. A writer is reserved before the subprocess starts;
failed attempts leave uncertain reservations for reconciliation. IR, result and
each original asset are independently digested and uploaded. The client lowers
the asset limit to 126 to fit the existing 128-resource checkpoint contract.

The returned revision, quality decision, coverage and external uncertainty are
explicit. A technically valid partial extraction can be checkpointed with a
denied strict-complete quality decision. This receipt grants no publication or
disclosure permission: the separate publication use case retains its own
authoritative policy, lifecycle, epoch and lease barrier. Cache reuse still uses
`ExtractionCacheEntry` and full `ProcessingIdentity`; a cache hit never grants
access or rebinds an old worker attempt to a new lease.
