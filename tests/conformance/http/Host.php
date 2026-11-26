<?php

declare(strict_types=1);

namespace KnowKit\Tests\Conformance\Http;

use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Cli\Command as CliCommand;
use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response as CliResponse;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Disclosure\FreshDisclosure;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Client as C;
use KnowKit\Http\Server as H;
use KnowKit\Tests\Delivery\Http\Fixture as HttpFixture;
use KnowKit\Tests\Delivery\Http\SelectionBindings;
use KnowKit\Tests\Retrieval\Cache\Fixture as CacheFixture;
use KnowKit\Tests\Retrieval\Reading\Fixture as ReadingFixture;
use KnowKit\Tests\Semantic\Hybrid\Fixture as HybridFixture;

require_once dirname(__DIR__, 2) . '/delivery/http/Fixture.php';
require_once dirname(__DIR__, 2) . '/delivery/http/SelectionBindings.php';
require_once dirname(__DIR__, 2) . '/retrieval/cache/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/reading/bootstrap.php';
require_once dirname(__DIR__, 2) . '/semantic/hybrid/bootstrap.php';

/** Both modes share these core services. Test controls never enter production routes. */
final class Host
{
    public HttpFixture $http;
    public H\HostApplication $application;
    public H\Server $server;
    public CacheFixture $cache;
    public string $wireFault = '';
    public int $requests = 0;
    private array $cliTokens = [];
    private string $scenario = 'success';
    private ?H\Contract $wireContract = null;

    public function __construct(
        public ?ReadingFixture $reading = null,
    ) {
        $this->http = new HttpFixture();
        $this->cache = new CacheFixture();
        if ($reading !== null) {
            $this->composeReading($reading);
            return;
        }
        $this->http->host = $this->cache->host;
        $this->http->handler = $this->search(...);
        $this->application = $this->http;
        $this->server = $this->http->server(
            application: $this->application, clock: $this->cache->host, ids: $this->cache->host,
        );
    }

    public function invoke(C\Call $call): C\Response
    {
        $reply = $this->reply($call);
        $wire = H\Response::reply(reply: $reply, route: $call->route, requestId: $call->requestId);

        return C\Response::decode(wire: $wire, contract: $this->wireContract(), call: $call, version: '1.0');
    }

    public function invokeCli(C\Call $call): C\Response
    {
        $name = match ($call->route->operation) {
            'search' => 'search',
            'disclose' => 'disclose',
            'readSource' => 'read source',
        };
        $kernel = new Kernel(function (CliCommand $command) use ($call): CliResponse {
            $input = C\Call::operation(operation: $call->route->operation, body: $command->input->without('token'), requestId: $call->requestId);
            $reply = $this->reply($input);

            return CliResponse::result(result: $reply->result, data: $reply->data());
        });
        $request = $call->request(contract: $this->wireContract(), version: '1.0');
        $body = \KnowKit\Domain\Contract\StrictJson::decode($request->read(262144));
        if ($name === 'disclose') {
            $body = $body->with('token', $this->cliTokens[$body->get('selectionId')]);
        }
        $response = $kernel->run(
            arguments: [...explode(' ', $name), '--json', '--input', '-'],
            stdin: \KnowKit\Domain\Contract\Jcs::canonicalize($body),
        );
        if ($name === 'search' && $response->envelope->get('outcome') === 'success') {
            $data = $response->envelope->get('data');
            if ($data->get('selection') instanceof JsonObject) {
                $this->cliTokens[$data->get('selection')->get('selectionId')] = $data->get('token');
            }
        }
        $status = match ($response->exit) {
            0 => 200,
            3 => 403,
            4 => 503,
            5 => 409,
            default => 500,
        };

        return new C\Response(status: $status, requestId: $call->requestId, envelope: $response->envelope);
    }

    private function reply(C\Call $call): H\Reply
    {
        $request = $call->request(contract: $this->wireContract(), version: '1.0');
        $clock = $this->reading?->upstream->host ?? $this->cache->host;
        $context = AccessContext::fromHost($this->http->host);
        $command = (new H\CommandFactory(validator: HttpFixture::validator(), clock: $clock))->create(
            request: $request, route: $call->route, parameters: $call->parameters,
            body: \KnowKit\Domain\Contract\StrictJson::decode($request->read(1403000) ?: '{}'),
            requestId: $call->requestId, context: $context,
        );
        try {
            return $this->application->execute(command: $command, context: $context);
        } catch (D\DomainFailure $failure) {
            return new H\Reply(D\Result::failure($failure));
        }
    }

