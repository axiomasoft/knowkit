<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Contracts;

use KnowKit\Answer\Domain\ModelBindingRef;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Port\ConversationBindingResolver;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;
use RuntimeException;

final class Fixture implements ConversationBindingResolver
{
    public int $reads = 0;
    public bool $unavailable = false;

    public function __construct(
        public ConversationBinding $binding,
    ) {}

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/chatom/routes/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $changes */
    public static function conversation(array $changes = []): ConversationRef
    {
        $values = array_replace(self::corpus()['conversation'], $changes);

        return new ConversationRef(
            tenantId: new TenantId($values['tenantId']),
            spaceId: $values['spaceId'],
            channelId: $values['channelId'],
            botId: $values['botId'],
            bindingId: $values['bindingId'],
        );
    }

    /** @param array<string, string> $changes */
    public static function binding(array $changes = [], ?ConversationRef $conversation = null): ConversationBinding
    {
        $values = array_replace(self::corpus()['binding'], $changes);
        $conversation ??= self::conversation();
        $kb = new KbId($values['kbId']);
        $scope = new Scope(
            tenantId: $conversation->tenantId,
            kbId: $kb,
            actorId: new ActorId($values['actorId']),
            purpose: $values['purpose'],
            policyEpoch: new Decimal($values['policyEpoch']),
            hostScopeHash: new Sha256($values['hostScopeHash']),
        );

        return new ConversationBinding(
            conversation: $conversation,
            route: ConversationRoute::from($values['route']),
            kbId: $kb,
            modelBindingRef: new ModelBindingRef($values['modelBindingRef']),
            hostScopeInputs: $scope,
            viewerBindingRef: $values['viewerBindingRef'],
            bindingRevision: $values['bindingRevision'],
        );
    }

    public function resolve(ConversationRef $conversation): ConversationBinding
    {
        $this->reads++;

        if ($this->unavailable) {
            throw new RuntimeException('Host facts unavailable; protected diagnostic must not escape');
        }

        return $this->binding;
    }
}
