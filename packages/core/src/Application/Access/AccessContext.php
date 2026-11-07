<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{DomainFailure, Outcome, Scope};
use KnowKit\Domain\Access\PolicyContext;

/** Host-only construction pattern adapted from AgenTools ExecutionContext (see P2.3-reuse.json). */
final readonly class AccessContext
{
    private function __construct(private HostIdentity $host, public PolicyContext $policy) {}

    public static function fromHost(HostIdentity $host): self
    {
        $policy = self::identity($host);
        if ($policy === null) { throw new DomainFailure(Outcome::Denied, 'unauthenticated'); }
        return new self($host, $policy);
    }

    public function scope(): Scope { return $this->policy->scope; }

    /** Call outside SQL transactions, immediately before the owner check at each boundary. */
    public function assertCurrent(): void
    {
        $current = self::identity($this->host);
        if ($current === null) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $this->policy->assertSame($current);
    }

    private static function identity(HostIdentity $host): ?PolicyContext
    {
        try { return $host->current(); }
        catch (DomainFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new DomainFailure(Outcome::Unavailable, 'identity_unavailable'); }
    }

    public function __debugInfo(): array { return ['context'=>'host']; }
}