    private function wireContract(): H\Contract
    {
        // OpenAPI compilation is pure and larger than a search. One instance per host, not per call.
        return $this->wireContract ??= new H\Contract();
    }

    public function control(string $action): JsonObject
    {
        if (str_starts_with($action, 'wire:')) {
            $this->wireFault = substr($action, 5);
        } elseif ($action === 'revoke') {
            $this->reading->upstream->revoke();
        } elseif ($action !== 'stats') {
            $this->scenario = $action;
            $this->cache = new CacheFixture();
            $this->http->host = $this->cache->host;
        }

        return new JsonObject([
            'hits' => $this->cache->hits,
            'requests' => $this->requests,
            'usages' => $this->reading?->upstream->lexical->catalog->count('evidence_usages') ?? 0,
        ]);
    }

    private function search(H\Command $command): H\Reply
    {
        if (str_starts_with($this->scenario, 'hybrid')) {
            return $this->hybrid();
        }
        $host = $this->cache->host;
        $host->candidates = $this->scenario === 'empty' ? [] : null;
        $host->failure = match ($this->scenario) {
            'denied' => new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'access_denied'),
            'unknown' => new D\DomainFailure(outcome: D\Outcome::Unknown, errorCode: 'policy_unknown'),
            'unavailable' => new D\DomainFailure(outcome: D\Outcome::Unavailable, errorCode: 'provider_unavailable'),
            default => null,
        };
        $query = $host->query(text: $command->query->text());
        if ($this->scenario === 'incompatible') {
            $query = new D\QueryBinding(
                original: $query->original, normalized: $query->normalized,
                normalizationRevision: $query->normalizationRevision, profile: $query->profile,
                limits: $query->limits, spaceFingerprint: new D\Fingerprint(str_repeat('d', 64)),
            );
        }
        $outcome = $this->cache->retrieve($query);

        return new H\Reply(result: $outcome->result, data: $outcome->result->data === null ? null : new JsonObject([
            'selection' => $outcome->result->data->toWire(), 'token' => $outcome->token->reveal(),
        ]));
    }

    private function hybrid(): H\Reply
    {
        $f = new HybridFixture();
        $f->host->candidates = [$f->hit('exact')];
        $f->vectorFailure = new D\DomainFailure(outcome: D\Outcome::Unavailable, errorCode: 'vector_timeout');
        $space = HybridFixture::space();
        if ($this->scenario === 'hybrid-mismatch') {
            $space = new D\SpaceRef(...iterator_to_array($space->toWire()->with('revision', 'r2')));
        }
        $result = $f->run(new D\SearchProfile(id: 'hybrid-v1', space: $space, allowDegrade: true));

        return new H\Reply(result: $result, data: $result->outcome === D\Outcome::Success ? new JsonObject([
            'refs' => array_map(static fn (D\Candidate $candidate): JsonObject => $candidate->ref->toWire(), $result->data->candidates),
        ]) : null);
    }

    private function composeReading(ReadingFixture $reading): void
    {
        $f = $reading->upstream;
        $this->cache->host = $f->host;
        $this->cache->memory = new InMemoryReferenceCache($f->host);
        $f->selections = new Selections(
            policy: $f->checks(), index: $f->lexical->index, catalog: $f->catalog,
            store: new PdoSelections(db: $f->db(), codec: new D\SelectionRecordCodec(HttpFixture::validator())),
            selector: new BoundedSelector(), clock: $f->host, ids: $f->host,
            validator: HttpFixture::validator(), cache: $this->cache,
        );
        $canonical = new CanonicalBuffer(
            catalog: $f->catalog,
            documents: new PdoCanonicalDocuments(db: $f->db(), blobs: $f->objects, validator: HttpFixture::validator()),
            ids: $f->host,
        );
        $disclosures = new Disclosures(
            selections: $f->selections, policy: $f->checks(), canonical: $canonical,
            store: $f->usages, ids: $f->host, validator: HttpFixture::validator(),
        );
        $bindings = new SelectionBindings($f);
        $this->http->host = $f->host;
        $this->http->identity = $f->host->identity;
        $this->application = new H\CoreReads(
            commands: $this->http, retrieval: $bindings,
            disclosure: new FreshDisclosure(disclosures: $disclosures, store: $f->usages, host: $bindings),
            readings: $reading->service,
        );
        $this->server = $this->http->server(application: $this->application, clock: $f->host, ids: $f->host);
    }
}
