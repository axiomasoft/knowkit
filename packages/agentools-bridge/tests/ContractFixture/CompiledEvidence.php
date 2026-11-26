<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use RuntimeException;

final readonly class CompiledEvidence
{
    /** @param list<EvidenceContribution> $contributions
     * @param array<string, CurrentSourceAuthority> $authorities keys are id:pinDigest */
    public function __construct(
        public array $contributions,
        private array $authorities,
    ) {
    }

    public function authorize(ExecutionContext $context, BoundaryStage $stage): void
    {
        foreach ($this->contributions as $contribution) {
            $key = $contribution->pin->id . ':' . $contribution->pin->digest();
            $authority = $this->authorities[$key] ?? null;
            if (!$authority instanceof CurrentSourceAuthority || $authority->id() !== $contribution->pin->id) {
                throw new RuntimeException('Missing current authority for exact id+pinDigest');
            }
            foreach ($contribution->blocks as $block) {
                $authority->authorizeBlock(context: $context, block: $block, receipt: $contribution->receipt, stage: $stage);
            }
            $authority->authorizeSnapshot(
                context: $context,
                snapshot: new ContextSnapshot($contribution->blocks),
                receipt: $contribution->receipt,
                stage: $stage,
            );
        }
    }

    public function modelText(): string
    {
        $text = '';
        foreach ($this->contributions as $contribution) {
            foreach ($contribution->blocks as $block) {
                $text .= "\n" . $block->modelView;
            }
        }

        return $text;
    }

    public function dependencies(): array
    {
        $seen = [];
        $dependencies = [];
        foreach ($this->contributions as $contribution) {
            foreach ($contribution->dependencies as $dependency) {
                if (!is_array($dependency) || count($dependency) !== 3
                    || !is_string($dependency['ref'] ?? null)
                    || !is_string($dependency['version'] ?? null)
                    || !is_string($dependency['hash'] ?? null)) {
                    throw new RuntimeException('Dependency identity/hash conflict');
                }
                $identity = [$dependency['ref'], $dependency['version'], $dependency['hash']];
                $key = strlen($dependency['ref']) . ':' . $dependency['ref']
                    . strlen($dependency['version']) . ':' . $dependency['version'];
                if (isset($seen[$key]) && $seen[$key] !== $identity) {
                    throw new RuntimeException('Dependency identity/hash conflict');
                }
                if (!isset($seen[$key])) {
                    $seen[$key] = $identity;
                    $dependencies[] = $dependency;
                }
            }
        }

        return $dependencies;
    }

    public function retentionManifest(): array
    {
        return [
            'contributions' => array_map(static fn (EvidenceContribution $contribution): array => [
                'pin' => get_object_vars($contribution->pin),
                'receipt' => get_object_vars($contribution->receipt),
            ], $this->contributions),
            'dependencies' => $this->dependencies(),
        ];
    }
}
