<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Http\Server as H;
use KnowKit\Port\Clock;
use KnowKit\Port\IdGenerator;
use KnowKit\Tests\Contract\Ports\PortFixture;

/** Local host double; does not load any installed optional-package Composer copy. */
final class HttpHost implements H\Authenticator, H\HostApplication, HostIdentity, Clock, IdGenerator
{
    public array $seen = [];
    public ?\Closure $handler = null;
    public PolicyContext $identity;
    public int $sequence = 200;

    public function __construct()
    {
        $this->identity = new PolicyContext(PortFixture::scope(), 'hardening-host');
    }

    public static function id(int $number): string
    {
        return PortFixture::id($number);
    }

    public static function validator(): D\Contract\ContractValidator
    {
        return PortFixture::validator();
    }

    public function server(): H\Server
    {
        return new H\Server(authenticator: $this, application: $this, contract: new H\Contract(),
            commands: new H\CommandFactory(validator: self::validator(), clock: $this), ids: $this);
    }

    public function authenticate(H\Request $request): HostIdentity
    {
        return $this;
    }

    public function current(): PolicyContext
    {
        return $this->identity;
    }

    public function now(): D\UtcInstant
    {
        return new D\UtcInstant('2026-11-09T00:00:00Z');
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(++$this->sequence));
    }

    public function execute(H\Command $command, AccessContext $context): H\Reply
    {
        $this->seen[] = [$command, $context];
        if ($this->handler !== null) {
            return ($this->handler)($command, $context);
        }

        return H\Reply::success(new D\Contract\JsonObject());
    }

    public static function request(string $path, array $body = []): H\Request
    {
        return new H\Request(method: 'POST', target: $path, headers: ['Content-Type' => 'application/json'],
            body: json_encode((object) $body, JSON_THROW_ON_ERROR));
    }
}
