<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Disclosure;
use KnowKit\Application\Reading\Readings;
use KnowKit\Application\Retrieval;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Optional composition decorator for the existing library contracts; no storage or policy reimplementation. */
final readonly class CoreReads implements HostApplication
{
    public function __construct(
        private HostApplication $commands,
        private Retrieval $retrieval,
        private Disclosure $disclosure,
        private Readings $readings,
    ) {}

    public function execute(Command $command, AccessContext $context): Reply
    {
        if ($command->query !== null) {
            $limit = $command->limit ?? throw new InputFailure();
            $outcome = $this->retrieval->retrieve(scope: $context->scope(), query: $command->query, limit: $limit);
            $data = $outcome->result->data;

            return new Reply(result: $outcome->result, data: !$data instanceof D\Selection ? null : new JsonObject([
                'selection' => $data->toWire(), 'token' => $outcome->token?->reveal(),
                'readRequirements' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $outcome->readRequirements),
            ]));
        }
        if ($command->disclosure !== null) {
            $result = $this->disclosure->disclose(scope: $context->scope(), request: $command->disclosure)->result;

            return self::committed($result);
        }
        if ($command->read !== null) {
            return self::committed($this->readings->read(context: $context, request: $command->read));
        }

        return $this->commands->execute(command: $command, context: $context);
    }

    public static function committed(D\Result $result): Reply
    {
        if ($result->outcome !== D\Outcome::Success) {
            return new Reply($result);
        }
        $value = $result->data;
        if ($value instanceof D\EvidenceSet) {
            if ($value->checkedAt() === null) {
                throw new \LogicException('Usage-backed evidence required');
            }

            return new Reply(result: $result, data: $value->toWire());
        }
        if ($value instanceof D\DisclosureReplay) {
            return new Reply(result: $result, data: new JsonObject([
                'replay' => true, 'usageRef' => $value->usageRef->value,
                'disclosureAttemptId' => $value->disclosureAttemptId->value,
                'checkedAt' => $value->checkedAt->value, 'evidenceDigest' => $value->evidenceDigest->value,
            ]));
        }
        if ($value instanceof D\Reading\ReadResponse) {
            return new Reply(result: $result, data: new JsonObject([
                'citation' => $value->citation->toWire(), 'usageRef' => $value->usageRef->value,
                'checkedAt' => $value->checkedAt->value, 'profile' => $value->profile,
                'contentBase64' => base64_encode($value->body->bytes()), 'mediaType' => $value->body->mediaType,
                'digest' => $value->body->digest->value, 'metadata' => $value->body->metadata(),
            ]));
        }
        throw new \LogicException('Committed library disclosure required');
    }
}
