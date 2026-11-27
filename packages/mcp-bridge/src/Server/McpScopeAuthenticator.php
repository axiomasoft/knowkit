<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\DomainFailure;

interface McpScopeAuthenticator
{
    /**
     * Authenticate transport-level credentials (OAuth 2.1 bearer token or signed host context)
     * into an immutable server-side scope context.
     *
     * @param array<string, string|list<string>> $transportHeaders
     * @throws DomainFailure
     */
    public function authenticate(array $transportHeaders): AuthenticatedMcpContext;
}
