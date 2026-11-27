<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\Layers\ResolutionTier;

interface McpBackend
{
    /**
     * @return array<string, mixed>
     */
    public function navigateOutline(AuthenticatedMcpContext $context, string $revisionId, int $maxDepth, int $limit): array;

    /**
     * Must return ONLY {selectionToken: string, candidatesMeta: list<array<string, mixed>>} with zero chunk text.
     *
     * @return array{selectionToken: string, candidatesMeta: list<array<string, mixed>>}
     */
    public function searchLayered(AuthenticatedMcpContext $context, string $query, ?string $stackId, int $topK): array;

    /**
     * @return array<string, mixed>
     */
    public function discloseEvidence(
        AuthenticatedMcpContext $context,
        string $selectionToken,
        ResolutionTier $tier,
        int $maxFragments,
    ): array;

    /**
     * @param list<string> $unitIds
     * @return array<string, mixed>
     */
    public function readUnits(AuthenticatedMcpContext $context, string $revisionId, array $unitIds): array;

    /**
     * @param list<string> $citations
     * @return array<string, mixed>
     */
    public function verifyGrounding(
        AuthenticatedMcpContext $context,
        string $selectionToken,
        string $responseText,
        array $citations,
    ): array;

    /**
     * @return list<array{uri: string, name: string, mimeType: string, description: string}>
     */
    public function listResources(AuthenticatedMcpContext $context): array;

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    public function readOutlineResource(AuthenticatedMcpContext $context, string $kb, string $revision): array;

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    public function readDigestResource(AuthenticatedMcpContext $context, string $kb, string $artifactId): array;

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    public function readCitationResource(AuthenticatedMcpContext $context, string $kb, string $citationId): array;
}
