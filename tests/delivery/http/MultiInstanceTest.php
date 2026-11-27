<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain as D;
use KnowKit\Http\Server as H;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:http')]
final class MultiInstanceTest extends TestCase
{
    public function testServerBindsRoutesToInstanceIdAndDeniesScopeMismatch(): void
    {
        $supportId = D\InstanceId::fromString('support_faq');
        $enterpriseId = D\InstanceId::fromString('enterprise_kb');

        $f = new Fixture();
        $f->host = new H\AuthenticatedContext($supportId, $f->identity);

        $server = H\Server::forInstance(
            instanceId: $supportId,
            authenticator: $f,
            application: $f,
            contract: new H\Contract(),
            commands: new H\CommandFactory(validator: Fixture::validator(), clock: $f),
            ids: $f,
        );

        // Matching instanceId succeeds.
        $ok = $server->handle(Fixture::request('/v1/search', ['query' => 'support']));
        self::assertSame(200, $ok->status);
        self::assertCount(1, $f->seen);

        // Mismatched instanceId returns 403 denied(scope_mismatch) without invoking application.
        $f->host = new H\AuthenticatedContext($enterpriseId, $f->identity);
        $mismatch = $server->handle(Fixture::request('/v1/search', ['query' => 'support']));
        self::assertSame(403, $mismatch->status);
        $wire = Fixture::wire($mismatch);
        self::assertSame('denied', $wire['outcome']);
        self::assertSame('scope_mismatch', $wire['error']['code']);
        self::assertCount(1, $f->seen);

        // Plain HostIdentity without AuthenticatedContext also fails closed with 403 scope_mismatch.
        $f->host = new class($f->identity) implements HostIdentity {
            public function __construct(private D\Access\PolicyContext $policy) {}

            public function current(): D\Access\PolicyContext
            {
                return $this->policy;
            }
        };
        $missingContext = $server->handle(Fixture::request('/v1/search', ['query' => 'support']));
        self::assertSame(403, $missingContext->status);
        self::assertSame('scope_mismatch', Fixture::wire($missingContext)['error']['code']);
        self::assertCount(1, $f->seen);

        // Unauthenticated still returns 401 before instance check.
        $f->authenticated = false;
        $unauth = $server->handle(Fixture::request('/v1/search', ['query' => 'support']));
        self::assertSame(401, $unauth->status);
        self::assertSame('unauthenticated', Fixture::wire($unauth)['error']['code']);
        self::assertCount(1, $f->seen);
    }
}
