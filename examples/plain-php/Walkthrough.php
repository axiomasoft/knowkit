<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\Jcs;

final class Walkthrough
{
    public readonly Kernel $cli;
    public array $steps = [];

    public function __construct(
        public readonly Runtime $runtime,
    ) {
        $this->cli = new Kernel($runtime->dispatch(...));
    }

    public function call(string $command, array $input = [], ?string $key = null,
        ?string $epoch = null, ?string $revision = null, ?string $file = null): Response
    {
        $arguments = [...explode(' ', $command), '--json', '--input', '-'];
        foreach (['idempotency-key' => $key, 'expected-epoch' => $epoch, 'expected-revision' => $revision, 'file' => $file] as $flag => $value) {
            if ($value !== null) {
                array_push($arguments, '--' . $flag, $value);
            }
        }
        $response = $this->cli->run($arguments, json_encode((object) $input, JSON_THROW_ON_ERROR));
        $this->steps[] = ['command' => $command, 'exit' => $response->exit, 'outcome' => $response->envelope->get('outcome')];

        return $response;
    }

    public function prepare(bool $publish = true): void
    {
        $this->require($this->call('kb create', ['kbId' => Host::id('demo-kb'), 'name' => 'Local TXT'], 'kb'));
        $this->require($this->call('collection create', ['collectionId' => Host::id('demo-collection')], 'collection', '0', '0'));
        $this->require($this->call('source register', ['sourceId' => Host::id('demo-source'),
            'ownerRef' => 'local-owner:1', 'origin' => 'local:fixture'], 'source'));
        $this->require($this->call('source upload', self::uploadInput(), 'upload', '0', '0', self::fixture()));
        $this->require($this->call('ingest start', ['sourceVersionId' => Host::id('demo-version'),
            'generationId' => Host::id('demo-generation')], 'ingest', '0'));
        if (!$publish) {
            return;
        }
        $this->require($this->call('publication validate', ['generationId' => Host::id('demo-generation')], 'validate', '0'));
        $this->require($this->call('publication activate', ['generationId' => Host::id('demo-generation')], 'publish', '0', '0'));
    }

    public function run(): JsonObject
    {
        $this->prepare();
        $search = $this->require($this->call('search', ['query' => 'support']));
        $cached = $this->require($this->call('search', ['query' => 'support']));
        $request = self::disclosureInput($search, 'first-attempt');
        $evidence = $this->require($this->call('disclose', $request));
        $fragment = $evidence->get('fragments')[0];
        $citation = $fragment->get('citation');
        $read = $this->require($this->call('read source', ['citationId' => $citation->get('citationId'),
            'attemptId' => Host::id('read-attempt')]));
        if (hash('sha256', base64_decode($read->get('contentBase64'), true)) !== hash_file('sha256', self::fixture())) {
            throw new \RuntimeException('Source identity assertion failed');
        }
        $this->require($this->call('lifecycle revoke', ['sourceId' => Host::id('demo-source'), 'reason' => 'source_revoked'], 'revoke', '0'));
        $denied = $this->call('disclose', self::disclosureInput($cached, 'after-revoke'));
        if ($denied->exit !== 3 || $denied->envelope->has('data')) {
            throw new \RuntimeException('Revocation assertion failed');
        }

        return new JsonObject(['outcome' => 'success', 'steps' => $this->steps, 'citation' => $citation,
            'sourceDigest' => hash_file('sha256', self::fixture()), 'afterRevoke' => $denied->envelope,
            'optionalHttp' => 'not_selected', 'host' => 'single-process-local-text']);
    }

    public function require(Response $response): JsonObject
    {
        if ($response->exit !== 0 || $response->envelope->get('outcome') !== 'success') {
            $error = $response->envelope->get('error');
            $code = $error instanceof JsonObject ? $error->get('code') : 'unexpected_outcome';
            throw new \RuntimeException('Walkthrough failed: ' . $code);
        }

        return $response->envelope->get('data');
    }

    public static function fixture(): string
    {
        return __DIR__ . '/fixtures/v1/support.txt';
    }

    public static function uploadInput(): array
    {
        return ['sourceId' => Host::id('demo-source'), 'sourceVersionId' => Host::id('demo-version'), 'rightsRef' => 'synthetic:private'];
    }

    public static function disclosureInput(JsonObject $search, string $attempt): array
    {
        $selection = $search->get('selection');
        // Convert wire values to native JSON for the stdin boundary; never pass host scope or policy claims.
        return json_decode(Jcs::canonicalize(new JsonObject(['selectionId' => $selection->get('selectionId'),
            'chunkRefs' => $selection->get('candidateRefs'), 'disclosureAttemptId' => Host::id($attempt),
            'token' => $search->get('token')])), true, 64, JSON_THROW_ON_ERROR);
    }
}
