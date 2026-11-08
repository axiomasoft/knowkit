-- Optional package administration only. Apply explicitly in the host-owned schema.
CREATE TABLE embedding_requests (
    tenant_id char(26) NOT NULL,
    kb_id char(26) NOT NULL,
    request_digest char(64) NOT NULL,
    scope_digest char(64) NOT NULL,
    space_digest char(64) NOT NULL,
    next_attempt integer NOT NULL CHECK (next_attempt > 0),
    accepted_result_digest char(64),
    PRIMARY KEY (tenant_id, kb_id, request_digest)
);

CREATE TABLE embedding_receipts (
    tenant_id char(26) NOT NULL,
    kb_id char(26) NOT NULL,
    request_digest char(64) NOT NULL,
    attempt integer NOT NULL CHECK (attempt > 0),
    status text NOT NULL CHECK (status IN ('accepted', 'unknown', 'failed')),
    result_fingerprint char(64),
    provider_request_id varchar(128),
    failure_outcome text CHECK (failure_outcome IN ('denied', 'unknown', 'unavailable', 'incompatible')),
    PRIMARY KEY (tenant_id, kb_id, request_digest, attempt),
    FOREIGN KEY (tenant_id, kb_id, request_digest)
        REFERENCES embedding_requests (tenant_id, kb_id, request_digest),
    CHECK ((status = 'accepted') = (result_fingerprint IS NOT NULL)),
    CHECK (status <> 'accepted' OR failure_outcome IS NULL),
    CHECK (status <> 'failed' OR (failure_outcome IS NOT NULL AND failure_outcome <> 'unknown')),
    CHECK (status <> 'unknown' OR failure_outcome IS NULL OR failure_outcome = 'unknown')
);
