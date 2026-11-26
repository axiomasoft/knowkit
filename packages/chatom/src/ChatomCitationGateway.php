<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Actors\Data\ActorRef;
use KnowKit\Application\Reading\Readings;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Port\CitationViewerContext;
use KnowKit\Domain\DisclosureReplay;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Reading\ReadRequest;
use KnowKit\Domain\Reading\ReadResponse;
use KnowKit\Domain\Result;
use Throwable;

final readonly class ChatomCitationGateway
{
    public function __construct(
        private CitationViewerContext $viewers,
        private Readings $readings,
    ) {}

    /** @return Result<ReadResponse>|Result<DisclosureReplay> */
    public function preview(ConversationRef $conversation, ActorRef $viewer, ReadRequest $request): Result
    {
        try {
            $context = $this->viewers->current(conversation: $conversation, viewer: $viewer);
            $context->scope()->assertTenant($conversation->tenantId);

            return $this->readings->read(context: $context, request: $request);
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        } catch (Throwable) {
            return Result::failure(new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'citation_viewer_unavailable'));
        }
    }
}
