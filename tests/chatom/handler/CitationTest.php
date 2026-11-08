<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Handler;

use Chatom\Actors\Data\ActorRef;
use KnowKit\Answer\Domain\AnsweredContent;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Chatom\ChatomCitationGateway;
use KnowKit\Chatom\ChatomReplyMapper;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Port\CitationViewerContext;
use KnowKit\Domain as D;
use KnowKit\Domain\Reading\ReadTarget;
use KnowKit\Tests\Retrieval\Reading\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/reading/bootstrap.php';

#[Group('chatom:handler')]
final class CitationTest extends TestCase
{
    public function testOpaqueCitationPreviewUsesFreshGatewayAndPinnedHistoricalIdentity(): void
    {
        Fixture::run(function (Fixture $f): void {
            $content = new AnsweredContent(
                text: 'Checked answer',
                citations: [$f->citation],
                modelBindingDigest: new D\Sha256(str_repeat('1', 64)),
                evidenceSetId: new D\Sha256(str_repeat('2', 64)),
            );
            $document = (new ChatomReplyMapper())->map($content);
            $wire = json_encode($document->toArray(), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('knowkit:citation:' . $f->citation->id()->value, $wire);
            foreach (['sourceVersionId', 'sourceDigest', 'spans', 'extension', 'agentools', 'url', Fixture::corpus()['page']] as $private) {
                self::assertStringNotContainsString($private, $wire);
            }
            $viewers = new class ($f) implements CitationViewerContext {
                public int $reads = 0;

                public function __construct(
                    private Fixture $fixture,
                ) {}

                public function current(ConversationRef $conversation, ActorRef $viewer): AccessContext
                {
                    $this->reads++;

                    return $this->fixture->upstream->host->context();
                }
            };
            $gateway = new ChatomCitationGateway(viewers: $viewers, readings: $f->service);
            $conversation = new ConversationRef(tenantId: $f->scope()->tenantId, spaceId: 'school', channelId: 'lesson', botId: 'bot:tutor', bindingId: 'lesson:tutor');
            $viewer = ActorRef::of(type: 'user', id: 'learner');
            $request = $f->request(ReadTarget::page(new D\LocalId('p1')));
            $first = $gateway->preview(conversation: $conversation, viewer: $viewer, request: $request);
            self::assertSame(D\Outcome::Success, $first->outcome, $first->errorCode ?? 'success');
            self::assertSame(Fixture::corpus()['page'], $first->data->body->bytes());
            self::assertSame($f->citation->canonical(), $first->data->citation->canonical());
            // A newer object's presence never redirects the original immutable citation.
            $f->upstream->objects->bodies[Fixture::corpus()['expectedDigests']['correctedPage']] = Fixture::corpus()['correctedPage'];
            $old = $gateway->preview(conversation: $conversation, viewer: $viewer, request: $f->request(ReadTarget::page(new D\LocalId('p1'))));
            self::assertSame(Fixture::corpus()['page'], $old->data->body->bytes());
            // Fresh context plus core's post-object-I/O source recheck: no cached grant.
            $f->upstream->objects->beforeOpen = fn () => $f->upstream->revoke();
            $denied = $gateway->preview(conversation: $conversation, viewer: $viewer, request: $f->request(ReadTarget::page(new D\LocalId('p1'))));
            self::assertSame(D\Outcome::Denied, $denied->outcome);
            self::assertNull($denied->data);
            self::assertSame(3, $viewers->reads);
            self::assertSame($document->toArray(), (new ChatomReplyMapper())->map($content)->toArray());
        });
    }
}
