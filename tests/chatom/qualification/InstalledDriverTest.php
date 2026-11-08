<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Internal\Services\TransactionalAgentReply;
use Chatom\Actors\Data\ActorRef;
use Chatom\Channels\Contracts\Channels;
use Chatom\Channels\Data\ArchiveChannel;
use Chatom\Channels\Data\FindChannel;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Contracts\Messages;
use Chatom\Members\Contracts\Members;
use Chatom\Members\Data\RemoveMember;
use Chatom\Messages\Data\SendMessage;
use Chatom\Operations\Enums\ReceiptCode;
use Chatom\Operations\Exceptions\ReceiptConflictException;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Chatom\Handler\HandlerFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('chatom:qualification')]
final class InstalledDriverTest extends TestCase
{
    public static function supportedProfiles(): array
    {
        return ['pg17' => [17], 'pg18' => [18]];
    }

    #[DataProvider('supportedProfiles')]
    public function testInstalledDriverWritesOneNativeReplyAndOutbox(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $trigger = $host->trigger();
            self::assertSame(0, $host->relay()['exit']);
            self::assertCount(1, $host->handler->invocations);
            self::assertSame([TransactionalAgentReply::class], $host->handler->nativeReplies);
            self::assertSame(1, $host->handler->hosts['tutor']->answer->sends);
            self::assertSame([], array_map(static fn (\Throwable $error): string => $error->getMessage(), $host->handler->errors));
            self::assertCount(2, $host->rows('messages'));
            $slots = $host->rows('agent_reply_slots');
            self::assertCount(1, $slots);
            self::assertSame('final.v1', $slots[0]['slot']);
            self::assertSame('committed', $slots[0]['state']);
            self::assertSame($host->handler->invocations[0]->invocationKey, $slots[0]['invocation_key']);
            self::assertNotSame($trigger->message->id->value, $slots[0]['message_id']);
            self::assertNotEmpty($host->rows('outbox'));
            self::assertNotEmpty($host->rows('receipts'));
        } finally {
            $host->close();
        }
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach ([17, 18] as $major) {
            foreach (['learner-denied', 'consent-unknown', 'source-revoked', 'binding-stale'] as $reason) {
                $cases["pg{$major}:{$reason}"] = [$major, $reason];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function testCurrentAudienceIsRecheckedInsideNativeWrite(int $major, string $reason): void
    {
        $host = new NativeHost($major);
        try {
            $host->trigger();
            $host->handler->revokeAtWrite = static function () use ($host, $reason): void {
                $fixture = $host->handler->hosts['tutor'];
                if ($reason === 'binding-stale') {
                    $fixture->changeBinding();

                    return;
                }
                $fixture->denied = [$reason];
            };
            self::assertSame(0, $host->relay()['exit']);
            self::assertSame(1, $host->handler->hosts['tutor']->answer->sends);
            self::assertCount(1, $host->rows('messages'));
            self::assertSame([], $host->rows('agent_reply_slots'));
            self::assertSame([], array_values(array_filter(
                $host->rows('outbox'),
                static fn (array $row): bool => str_contains($row['payload'], 'knowkit:citation:'),
            )));
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testNativeChannelPolicyCanRefuseAfterGeneration(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $trigger = $host->trigger();
            $host->handler->beforeHandle = static function (HandlerFixture $fixture) use ($host, $trigger): void {
                $fixture->answer->onSend = static function () use ($host, $trigger): void {
                    $channels = $host->app->make(Channels::class);
                    $current = $channels->find(new FindChannel(channel: $trigger->message->channel));
                    $channels->archive(new ArchiveChannel(
                        channel: $current->id,
                        actor: ActorRef::of(type: 'user', id: 'learner'),
                        expectedRevision: $current->revision,
                    ));
                };
            };
            $host->relay();
            self::assertSame(1, $host->handler->hosts['tutor']->answer->sends);
            self::assertCount(1, $host->rows('messages'));
            self::assertSame([], $host->rows('agent_reply_slots'));
            self::assertNotEmpty($host->handler->errors);
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testLostAckRecoversNativeSlotAndSavedAnswerWithoutRegeneration(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $host->trigger();
            $host->handler->loseAck = true;
            $host->relay();
            $slots = $host->rows('agent_reply_slots');
            self::assertCount(1, $slots);
            self::assertCount(1, $host->handler->errors);
            self::assertSame('QUALIFICATION_LOST_ACK_AFTER_NATIVE_COMMIT', $host->handler->errors[0]->getMessage());
            $receipts = $host->rows('receipts');
            $messages = $host->rows('messages');
            $host->makePendingDeliveryDue();
            $host->relay();
            self::assertCount(2, $host->handler->invocations);
            self::assertSame($slots, $host->rows('agent_reply_slots'));
            self::assertSame($messages, $host->rows('messages'));
            self::assertSame($receipts, $host->rows('receipts'));
            self::assertSame(1, $host->handler->hosts['tutor']->answer->sends);
            self::assertSame($host->handler->invocations[0]->invocationKey, $host->handler->invocations[1]->invocationKey);
            $status = $host->command(name: 'chatom:outbox', parameters: ['action' => 'status', '--consumer' => 'core.agent', '--json' => true]);
            self::assertSame(0, $status['exit']);
            self::assertSame('succeeded', json_decode($status['output'], true, flags: JSON_THROW_ON_ERROR)['rows'][0]['status']);
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testNativeReceiptReplaysIdenticalBodyAndRejectsChangedBody(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $trigger = $host->trigger();
            $command = new SendMessage(
                channel: $trigger->message->channel,
                sender: $trigger->message->sender,
                content: $trigger->message->content,
                clientMessageId: 'qualification.trigger',
            );
            $messages = $host->app->make(Messages::class);
            $replay = $messages->send($command);
            self::assertSame(ReceiptCode::Replayed, $replay->receipt);
            self::assertSame($trigger->message->id->value, $replay->message->id->value);
            try {
                $messages->send(new SendMessage(
                    channel: $command->channel,
                    sender: $command->sender,
                    content: MessageDocument::fromPlainText('Changed body must not replace saved content'),
                    clientMessageId: $command->clientMessageId,
                ));
                self::fail('Changed same-key body must conflict');
            } catch (ReceiptConflictException $error) {
                self::assertSame('fingerprint_mismatch', $error->getMessage());
            }
            self::assertCount(1, $host->rows('messages'));
            self::assertSame([], $host->handler->invocations);
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testDistinctBotsKeepNativeKeysSlotsAndAnswersIsolated(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $host->trigger(['tutor', 'second']);
            $host->handler->beforeHandle = static function (HandlerFixture $fixture, $invocation): void {
                $fixture->answer->outputText = Jcs::canonicalize(new JsonObject([
                    'text' => 'Answer for ' . $invocation->agent->id,
                    'citations' => [$fixture->answer->citation()->toWire()],
                ]));
            };
            $host->relay();
            self::assertCount(2, $host->handler->invocations);
            $keys = array_map(static fn ($invocation): string => $invocation->invocationKey, $host->handler->invocations);
            self::assertCount(2, array_unique($keys));
            self::assertCount(2, $host->rows('agent_reply_slots'));
            self::assertCount(3, $host->rows('messages'));
            foreach (['tutor', 'second'] as $bot) {
                self::assertSame(1, $host->handler->hosts[$bot]->answer->sends);
                $reply = array_values(array_filter(
                    $host->rows('messages'),
                    static fn (array $message): bool => $message['sender_type'] === 'bot' && $message['sender_id'] === $bot,
                ));
                self::assertCount(1, $reply);
                self::assertStringContainsString('Answer for ' . $bot, $reply[0]['body']);
                $invocation = array_values(array_filter($host->handler->invocations, static fn ($item): bool => $item->agent->id === $bot))[0];
                self::assertSame($invocation->invocationKey, $host->handler->hosts[$bot]->answer->ledger->record->invocationRef->key->value);
            }
            self::assertNotSame(
                $host->handler->hosts['tutor']->answer->ledger->record,
                $host->handler->hosts['second']->answer->ledger->record,
            );
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testRevokedNativeMembershipCancelsBeforeModelOrReply(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $trigger = $host->trigger();
            $host->app->make(Members::class)->remove(new RemoveMember(
                channel: $trigger->message->channel,
                member: ActorRef::of(type: 'bot', id: 'tutor'),
                actor: ActorRef::of(type: 'user', id: 'learner'),
            ));
            $host->relay();
            self::assertSame([], $host->handler->invocations);
            self::assertSame([], $host->rows('agent_reply_slots'));
            self::assertCount(1, $host->rows('messages'));
            $status = $host->command(name: 'chatom:outbox', parameters: ['action' => 'status', '--consumer' => 'core.agent', '--json' => true]);
            $row = json_decode($status['output'], true, flags: JSON_THROW_ON_ERROR)['rows'][0];
            self::assertSame('cancelled', $row['status']);
            self::assertSame('AGENT_MEMBERSHIP_REVOKED', $row['error_code']);
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testSavedReceiptCannotAuthorizeReplayAfterAudienceRevocation(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $host->trigger();
            $host->handler->loseAck = true;
            $host->relay();
            $messages = $host->rows('messages');
            $slots = $host->rows('agent_reply_slots');
            $fixture = $host->handler->hosts['tutor'];
            $settlements = $fixture->answer->commits;
            $host->handler->beforeHandle = static function (HandlerFixture $fixture): void {
                $fixture->denied = ['current-audience-revoked'];
            };
            $host->makePendingDeliveryDue();
            $host->relay();
            self::assertCount(2, $host->handler->invocations);
            self::assertSame($messages, $host->rows('messages'));
            self::assertSame($slots, $host->rows('agent_reply_slots'));
            self::assertSame(1, $fixture->answer->sends);
            self::assertSame($settlements, $fixture->answer->commits);
        } finally {
            $host->close();
        }
    }

    #[DataProvider('supportedProfiles')]
    public function testOrchestratedBindingDoesNotActivateDirectHandlerBesideDistinctDirectBot(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $host->trigger(['tutor', 'second']);
            $host->handler->beforeHandle = static function (HandlerFixture $fixture, $invocation): void {
                if ($invocation->agent->id !== 'second') {
                    return;
                }
                $binding = $fixture->binding;
                $fixture->binding = new ConversationBinding(
                    conversation: $binding->conversation,
                    route: ConversationRoute::AgentoolsOrchestrated,
                    kbId: $binding->kbId,
                    modelBindingRef: $binding->modelBindingRef,
                    hostScopeInputs: $binding->hostScopeInputs,
                    viewerBindingRef: $binding->viewerBindingRef,
                    bindingRevision: $binding->bindingRevision,
                );
            };
            $host->relay();
            self::assertSame(1, $host->handler->hosts['tutor']->answer->sends);
            self::assertSame(0, $host->handler->hosts['second']->answer->sends);
            self::assertNull($host->handler->hosts['second']->answer->ledger->record);
            self::assertCount(1, $host->rows('agent_reply_slots'));
        } finally {
            $host->close();
        }
    }
}
