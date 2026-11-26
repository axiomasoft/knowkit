<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use Closure;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Server as H;
use KnowKit\Port\Clock;
use KnowKit\Port\IdGenerator;

require_once dirname(__DIR__, 3) . '/packages/http/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/tooling/baseline/CandidateAutoload.php';
\KnowKit\Tests\Tooling\CandidateAutoload::register(dirname(__DIR__, 3));

final class Fixture implements H\Authenticator, H\HostApplication, HostIdentity, Clock, IdGenerator
{
    public bool $authenticated = true;
    public ?Closure $handler = null;
    public array $seen = [];
    public PolicyContext $identity;
    public ?HostIdentity $host = null;
    public int $sequence = 200;

    public function __construct()
    {
        $this->identity = new PolicyContext(new D\Scope(
            tenantId: new D\TenantId(self::id(1)), kbId: new D\KbId(self::id(2)), actorId: new D\ActorId(self::id(3)),
            purpose: 'lesson.read', policyEpoch: new D\Decimal('0'), hostScopeHash: new D\Sha256(str_repeat('a', 64)),
        ), 'http-host');
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function id(int $number): string
    {
        return '01J' . str_pad((string) $number, 23, '0', STR_PAD_LEFT);
    }

    public static function validator(): ContractValidator
    {
        static $validator;
        if ($validator !== null) {
            return $validator;
        }
        $schemas = [];
        foreach (glob(self::root() . '/schemas/*.schema.json') as $path) {
            $wire = StrictJson::decode(file_get_contents($path), 4194304);
            $schemas[$wire->get('$id')] = $wire;
        }

        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    public function server(?H\HostApplication $application = null, ?Clock $clock = null, ?IdGenerator $ids = null): H\Server
    {
        return new H\Server(
            authenticator: $this, application: $application ?? $this, contract: new H\Contract(),
            commands: new H\CommandFactory(validator: self::validator(), clock: $clock ?? $this), ids: $ids ?? $this,
        );
    }

    public function authenticate(H\Request $request): ?HostIdentity
    {
        return $this->authenticated ? ($this->host ?? $this) : null;
    }

    public function current(): PolicyContext
    {
        return $this->identity;
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(++$this->sequence));
    }

    public function now(): D\UtcInstant
    {
        return new D\UtcInstant('2026-11-08T12:00:00Z');
    }

    public function execute(H\Command $command, AccessContext $context): H\Reply
    {
        $this->seen[] = [$command, $context];
        if ($this->handler !== null) {
            return ($this->handler)($command, $context);
        }

        return H\Reply::success(new JsonObject(['operation' => $command->route->operation]));
    }

    public static function request(string $path, array $body = [], array $headers = [], string $method = 'POST'): H\Request
    {
        return new H\Request(
            method: $method, target: $path, headers: $headers + ['Content-Type' => 'application/json'],
            body: $method === 'GET' ? '' : json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    public static function wire(H\Response $response): array
    {
        return json_decode($response->body, true, 128, JSON_THROW_ON_ERROR);
    }

    public static function cases(): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/http/v1/cases.json'), true, 128, JSON_THROW_ON_ERROR);
    }
}
