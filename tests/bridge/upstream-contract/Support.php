<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\UpstreamContract;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use Closure;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\BoundaryStage;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContextBlock;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContextSnapshot;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\Contract;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContributionPin;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContributionReceipt;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\CurrentSourceAuthority;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EffectiveProfile;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EvidenceContextContributor;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EvidenceContextRequest;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EvidenceContribution;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\IncomingInput;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\TextComposition;
use KnowKit\Tests\Bridge\Contracts\Host;
use KnowKit\Tests\Bridge\Contracts\Support as LegacySupport;
use RuntimeException;

require_once dirname(__DIR__) . '/contracts/Support.php';
require_once __DIR__ . '/CaseManifest.php';

final class Contributor implements EvidenceContextContributor
{
    public int $collectCalls = 0;

    public function __construct(
        public EvidenceContribution $contribution,
        public ContributionPin $declaredPin,
    ) {
    }

    public function pin(): ContributionPin
    {
        return $this->declaredPin;
    }

    public function collect(ExecutionContext $context, IncomingInput $input, EvidenceContextRequest $request): EvidenceContribution
    {
        $this->collectCalls++;

        return $this->contribution;
    }
}

/** Scripted current owner; the real native owner and DB/compiler are deliberately absent. */
final class SourceAuthority implements CurrentSourceAuthority
{
    public string $decision = 'allowed';
    public string $epoch = '1';
    public bool $ancestorsActive = true;
    public string $now = '2026-11-09T00:00:00Z';
    public ?BoundaryStage $deniedStage = null;
    public array $stages = [];

    public function __construct(
        private Host $host,
        private ContributionPin $pin,
        private ContextBlock $expected,
    ) {
    }

    public function id(): string
    {
        return $this->pin->id;
    }

    public function authorizeBlock(ExecutionContext $context, ContextBlock $block, ContributionReceipt $receipt, BoundaryStage $stage): void
    {
        $this->stages[] = $stage;
        $context->assertCurrent($this->host);
        if ($this->decision !== 'allowed' || !$this->ancestorsActive || $this->deniedStage === $stage
            || $receipt->id !== $this->id() || $receipt->pinDigest !== $this->pin->digest()
            || $receipt->hostScopeHash !== Contract::hostScopeHash($context)
            || $receipt->expiresAt <= $this->now
            || $receipt->sourceEpochs !== [['ref' => $block->sourceRef, 'epoch' => $this->epoch]]
            || $receipt->modelViewDigests !== [['ref' => $block->spanRef, 'hash' => $block->modelViewDigest()]]
            || get_object_vars($block) !== get_object_vars($this->expected)) {
            throw new RuntimeException('Current source missing, stale, denied or unknown');
        }
    }

    public function authorizeSnapshot(ExecutionContext $context, ContextSnapshot $snapshot, ContributionReceipt $receipt, BoundaryStage $stage): void
    {
        foreach ($snapshot->blocks as $block) {
            $this->authorizeBlock(context: $context, block: $block, receipt: $receipt, stage: $stage);
        }
    }
}

final class Scenario
{
    public Host $host;
    public ExecutionContext $context;
    public ContextBlock $block;
    public ContributionPin $pin;
    public ContributionReceipt $receipt;
    public EvidenceContextRequest $request;
    public Contributor $contributor;
    public SourceAuthority $authority;
    public int $sendCount = 0;
    public int $deliveryCount = 0;
    public array $sent = [];

    public function __construct()
    {
        [$this->host, $this->context] = LegacySupport::trusted();
        $matrix = Contract::json('contracts/agentools/pin-matrix.json');
        $this->pin = new ContributionPin(
            id: 'knowkit.evidence', version: '1.0.0',
            contractDigest: $matrix['contractDigest'], configDigest: Contract::digest(['profile' => 'compact']),
        );
        $this->block = new ContextBlock(
            sourceRef: 'source:immutable-1', sourceVersion: 'version:1',
            sourceHash: hash('sha256', 'Immutable source bytes'), irDigest: hash('sha256', 'IR version 1'),
            spanRef: 'span:0:24', modelView: 'Permitted evidence bytes',
        );
        $this->receipt = $this->newReceipt();
        $this->request = new EvidenceContextRequest(...$this->requestData());
        $this->contributor = new Contributor(contribution: $this->contribution(), declaredPin: $this->pin);
        $this->authority = new SourceAuthority(host: $this->host, pin: $this->pin, expected: $this->block);
    }

    public function requestData(): array
    {
        return ['turnId' => 'turn:1', 'runId' => 'run:1', 'invocationUseRef' => 'invocation:1',
            'snapshotUseRef' => 'snapshot:1', 'bundleRef' => 'bundle:1', 'bundleHash' => hash('sha256', 'bundle:1'),
            'hostScopeHash' => Contract::hostScopeHash($this->context), 'maxFragments' => 4, 'maxBytes' => 4096,
            'operation' => 'ModelEgressContext', 'now' => '2026-11-09T00:00:00Z'];
    }

    public function newReceipt(array $replace = []): ContributionReceipt
    {
        return new ContributionReceipt(...array_replace([
            'schema' => 'urn:knowkit:agentools-contribution-receipt:1.0', 'id' => $this->pin->id,
            'pinDigest' => $this->pin->digest(), 'hostScopeHash' => Contract::hostScopeHash($this->context),
            'selectionRefs' => ['selection:1'], 'evidenceSetIds' => ['evidence-set:1'],
            'sourceEpochs' => [['ref' => $this->block->sourceRef, 'epoch' => '1']],
            'expiresAt' => '2026-11-09T00:01:00Z',
            'modelViewDigests' => [['ref' => $this->block->spanRef, 'hash' => $this->block->modelViewDigest()]],
        ], $replace));
    }

    public function contribution(?array $blocks = null, ?ContributionReceipt $receipt = null): EvidenceContribution
    {
        return new EvidenceContribution(pin: $this->pin, blocks: $blocks ?? [$this->block],
            dependencies: [['ref' => $this->block->sourceRef, 'version' => $this->block->sourceVersion, 'hash' => $this->block->sourceHash]],
            receipt: $receipt ?? $this->receipt);
    }

    public function compile(?array $authorities = null, ?array $contributors = null): array
    {
        $composition = new TextComposition(
            legacyCompiler: static fn (): string => Contract::fixture()['golden']['requestBytes'],
            evidenceContributors: $contributors ?? [$this->contributor],
        );

        return $composition->compile(context: $this->context, input: new IncomingInput('untrusted question'),
            request: $this->request, profile: new EffectiveProfile('test'),
            authorities: $authorities ?? [$this->pin->id . ':' . $this->pin->digest() => $this->authority]);
    }

    public function exchange(array $compiled, ?Closure $beforeSend = null, ?Closure $afterSend = null): void
    {
        $beforeSend?->__invoke();
        $compiled['compiled']->authorize(context: $this->context, stage: BoundaryStage::BeforeModelIo);
        $this->sendCount++;
        $this->sent[] = $compiled['requestBytes'];
        $afterSend?->__invoke();
        foreach ([BoundaryStage::AfterDurableIntent, BoundaryStage::BufferedDelivery, BoundaryStage::ActualDelivery] as $stage) {
            $compiled['compiled']->authorize(context: $this->context, stage: $stage);
        }
        $this->deliveryCount++;
    }
}
