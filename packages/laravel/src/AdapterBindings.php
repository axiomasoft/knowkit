<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use Illuminate\Contracts\Container\Container;
use KnowKit\Domain\AdapterKind;
use KnowKit\Port;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Registry\HostProfile;

/** Registration performs only offline validation; adapter factories stay lazy. */
final class AdapterBindings
{
    public static function register(Container $container, HostProfile $profile): void
    {
        $effective = $profile->effectiveProfile();
        foreach ($effective->adapterBindings as $kind => $id) {
            $contract = self::contract(AdapterKind::from($kind));
            $container->singleton(abstract: $contract, concrete: static fn (Container $app): object =>
                $app->make(AdapterRegistry::class)->resolve(id: $id, profile: $effective));
        }
    }

    private static function contract(AdapterKind $kind): string
    {
        return match ($kind) {
            AdapterKind::Parser => Port\DocumentParser::class,
            AdapterKind::Chunker => Port\Chunker::class,
            AdapterKind::Lexical => Port\LexicalIndex::class,
            AdapterKind::Vector => Port\VectorIndex::class,
            AdapterKind::Embedding => Port\EmbeddingProvider::class,
            AdapterKind::Reranker => Port\Reranker::class,
            AdapterKind::SourceConnector => Port\SourceConnector::class,
            AdapterKind::SourceReader => Port\SourceReader::class,
            AdapterKind::BlobStore => Port\BlobStore::class,
            AdapterKind::AccessPolicy => Port\AccessPolicy::class,
            AdapterKind::EgressPolicy => Port\EgressPolicy::class,
            AdapterKind::Jobs => Port\JobQueue::class,
            AdapterKind::Outbox => Port\Outbox::class,
            AdapterKind::Clock => Port\Clock::class,
            AdapterKind::Tokenizer => Port\Tokenizer::class,
        };
    }
}
