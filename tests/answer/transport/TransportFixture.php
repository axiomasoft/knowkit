<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Transport;

use DateTimeImmutable;
use KnowKit\Answer\Adapter\OpenAiCompatibleTransport;
use KnowKit\Answer\Adapter\Psr18 as H;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\CredentialResolver;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedHttpClient;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Clock;
use KnowKit\Tests\Answer\Contracts\Fixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;

final class TransportClock implements Clock
{
    public int $seconds = 0;

    public function now(): D\UtcInstant
    {
        $time = new DateTimeImmutable('2026-11-08T00:00:01Z');

        return new D\UtcInstant($time->modify("+{$this->seconds} seconds")->format('Y-m-d\TH:i:s.u\Z'));
    }
}

final class HttpPolicy implements H\HostHttpPolicy
{
    public int $calls = 0;
    public bool $allowed = true;
    public ?H\HttpLimits $limits = null;
    public ?\Closure $duringPrepare = null;

    public function prepare(ClientInterface $client, H\Deployment $deployment, H\HttpLimits $limits): bool
    {
        $this->calls++;
        $this->limits = $limits;
        if ($this->duringPrepare !== null) {
            ($this->duringPrepare)();
        }

        return $this->allowed;
    }
}

final class Credentials implements CredentialResolver
{
    public int $calls = 0;
    public bool $missing = false;
    public bool $fail = false;
    public ?A\ResolvedModelBinding $observed = null;

    public function resolve(D\Scope $scope, D\CredentialRef $ref, A\ResolvedModelBinding $binding): ?A\Credential
    {
        $this->calls++;
        $binding->requireScope($scope);
        if ($ref != $binding->credentialRef) {
            throw new \LogicException('Credential binding changed');
        }
        $this->observed = $binding;
        if ($this->fail) {
            throw new \RuntimeException(TransportFixture::canary());
        }

        return $this->missing ? null : new A\Credential(TransportFixture::canary());
    }
}

final class TransportFixture
{
    public TransportClock $clock;
    public HttpPolicy $policy;
    public Credentials $credentials;
    public ScriptedHttpClient $http;
    public H\Deployment $deployment;
    public OpenAiCompatibleTransport $transport;

    public function __construct(array $script = [], ?A\AnswerLimits $limits = null, ?H\HttpLimits $httpLimits = null)
    {
        $this->clock = new TransportClock();
        $this->policy = new HttpPolicy();
        $this->credentials = new Credentials();
        $this->http = new ScriptedHttpClient(new EffectiveProfile(ProfileMode::Test), $script);
        $this->deployment = new H\Deployment(self::binding(), self::corpus()['endpoint'], $limits ?? new A\AnswerLimits(), $httpLimits ?? new H\HttpLimits(5000, 60000, 131072));
        $factory = new Psr17Factory();
        $this->transport = new OpenAiCompatibleTransport($this->clock, $this->http, $factory, $factory, $this->credentials, $this->policy, $this->deployment);
    }

    public static function corpus(): array
    {
        return json_decode((string) file_get_contents(Fixture::root() . '/tests/fixtures/answer/transport/v1/http.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function canary(): string
    {
        // Generated at runtime: no usable credential or credential-shaped literal is tracked.
        return 'fixture-' . hash('sha256', 'P4.9 ephemeral redaction canary');
    }

    public static function binding(array $changes = []): A\ResolvedModelBinding
    {
        $descriptor = (new OpenAiCompatibleTransport(new TransportClock()))->descriptor();

        return Fixture::binding(array_replace([
            'transport' => $descriptor,
            'settings' => new A\GenerationSettings(32, '0.2', '0.9', 7, ['END']),
        ], $changes));
    }

    /** @return list<H\ChatMessage> */
    public static function messages(): array
    {
        return array_map(static fn (array $message) => new H\ChatMessage(A\MessageRole::from($message['role']), $message['content']), self::corpus()['messages']);
    }

    public static function request(?string $bytes = null, array $changes = []): A\TransportRequest
    {
        $bytes ??= H\OpenAiRequest::bytes(self::binding(), self::messages());
        $messages = array_map(static fn (H\ChatMessage $message) => new A\MessageDigest($message->role, new D\Sha256(hash('sha256', $message->text))), self::messages());
        $identity = Fixture::call(array_replace([
            'completeRequestBytes' => $bytes,
            'messages' => $messages,
            'binding' => self::binding(),
            'dependencies' => self::dependencies(),
        ], $changes));

        return new A\TransportRequest($identity, $bytes);
    }

    public static function response(array $changes = [], int $status = 200, string $type = 'application/json'): Response
    {
        return new Response($status, ['Content-Type' => $type], json_encode(array_replace_recursive(self::corpus()['response'], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function validator(): ContractValidator
    {
        $schemas = [];
        foreach (['common', 'citation-ref', 'egress-request', 'decision-receipt'] as $name) {
            $schema = StrictJson::decode((string) file_get_contents(Fixture::root() . "/schemas/{$name}.schema.json"));
            $schemas[$schema->get('$id')] = $schema;
        }

        return new ContractValidator(new SchemaSet($schemas));
    }

    public static function dependencies(): A\DependencyManifest
    {
        $messages = self::messages();
        $citation = new D\CitationRef(Fixture::citation()->toWire()->with('contentDigest', hash('sha256', $messages[2]->text)), self::validator());

        return new A\DependencyManifest(
            new D\Sha256(hash('sha256', $messages[3]->text)),
            [$citation],
            [new A\HistoryDependency(Fixture::scope()->tenantId, 'history:001', '1', new D\Sha256(hash('sha256', $messages[1]->text)))],
        );
    }

    public static function manifest(?A\ModelCallIdentity $identity = null): A\EgressContentManifest
    {
        $identity ??= self::request()->identity;
        $wire = $identity->dependencies->evidence[0]->toWire();
        $source = new D\SourceVersionRef(Fixture::scope()->tenantId, new D\SourceId($wire->get('sourceId')), new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest($wire->get('sourceDigest')));
        $categories = [A\EgressCategory::SystemInstruction, A\EgressCategory::History, A\EgressCategory::Evidence, A\EgressCategory::Question];
        $items = [];
        foreach (self::messages() as $index => $message) {
            $items[] = new A\EgressContentItem($categories[$index], new D\Sha256(hash('sha256', $message->text)), $index === 2 ? [$source] : []);
        }

        return new A\EgressContentManifest($identity, $items);
    }
}
