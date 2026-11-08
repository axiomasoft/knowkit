<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

/** Immutable audit metadata. Never stores raw question/history/output or a credential value. */
final readonly class ModelCallIdentity
{
    public Sha256 $requestBytesDigest;

    /** @param list<MessageDigest> $messages */
    public function __construct(
        public InvocationRef $invocationRef,
        string $completeRequestBytes,
        public ProfileRef $template,
        public array $messages,
        public ResolvedModelBinding $binding,
        public EgressDecisionRef $egressDecision,
        public DependencyManifest $dependencies,
        public DateTimeImmutable $deadline,
    ) {
        Guard::utf8($completeRequestBytes, 32768, false);
        $binding->scope->assertTenant($invocationRef->tenantId);
        $dependencies->requireScope($binding->scope);
        Guard::objects($messages, MessageDigest::class, 64);
        if ($messages === []) {
            throw new \InvalidArgumentException('Ordered typed outbound messages required');
        }
        if ($deadline <= $egressDecision->checkedAt) {
            throw new \InvalidArgumentException('Deadline must follow preparation');
        }
        $this->requestBytesDigest = new Sha256(hash('sha256', $completeRequestBytes));
    }

    public function requireBytes(string $bytes): void
    {
        if (!hash_equals($this->requestBytesDigest->value, hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('Outbound bytes changed after admission');
        }
    }

    public function digest(): Sha256
    {
        return new Sha256(hash('sha256', Jcs::canonicalize($this->toWire())));
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'invocationRef' => $this->invocationRef->toWire(),
            'requestBytesDigest' => $this->requestBytesDigest->value,
            'template' => $this->template->toWire(),
            'messages' => array_map(static fn (MessageDigest $message) => $message->toWire(), $this->messages),
            'binding' => $this->binding->toWire(),
            'egressDecision' => $this->egressDecision->toWire(),
            'dependencies' => $this->dependencies->toWire(),
            'deadline' => $this->deadline->format('Y-m-d\\TH:i:s.uP'),
        ]);
    }
}
