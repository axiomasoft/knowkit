<?php
declare(strict_types=1);

namespace KnowKit\Tooling;

use RuntimeException;
use Throwable;

/**
 * P1.3: adapted AgenTools mandatory-case/metadata and DeepTutor explicit graph /
 * fresh-process test patterns; no donor runtime imported. Modifications: PHP8.3
 * offline qualification, independent code/assets, notices and inclusion checks.
 * Provenance and restrictions: licenses/P1.3-reuse.json.
 * DeepTutor patterns copyright 2025 Data Intelligence Lab, University of Hong Kong.
 */
final class LicenseAudit
{
    public function __construct(private readonly string $root) {}

    /** @phpstan-assert true $valid */
    private static function require(bool $valid, string $reason): void
    {
        if (!$valid) {
            throw new RuntimeException($reason);
        }
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== ''
            && !in_array(strtolower(trim($value)), ['unknown', 'unavailable', 'pending', 'noassertion'], true);
    }

    private static function revision(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $value) === 1;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function url(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parts = parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    public function file(string $path): string
    {
        self::require($path !== '' && !str_starts_with($path, '/')
            && !str_contains($path, '\\') && !in_array('..', explode('/', $path), true), 'invalid local evidence path');
        $root = realpath($this->root);
        self::require($root !== false, 'missing evidence root');
        $resolved = realpath($root . '/' . $path);
        self::require($resolved !== false && is_file($resolved)
            && str_starts_with($resolved, $root . '/'), 'missing/escaping evidence: ' . $path);
        return $resolved;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::require($content !== false, 'cannot read file: ' . $path);
        return $content;
    }

    public function json(string $path): array
    {
        $value = json_decode(self::read($this->file($path)), true, 512, JSON_THROW_ON_ERROR);
        self::require(is_array($value), 'expected JSON object');
        return $value;
    }

    private function evidence(array $entry): void
    {
        self::require(is_string($entry['path'] ?? null) && self::digest($entry['sha256'] ?? null), 'incomplete evidence');
        self::require(hash_file('sha256', $this->file($entry['path'])) === $entry['sha256'], 'evidence digest mismatch: ' . $entry['path']);
    }

    private function qualification(array $record): void
    {
        foreach (['id', 'version', 'sourceUrl', 'sourceRevision', 'codeLicense', 'licenseFileDigest',
            'notices', 'transitiveComponents', 'modelAssets', 'datasets', 'networkAtBuild',
            'networkAtRuntime', 'distributionConstraints', 'enabledProfiles', 'verdict', 'reason', 'checkedAt'] as $key) {
            self::require(array_key_exists($key, $record), 'incomplete qualification: ' . $key);
        }
        foreach (['id', 'version', 'codeLicense', 'reason', 'checkedAt'] as $key) {
            self::require(self::text($record[$key]), 'unknown qualification: ' . $key);
        }
        self::require(in_array($record['verdict'], ['allowed', 'restricted'], true), 'blocked qualification: ' . $record['id']);
        self::require(self::url($record['sourceUrl']) && self::revision($record['sourceRevision']), 'unpinned source');
        self::require(self::digest($record['licenseFileDigest']), 'unknown code license digest');
        foreach (['notices', 'transitiveComponents', 'modelAssets', 'datasets', 'enabledProfiles'] as $key) {
            self::require(is_array($record[$key]) && array_is_list($record[$key]), 'invalid list: ' . $key);
        }
        self::require($record['notices'] !== [], 'missing notice');
        $licenseFound = false;
        foreach ($record['notices'] as $notice) {
            self::require(is_array($notice), 'invalid notice');
            $this->evidence($notice);
            $licenseFound = $licenseFound || $notice['sha256'] === $record['licenseFileDigest'];
        }
        self::require($licenseFound, 'code license notice missing');
        foreach ($record['selectedFiles'] ?? [] as $selected) {
            self::require(self::text($selected['path'] ?? null) && self::digest($selected['sha256'] ?? null)
                && self::url($selected['sourceUrl'] ?? null)
                && str_contains($selected['sourceUrl'], $record['sourceRevision'])
                && self::text($selected['span'] ?? null), 'unpinned selected source');
        }
        foreach (['networkAtBuild', 'networkAtRuntime'] as $key) {
            self::require(is_array($record[$key]) && is_bool($record[$key]['required'] ?? null)
                && is_array($record[$key]['destinations'] ?? null)
                && self::text($record[$key]['reason'] ?? null), 'unknown network requirements');
        }
        $constraints = $record['distributionConstraints'];
        self::require(is_array($constraints) && ($constraints['localUseAllowed'] ?? null) === true
            && is_bool($constraints['redistributionAllowed'] ?? null)
            && is_array($constraints['requirements'] ?? null), 'unfulfilled distribution constraints');
        foreach ($constraints['requirements'] as $requirement) {
            self::require(self::text($requirement['id'] ?? null) && ($requirement['fulfilled'] ?? null) === true
                && is_array($requirement['evidence'] ?? null) && $requirement['evidence'] !== [], 'unfulfilled requirement');
            foreach ($requirement['evidence'] as $evidence) {
                $this->evidence($evidence);
            }
        }
        if (stripos($record['codeLicense'], 'proprietary') !== false) {
            self::require($record['verdict'] === 'restricted' && $constraints['redistributionAllowed'] === false
                && in_array('owner-local-adaptation', array_column($constraints['requirements'], 'id'), true), 'proprietary permission missing');
        }
        foreach (['modelAssets', 'datasets'] as $kind) {
            foreach ($record[$kind] as $asset) {
                self::require(is_array($asset) && self::text($asset['id'] ?? null)
                    && self::text($asset['version'] ?? null) && self::digest($asset['sha256'] ?? null)
                    && self::text($asset['license'] ?? null) && self::url($asset['source'] ?? null)
                    && self::revision($asset['sourceRevision'] ?? null)
                    && str_contains($asset['source'], $asset['sourceRevision'])
                    && is_array($asset['notice'] ?? null), 'unqualified ' . $kind);
                $this->evidence($asset['notice']);
                self::require(($asset['localUseAllowed'] ?? null) === true
                    && is_bool($asset['redistributionAllowed'] ?? null), 'asset rights denied: ' . $kind);
            }
        }
    }

    /** Offline inclusion gate. Disabled candidates are inventory, never permission. */
    public function audit(array $catalog): array
    {
        self::require(($catalog['schemaVersion'] ?? '') === 'license-catalog:1', 'unknown catalog schema');
        self::require(is_array($catalog['qualificationFiles'] ?? null) && is_array($catalog['profiles'] ?? null)
            && $catalog['profiles'] !== [], 'empty catalog');
        $records = [];
        foreach ($catalog['qualificationFiles'] as $path) {
            $record = $this->json($path);
            self::require(self::text($record['id'] ?? null) && !isset($records[$record['id']]), 'duplicate/missing component');
            $records[$record['id']] = $record;
        }
        $selected = [];
        $visiting = [];
        $visit = function (string $id, string $profile) use (&$visit, &$visiting, $records): array {
            self::require(isset($records[$id]), 'missing qualification: ' . $id);
            self::require(!isset($visiting[$id]), 'cyclic qualification');
            $record = $records[$id];
            $this->qualification($record);
            self::require(in_array($profile, $record['enabledProfiles'], true), 'profile not qualified: ' . $id);
            $visiting[$id] = true;
            $closure = [$id => $record];
            foreach ($record['transitiveComponents'] as $dependency) {
                self::require(is_string($dependency), 'invalid dependency');
                $closure += $visit($dependency, $profile);
            }
            unset($visiting[$id]);
            return $closure;
        };
        $profiles = [];
        foreach ($catalog['profiles'] as $profile) {
            $closure = [];
            $id = $profile['id'] ?? null;
            self::require(self::text($id) && !isset($profiles[$id]) && is_bool($profile['enabled'] ?? null)
                && is_array($profile['components'] ?? null), 'invalid/duplicate profile');
            $profiles[$id] = ['id' => $id, 'status' => $profile['enabled'] ? 'allowed' : 'disabled'];
            if (!$profile['enabled']) {
                continue;
            }
            if (in_array($id, ['local-text', 'local-markdown'], true)) {
                self::require($profile['components'] === [] && ($profile['implementation'] ?? '') === 'original-core', 'local baseline depends on external component');
            } else {
                self::require($profile['components'] !== [], 'missing enabled profile qualification');
                foreach ($profile['components'] as $component) {
                    $closure += $visit($component, $id);
                }
                $selected += $closure;
                foreach ($closure as $record) {
                    if (in_array($id, $record['enabledProfiles'], true) && $record['verdict'] === 'restricted') {
                        $profiles[$id]['status'] = 'restricted';
                    }
                }
            }
        }
        // A pin cannot silently disappear from the development inventory after a lock update.
        if (isset($catalog['composerLock'])) {
            $this->evidence($catalog['composerLock']);
            $lock = $this->json($catalog['composerLock']['path']);
            self::require(($lock['packages'] ?? null) === [], 'unexpected runtime vendor dependency');
            foreach ($lock['packages-dev'] as $package) {
                if ($package['name'] === 'knowkit/core') {
                    continue;
                }
                $record = $selected[$package['name']] ?? [];
                $pin = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
                self::require(($record['version'] ?? null) === $package['version']
                    && ($record['sourceRevision'] ?? null) === $pin
                    && ($record['codeLicense'] ?? null) === implode(' OR ', $package['license']), 'locked component not qualified: ' . $package['name']);
                $deps = array_values(array_filter(array_keys($package['require'] ?? []),
                    static fn(int|string $name): bool => is_string($name) && str_contains($name, '/') && $name !== 'knowkit/core'));
                sort($deps);
                $actual = $record['transitiveComponents'];
                sort($actual);
                self::require($deps === $actual, 'locked dependency graph mismatch');
            }
        }
        ksort($selected);
        return ['schemaVersion' => 'license-audit:1', 'status' => 'passed', 'publicationAllowed' => false,
            'profiles' => array_values($profiles), 'components' => array_values($selected),
            'catalogDigest' => hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'limitations' => ['Local qualification only; no runtime parser/provider conformance or publication approval.']];
    }

    /** Local deterministic SBOM and complete retained notice texts; no network or install. */
    public function generate(array $report, string $directory): void
    {
        self::require($directory !== '' && !str_starts_with($directory, '/')
            && !str_contains($directory, '\\') && !in_array('..', explode('/', $directory), true), 'invalid output directory');
        $path = $this->root . '/' . $directory;
        self::require(is_dir($path), 'output directory must already exist');
        $root = realpath($this->root);
        self::require($root !== false, 'missing evidence root');
        $resolved = realpath($path);
        self::require($resolved !== false && str_starts_with($resolved, $root . '/'), 'escaping output directory');
        $notices = "KnowKit local third-party inventory. Publication is not authorized.\n";
        $seen = [];
        foreach ($report['components'] as $record) {
            $notices .= "\n" . $record['id'] . '@' . $record['sourceRevision'] . ' — ' . $record['codeLicense'] . "\n";
            foreach ($record['notices'] as $notice) {
                if (!isset($seen[$notice['sha256']])) {
                    $notices .= "\n--- " . $notice['path'] . " ---\n" . self::read($this->file($notice['path'])) . "\n";
                    $seen[$notice['sha256']] = true;
                }
            }
        }
        foreach (['sbom.json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'THIRD_PARTY_NOTICES.txt' => $notices] as $name => $content) {
            self::require(!is_link($resolved . '/' . $name), 'symlink output rejected');
            self::require(file_put_contents($resolved . '/' . $name, $content) === strlen($content), 'cannot write output');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = array_slice($argv, 1);
        $catalog = 'licenses/catalog.json';
        $output = null;
        while ($args !== []) {
            $flag = array_shift($args);
            if (!in_array($flag, ['--catalog', '--output'], true) || $args === []) {
                throw new RuntimeException('usage: php tools/license-audit.php [--catalog relative.json] [--output existing-relative-directory]');
            }
            $value = array_shift($args);
            if ($flag === '--catalog') {
                $catalog = $value;
            } else {
                $output = $value;
            }
        }
        $audit = new LicenseAudit(dirname(__DIR__));
        $report = $audit->audit($audit->json($catalog));
        if ($output !== null) {
            $audit->generate($report, $output);
        }
        echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'license audit failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
