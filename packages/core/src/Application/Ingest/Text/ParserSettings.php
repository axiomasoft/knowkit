<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Text;

use KnowKit\Domain\{DomainFailure, Outcome, ParserProfile};
use KnowKit\Domain\Contract\JsonObject;

/** Every effective option, including resource ceilings, is part of parser identity. */
final readonly class ParserSettings
{
    private function __construct(
        public int $maxSourceBytes, public int $maxLines, public int $maxLineBytes,
        public int $maxBlocks, public int $maxTableCells,
    ) {}

    public static function profile(string $format, int $maxSourceBytes = 1048576, int $maxLines = 16384,
        int $maxLineBytes = 1048576, int $maxBlocks = 4096, int $maxTableCells = 16384): ParserProfile
    {
        if (!in_array($format, ['text', 'markdown'], true)) { throw new \InvalidArgumentException('Unknown text format'); }
        $profile = new ParserProfile($format . '-v1', 'knowkit.' . $format, '1.0.0', new JsonObject([
            'encoding' => 'UTF-8', 'normalization' => 'text-v1', 'subset' => $format . '-v1',
            'maxSourceBytes' => $maxSourceBytes, 'maxLines' => $maxLines, 'maxLineBytes' => $maxLineBytes,
            'maxBlocks' => $maxBlocks, 'maxTableCells' => $maxTableCells,
        ]));
        self::from($profile, $format);
        return $profile;
    }

    public static function from(ParserProfile $profile, string $format): self
    {
        $o = $profile->options->toWire();
        $keys = ['encoding','normalization','subset','maxSourceBytes','maxLines','maxLineBytes','maxBlocks','maxTableCells'];
        if ($profile->signature->name !== 'knowkit.' . $format || $profile->signature->version !== '1.0.0'
            || count($o) !== count($keys) || array_diff($o->keys(), $keys) !== []
            || $o->get('encoding') !== 'UTF-8' || $o->get('normalization') !== 'text-v1'
            || $o->get('subset') !== $format . '-v1') {
            throw new DomainFailure(Outcome::Incompatible, 'unsupported_parser_profile');
        }
        foreach (['maxSourceBytes' => 4194304, 'maxLines' => 16384, 'maxLineBytes' => 4194304,
            'maxBlocks' => 4096, 'maxTableCells' => 16384] as $key => $ceiling) {
            if (!is_int($o->get($key)) || $o->get($key) < 1 || $o->get($key) > $ceiling) {
                throw new DomainFailure(Outcome::Incompatible, 'unsupported_parser_profile');
            }
        }
        return new self($o->get('maxSourceBytes'), $o->get('maxLines'), $o->get('maxLineBytes'),
            $o->get('maxBlocks'), $o->get('maxTableCells'));
    }
}
