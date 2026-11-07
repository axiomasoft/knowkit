<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{DomainFailure, Outcome};
use KnowKit\Domain\Contract\JsonObject;

/** Shared transport/tool guard. Authentication is always supplied by the host, never the body. */
final readonly class RequestBoundary
{
    public function __construct(private HostIdentity $host) {}

    public function context(JsonObject $body): AccessContext
    {
        $context = AccessContext::fromHost($this->host);
        foreach (['scope','context','identity','accessContext','policyContext','tenant','tenantId','kbId','subject','subjectId',
            'actorId','audience','grants','scopes','egress','purpose','policyEpoch','hostScopeHash'] as $field) {
            if ($body->has($field)) { throw new DomainFailure(Outcome::Denied, 'untrusted_context'); }
        }
        return $context;
    }
}
