<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Layers\DirectiveArtifact;
use KnowKit\Domain\Layers\TrustTier;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ValueGuard;

final readonly class DirectivePromptHandler
{
    /** @var list<DirectiveArtifact> */
    private array $directives;

    /**
     * @param list<DirectiveArtifact> $directives
     */
    public function __construct(array $directives = [])
    {
        ValueGuard::objects($directives, DirectiveArtifact::class);
        $this->directives = $directives;
    }

    /**
     * @return list<array{name: string, description: string, arguments: list<mixed>}>
     */
    public function list(AuthenticatedMcpContext $context): array
    {
        $prompts = [];
        foreach ($this->directives as $directive) {
            if (!$directive->tenantId->equals($context->scope->tenantId)) {
                continue;
            }
            $prompts[] = [
                'name' => $this->promptName($directive),
                'description' => $directive->title,
                'arguments' => [],
            ];
        }

        return $prompts;
    }

    /**
     * @return array{
     *   description: string,
     *   messages: list<array{role: string, content: array{type: string, text: string}}>,
     *   meta: array{
     *     directiveId: string,
     *     version: int,
     *     artifactDigest: string,
     *     trustTier: string,
     *     lockedRules: array<string, string|int|bool>
     *   }
     * }
     */
    public function get(AuthenticatedMcpContext $context, string $name): array
    {
        foreach ($this->directives as $directive) {
            if (!$directive->tenantId->equals($context->scope->tenantId)) {
                continue;
            }
            if ($this->promptName($directive) === $name) {
                /** @var array<string, string|int|bool> $lockedRules */
                $lockedRules = iterator_to_array($directive->lockedRules->getIterator());

                return [
                    'description' => $directive->title,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => $directive->body,
                            ],
                        ],
                    ],
                    'meta' => [
                        'directiveId' => $directive->directiveId->value,
                        'version' => $directive->version,
                        'artifactDigest' => $directive->artifactDigest()->value,
                        'trustTier' => TrustTier::TrustedDirective->value,
                        'lockedRules' => $lockedRules,
                    ],
                ];
            }
        }

        throw new DomainFailure(Outcome::Denied, 'prompt_not_found');
    }

    private function promptName(DirectiveArtifact $directive): string
    {
        return 'knowkit.directive.' . $directive->directiveId->value . '.v' . $directive->version;
    }
}
