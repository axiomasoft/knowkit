<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Intl;

use KnowKit\Domain as D;
use KnowKit\Domain\Navigation\Projection;
use KnowKit\Port\NfdNormalizer;

/** Explicit optional capability. Never selected implicitly by the whitespace baseline. */
final class NfdNormalizerAdapter implements NfdNormalizer
{
    public function readiness(): string
    {
        return extension_loaded('intl') && class_exists(\Normalizer::class) && class_exists(\IntlChar::class)
            ? 'ready' : 'failed';
    }

    public function fingerprint(): D\Fingerprint
    {
        $this->requireReady();

        return new D\Fingerprint(hash('sha256', 'unicode-nfd-intl-v1|ICU=' . INTL_ICU_VERSION
            . '|Unicode=' . implode('.', \IntlChar::getUnicodeVersion()) . '|form=NFD|whitespace=preserve'));
    }

    public function project(string $text): Projection
    {
        $this->requireReady();
        if (strlen($text) > 8388608 || preg_match('//u', $text) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'projection_limit');
        }
        $output = '';
        $map = '';
        $offset = 0;
        while (preg_match('/\G\X/u', $text, $match, 0, $offset) === 1) {
            $cluster = $match[0];
            $parts = $this->decompose($cluster, $offset);
            $normalized = '';
            foreach ($parts as [$char, $start, $end]) {
                $normalized .= $char;
                $from = strlen($output);
                $output .= $char;
                $map .= pack('N4', $from, strlen($output), $start, $end);
                if (strlen($map) > 134217728) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'projection_limit');
                }
            }
            if ($normalized !== \Normalizer::normalize($cluster, \Normalizer::FORM_D)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_mapping');
            }
            $offset += strlen($cluster);
        }
        if ($offset !== strlen($text)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_mapping');
        }

        return new Projection($output, $map);
    }

    /** @return list<array{string,int,int,int}> */
    private function decompose(string $cluster, int $offset): array
    {
        if (strlen($cluster) > 8192) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_cluster_limit');
        }
        $parts = [];
        $chars = preg_split('//u', $cluster, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_mapping');
        }
        foreach ($chars as $char) {
            $end = $offset + strlen($char);
            $decomposed = \Normalizer::normalize($char, \Normalizer::FORM_D);
            if (!is_string($decomposed)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_mapping');
            }
            $values = preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($values)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_mapping');
            }
            foreach ($values as $value) {
                $class = \IntlChar::getCombiningClass($value);
                $parts = $this->insert($parts, [$value, $offset, $end, $class]);
            }
            $offset = $end;
        }

        return $parts;
    }

    /**
     * @param list<array{string,int,int,int}> $parts
     * @param array{string,int,int,int} $part
     * @return list<array{string,int,int,int}>
     */
    private function insert(array $parts, array $part): array
    {
        $index = count($parts);
        while ($part[3] !== 0 && $index > 0 && $parts[$index - 1][3] > $part[3]) {
            --$index;
        }
        array_splice($parts, $index, 0, [$part]);

        return $parts;
    }

    private function requireReady(): void
    {
        if ($this->readiness() !== 'ready') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'normalizer_unavailable');
        }
    }
}
