<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{LocalId, Sha256, TextLocator, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class ProtectedAtom
{
    public const ALLOWED_KINDS = ['formula', 'code', 'table', 'atomic'];

    public function __construct(
        public LocalId $atomId,
        public string $kind,
        public string $content,
        public Sha256 $contentDigest,
        public ?TextLocator $locator = null,
    ) {
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported protected atom kind');
        }
        if ($content === '' || preg_match('//u', $content) !== 1) {
            throw new \InvalidArgumentException('Nonempty UTF-8 content required for protected atom');
        }
        if (!hash_equals($contentDigest->value, hash('sha256', $content))) {
            throw new \InvalidArgumentException('Protected atom contentDigest mismatch');
        }
    }

    public static function fromContent(
        LocalId $atomId,
        string $kind,
        string $content,
        ?TextLocator $locator = null,
    ): self {
        return new self(
            atomId: $atomId,
            kind: $kind,
            content: $content,
            contentDigest: new Sha256(hash('sha256', $content)),
            locator: $locator,
        );
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && hash_equals($this->contentDigest->value, $other->contentDigest->value)
            && $this->content === $other->content;
    }

    public function toWire(): JsonObject
    {
        $wire = new JsonObject([
            'atomId' => $this->atomId->value,
            'kind' => $this->kind,
            'content' => $this->content,
            'contentDigest' => $this->contentDigest->value,
        ]);
        if ($this->locator !== null) {
            $wire = $wire->with('locator', $this->locator->toWire());
        }
        return $wire;
    }
}
