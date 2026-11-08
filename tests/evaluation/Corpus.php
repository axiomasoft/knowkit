<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tooling\Qa;

/** Versioned test data, never a runtime source of authorization. */
final readonly class Corpus
{
    public const REQUIRED_CASE_IDS = [
        'exact-document', 'exact-function', 'exact-phrase', 'cyrillic', 'cjk-literal', 'english',
        'mixed-identifier', 'rare-term', 'cross-language', 'table-units', 'page-span',
        'wrong-version-phrase', 'denied-hard-negative', 'no-evidence', 'unavailable-basis',
        'unknown-owner', 'denied-owner', 'post-revoke',
    ];

    public array $manifest;
    public array $documents;
    public array $questions;
    public string $manifestSha256;

    public function __construct(string $path)
    {
        $this->manifest = self::json($path);
        $this->manifestSha256 = hash_file('sha256', $path);
        Qa::ensure(($this->manifest['schemaVersion'] ?? '') === 'evaluation-corpus:1', 'unknown corpus schema');
        Qa::ensure(!empty($this->manifest['versionRationale']) && !empty($this->manifest['annotationVersion']), 'unversioned annotations');
        Qa::ensure(($this->manifest['rights']['approved'] ?? false) === true, 'unapproved corpus rights');
        $files = [];
        foreach ($this->manifest['files'] as $entry) {
            Qa::ensure(!empty($entry['license']) && !empty($entry['origin']), 'missing rights');
            $file = realpath(dirname($path) . '/' . $entry['path']);
            Qa::ensure($file !== false && str_starts_with($file, realpath(dirname($path)) . DIRECTORY_SEPARATOR), 'missing/escaping corpus file');
            Qa::ensure(!isset($files[$entry['path']]), 'duplicate corpus file');
            Qa::ensure(hash_file('sha256', $file) === $entry['sha256'], 'corpus digest mismatch');
            $files[$entry['path']] = true;
        }
        Qa::ensure(isset($files['corpus.v1.json'], $files['extraction.v1.json'], $files['sources/structure.pdf']), 'missing required inventory');
        $data = self::json(dirname($path) . '/corpus.v1.json');
        $this->documents = $data['documents'];
        $this->questions = $data['questions'];
        Qa::ensure($this->documents !== [] && $this->questions !== [], 'empty corpus');
        $ids = array_column($this->questions, 'id');
        Qa::ensure(count($ids) === count(array_unique($ids)), 'duplicate question');
        Qa::ensure(array_diff(self::REQUIRED_CASE_IDS, $ids) === [], 'missing mandatory question');
        Qa::ensure($ids[array_key_last($ids)] === 'post-revoke', 'committed revoke must remain last');
        foreach ($this->documents as $doc) {
            Qa::ensure(isset($files[$doc['file']]), 'unhashed source file');
            $ir = new D\IrDocument(StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            Qa::ensure(hash('sha256', $doc['text']) === $doc['textDigest'], 'text digest mismatch');
            Qa::ensure(hash('sha256', $ir->canonical()) === $doc['chunkSetManifest']['revisionRef']['irDigest'], 'IR digest mismatch');
            Qa::ensure(file_get_contents(dirname($path) . '/' . $doc['file']) === $doc['text'], 'source text mismatch');
        }
    }

    public static function json(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function document(string $name): array
    {
        foreach ($this->documents as $doc) {
            if ($doc['name'] === $name) {
                return $doc;
            }
        }
        throw new \InvalidArgumentException('Unknown annotated document');
    }

    public static function fingerprint(array $data): string
    {
        return hash('sha256', Jcs::canonicalize(StrictJson::decode(json_encode($data, JSON_THROW_ON_ERROR))));
    }
}
