<?php
declare(strict_types=1);
namespace KnowKit\Application\Publication\Activate;

use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Domain as D;
use KnowKit\Port\SnapshotActivation;

/** All owner/blob I/O precedes the short SQL acceptance transaction. */
final readonly class Publisher
{
    public function __construct(private SnapshotActivation $store, private MutationAuthorizer $authority,
        private IndexReadabilityProbe $probe) {}

    public function publish(D\ActivationRequest $request): D\PublishReceipt
    {
        $replay = $this->store->replay($request);
        if ($replay !== null) { return $replay; }
        $this->probe->verify($request->candidate);
        $s = $request->lease->scope;
        $this->authority->prepare($s, new D\ResourceRef($s->tenantId, 'knowledge_base', $s->kbId), D\Operation::Publish);
        foreach ($request->sources() as $id) {
            $this->authority->prepare($s, new D\ResourceRef($s->tenantId, 'source', $id), D\Operation::Publish);
        }
        return $this->store->activate($request);
    }
}
