<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use DateTimeImmutable;
use KnowKit\Answer\Adapter\Psr18\ChatMessage;
use KnowKit\Answer\Adapter\Psr18\OpenAiRequest;
use KnowKit\Answer\Domain\DependencyManifest;
use KnowKit\Answer\Domain\EgressContentItem;
use KnowKit\Answer\Domain\EgressContentManifest;
use KnowKit\Answer\Domain\EgressDecisionRef;
use KnowKit\Answer\Domain\InvocationRef;
use KnowKit\Answer\Domain\MessageDigest;
use KnowKit\Answer\Domain\ModelCallIdentity;
use KnowKit\Answer\Domain\ProfileRef;
use KnowKit\Answer\Domain\ResolvedModelBinding;
use KnowKit\Answer\Domain\TransportRequest;
use KnowKit\Domain\Sha256;

final readonly class GroundingPrompt
{
    public string $bytes;

    /** @param list<ChatMessage> $messages
     * @param list<EgressContentItem> $items
     */
    public function __construct(
        public ResolvedModelBinding $binding,
        public ProfileRef $template,
        public array $messages,
        public array $items,
        public DependencyManifest $dependencies,
    ) {
        $this->bytes = OpenAiRequest::bytes($binding, $messages);
    }

    public function request(InvocationRef $ref, EgressDecisionRef $egress, DateTimeImmutable $deadline): TransportRequest
    {
        $identity = new ModelCallIdentity(
            $ref,
            $this->bytes,
            $this->template,
            array_map(static fn (ChatMessage $message) => new MessageDigest($message->role, new Sha256(hash('sha256', $message->text))), $this->messages),
            $this->binding,
            $egress,
            $this->dependencies,
            $deadline,
        );

        return new TransportRequest($identity, $this->bytes);
    }

    public function manifest(TransportRequest $request): EgressContentManifest
    {
        $request->identity->requireBytes($this->bytes);

        return new EgressContentManifest($request->identity, $this->items);
    }

    public function __debugInfo(): array
    {
        return ['bytes' => strlen($this->bytes), 'template' => $this->template];
    }
}
