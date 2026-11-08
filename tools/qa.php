<?php
declare(strict_types=1);

namespace KnowKit\Tooling;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

/**
 * KnowKit QA bootstrap. Adapted mandatory inventory / fresh-process DAG patterns:
 * AgenTools 2054acda51eb69eca1579c78900858c5ff3de01e and DeepTutor
 * f7aed0744c36c5afef16a4261c31ebee1208cac2. Changed for PHP and isolated installs.
 * Copyright 2025 Data Intelligence Lab, The University of Hong Kong (patterns).
 * License and notices: docs/architecture/third-party/DeepTutor-*;
 * qualification: docs/architecture/P1.2-reuse.json. No donor runtime is imported.
 */
final class Qa
{
    public function __construct(public readonly string $root) {}

    public static function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function file(string $relative): string
    {
        $path = realpath($this->root . '/' . $relative);
        self::ensure($path !== false && is_file($path)
            && str_starts_with($path, $this->root . DIRECTORY_SEPARATOR), 'missing/escaping file: ' . $relative);
        return $path;
    }

    public function json(string $relative): array
    {
        $data = json_decode(file_get_contents($this->file($relative)), true, 512, JSON_THROW_ON_ERROR);
        self::ensure(is_array($data), 'expected JSON object: ' . $relative);
        return $data;
    }

    public static function temporary(): string
    {
        $path = sys_get_temp_dir() . '/knowkit-qa-' . bin2hex(random_bytes(12));
        self::ensure(mkdir($path, 0700), 'cannot create private QA directory');
        return $path;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $child) {
                self::remove($child->getPathname());
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** Execute argv directly, with bounded runtime and file-backed output (no pipe deadlock). */
    public function process(array $argv, array $env = [], int $timeout = 300): array
    {
        self::ensure($argv !== [] && count(array_filter($argv, 'is_string')) === count($argv), 'invalid command argv');
        $temporary = self::temporary();
        try {
            $process = proc_open($argv, [0 => ['pipe', 'r'],
                1 => ['file', $temporary . '/stdout', 'w'], 2 => ['file', $temporary . '/stderr', 'w']],
                $pipes, $this->root, array_merge(getenv(), $env), ['bypass_shell' => true]);
            self::ensure(is_resource($process), 'cannot start child command');
            fclose($pipes[0]);
            $start = microtime(true);
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) - $start > $timeout) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    throw new RuntimeException('child command timed out');
                }
                usleep(10000);
            } while (true);
            $exit = $status['exitcode'];
            proc_close($process);
            return ['exit' => $exit, 'stdout' => file_get_contents($temporary . '/stdout'),
                'stderr' => file_get_contents($temporary . '/stderr')];
        } finally {
            self::remove($temporary);
        }
    }

    public function registry(): array
    {
        $registry = $this->json('tools/qa-groups.json');
        self::ensure(($registry['schemaVersion'] ?? '') === 'qa-registry:1', 'unknown QA registry schema');
        $groups = $registry['groups'] ?? [];
        self::ensure($groups !== [], 'empty QA registry');
        $ids = array_column($groups, 'id');
        self::ensure(count($ids) === count($groups) && count($ids) === count(array_unique($ids)), 'duplicate/missing QA group');
        foreach ($groups as $group) {
            self::ensure(isset($group['composerProject'], $group['minPhp'], $group['requiredCaseManifest'])
                && ($group['commands'] ?? []) !== [], 'incomplete QA group');
        }
        return $registry;
    }

    public static function validateCases(array $cases, array $manifest): array
    {
        self::ensure($cases !== [], 'zero executed tests');
        $keys = array_column($cases, 'id');
        self::ensure(count($keys) === count(array_unique($keys)), 'duplicate executed case');
        foreach ($cases as $case) {
            self::ensure(($case['status'] ?? '') === 'passed', 'failed/skipped executed case: ' . $case['id']);
        }
        $required = $manifest['requiredCaseIds'] ?? [];
        self::ensure($required !== [] && count($required) === count(array_unique($required)), 'empty/duplicate required cases');
        $executed = [];
        foreach ($required as $id) {
            $mapped = $manifest['testCaseMap'][$id] ?? [$id];
            self::ensure(is_array($mapped) && $mapped !== [], 'empty case mapping: ' . $id);
            self::ensure(array_diff($mapped, $keys) === [], 'missing required case: ' . $id);
            $executed[] = ['id' => $id, 'status' => 'passed'];
        }
        return $executed;
    }

    public static function junit(string $path): array
    {
        self::ensure(is_file($path), 'missing child JUnit report');
        $xml = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::ensure($xml->load($path, LIBXML_NONET) && $xml->doctype === null, 'invalid child JUnit');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($xml);
        self::ensure($xpath->query('//failure|//error|//skipped')->length === 0, 'failed/skipped JUnit tests');
        foreach ($xml->getElementsByTagName('testsuite') as $suite) {
            if ($suite->hasAttribute('tests')) {
                self::ensure(ctype_digit($suite->getAttribute('tests'))
                    && (int) $suite->getAttribute('tests') === $suite->getElementsByTagName('testcase')->length, 'JUnit count mismatch');
            }
            foreach (['failures', 'errors', 'skipped'] as $attribute) {
                self::ensure(!$suite->hasAttribute($attribute) || $suite->getAttribute($attribute) === '0', 'failed/skipped JUnit summary');
            }
        }
        $cases = [];
        foreach ($xpath->query('//testcase') as $case) {
            if (!$case instanceof \DOMElement) {
                throw new RuntimeException('invalid JUnit testcase node');
            }
            // PHPUnit writes the PHP identity in class and a dotted display name in
            // classname. Pytest uses classname directly. Preserve each native ID.
            $class = $case->hasAttribute('class') ? $case->getAttribute('class') : $case->getAttribute('classname');
            $cases[] = ['id' => $class . '::' . $case->getAttribute('name'), 'status' => 'passed'];
        }
        self::ensure($cases !== [], 'zero executed tests');
        return $cases;
    }

    public function suite(string $name): void
    {
        $xml = new DOMDocument();
        self::ensure($xml->load($this->file('phpunit.xml'), LIBXML_NONET), 'invalid PHPUnit configuration');
        $found = false;
        foreach ($xml->getElementsByTagName('testsuite') as $suite) {
            if ($suite->getAttribute('name') !== $name) {
                continue;
            }
            $found = true;
            $count = 0;
            foreach ($suite->getElementsByTagName('directory') as $directory) {
                $path = realpath($this->root . '/' . trim($directory->textContent));
                self::ensure($path !== false && is_dir($path) && str_starts_with($path, $this->root . '/'), 'missing suite directory: ' . $name);
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                        $count++;
                    }
                }
            }
            foreach ($suite->getElementsByTagName('file') as $file) {
                $this->file(trim($file->textContent));
                $count++;
            }
            self::ensure($count > 0, 'empty suite: ' . $name);
        }
        self::ensure($found, 'unknown suite: ' . $name);
    }

    public function group(array $group): array
    {
        self::ensure(version_compare(PHP_VERSION, $group['minPhp'], '>='), 'lane_unavailable: PHP ' . $group['minPhp']);
        $project = $group['composerProject'];
        $this->file($project . '/composer.json');
        $this->file($project . '/vendor/autoload.php');
        if (str_starts_with($group['id'], 'worker:')) {
            foreach (['pyproject.toml', 'uv.lock', '.venv/bin/python', '.venv/bin/pytest'] as $file) {
                $this->file('python/knowkit-worker/' . $file);
            }
        }
        $manifest = $this->json($group['requiredCaseManifest']);
        self::ensure(($manifest['schemaVersion'] ?? '') === 'check-group:1'
            && ($manifest['group'] ?? '') === $group['id'], 'invalid required-case manifest');
        if ($group['id'] === 'tooling:baseline' || isset($manifest['fixtures'])) {
            $this->fixtures($manifest);
        }
        $temporary = self::temporary();
        try {
            $cases = [];
            foreach ($group['commands'] as $index => $command) {
                $junit = $temporary . '/' . $index . '-phpunit.xml';
                $pytest = $temporary . '/' . $index . '-pytest.xml';
                $argv = array_map(static fn(string $arg): string => str_replace(
                    ['<runner-private-junit.xml>', '<runner-private-pytest-junit.xml>', '<composer-project>'],
                    [$junit, $pytest, $project], $arg), $command);
                $suiteIndex = array_search('--testsuite', $argv, true);
                if ($suiteIndex !== false) {
                    self::ensure(isset($argv[$suiteIndex + 1]), 'missing testsuite argument');
                    $this->suite($argv[$suiteIndex + 1]);
                    $this->file($argv[0]);
                }
                $child = $this->process($argv, ['KNOWKIT_QA_COMPOSER_PROJECT' => $project]);
                self::ensure($child['exit'] === 0, 'child failed: ' . $group['id'] . ' exit ' . $child['exit']);
                if (in_array('<runner-private-junit.xml>', $command, true)) {
                    $cases = array_merge($cases, self::junit($junit));
                } elseif (str_contains(implode(' ', $command), '<runner-private-pytest-junit.xml>')) {
                    $cases = array_merge($cases, self::junit($pytest));
                } else {
                    $report = json_decode($child['stdout'], true, 512, JSON_THROW_ON_ERROR);
                    self::ensure(is_array($report) && ($report['count'] ?? 0) > 0
                        && $report['count'] === count($report['cases'] ?? [])
                        && ($report['failures'] ?? -1) === 0 && ($report['skips'] ?? -1) === 0, 'invalid child case report');
                    $cases = array_merge($cases, $report['cases']);
                }
            }
            $executed = self::validateCases($cases, $manifest);
            return ['id' => $group['id'], 'status' => 'PASS', 'cases' => $executed, 'count' => count($cases)];
        } finally {
            self::remove($temporary);
        }
    }

    /** Validate actual manifests and PHP name tokens against the approved package/layer DAG. */
    public function dag(): array
    {
        $architecture = $this->json('specs/architecture/manifest.v1.json');
        $root = $this->json('composer.json');
        self::ensure(empty($root['require']), 'root must be dev-only');
        self::ensure(empty(array_diff(array_keys($root['require-dev'] ?? []),
            ['knowkit/core', 'phpunit/phpunit', 'phpstan/phpstan', 'symfony/yaml'])), 'optional host dependency in root tooling');
        self::ensure(empty(array_diff(array_keys($root['scripts'] ?? []), ['qa']))
            && empty($root['autoload']['files']), 'root install side effects');
        self::ensure(($root['config']['platform']['php'] ?? '') === '8.3.0', 'root PHP floor changed');
        $namespaces = [];
        $installed = 0;
        foreach ($architecture['packages'] as $id => $definition) {
            $relative = $definition['path'] . '/composer.json';
            if (!file_exists($this->root . '/' . $relative)) {
                self::ensure($id !== 'core', 'missing core package');
                continue;
            }
            $installed++;
            $manifest = $this->json($relative);
            self::ensure(($manifest['name'] ?? '') === $definition['name'], 'package name differs: ' . $id);
            self::ensure(($manifest['type'] ?? '') === 'library', 'package must not be an installer/plugin: ' . $id);
            self::ensure(($manifest['require']['php'] ?? '') === '>=' . $definition['minPhp'], 'package PHP floor differs: ' . $id);
            self::ensure(($manifest['autoload']['psr-4'][$definition['namespace']] ?? '') === 'src/', 'namespace differs: ' . $id);
            self::ensure(empty($manifest['scripts']) && empty($manifest['autoload']['files'])
                && empty($manifest['extra']['laravel']), 'install/autoload side effects: ' . $id);
            foreach ($manifest['require'] as $dependency => $version) {
                if ($dependency === 'php' || str_starts_with($dependency, 'ext-')) {
                    continue;
                }
                self::ensure($id !== 'core', 'core vendor runtime dependency');
                if (str_starts_with($dependency, 'knowkit/')) {
                    $target = substr($dependency, 8);
                    self::ensure(in_array($target, $definition['dependsOn'], true), 'forbidden package dependency');
                }
            }
            $namespaces[$id] = $definition['namespace'];
        }
        $core = $this->json('packages/core/composer.json');
        $expected = ['php' => '>=8.3', 'ext-pdo' => '*', 'ext-json' => '*', 'ext-mbstring' => '*'];
        self::ensure($core['require'] == $expected, 'core runtime graph differs');
        self::ensure(isset($core['suggest']['ext-pdo_pgsql']), 'missing PostgreSQL extension declaration');
        foreach (glob($this->root . '/packages/*/composer.json') ?: [] as $path) {
            self::ensure(in_array(substr($path, strlen($this->root) + 1, -14), array_column($architecture['packages'], 'path'), true), 'unapproved Composer package');
        }
        $files = 0;
        foreach ($architecture['packages'] as $id => $definition) {
            $source = $this->root . '/' . $definition['path'] . '/src';
            if (!is_dir($source)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $files++;
                $relative = substr($file->getPathname(), strlen($source) + 1);
                $layer = explode('/', $relative)[0];
                $declared = false;
                $tokens = token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
                $aliases = self::namespaceAliases($tokens);
                foreach ($tokens as $tokenIndex => $token) {
                    if (!is_array($token)) {
                        continue;
                    }
                    if ($token[0] === T_NAMESPACE) {
                        $declared = true;
                    }
                    if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                        continue;
                    }
                    if ($token[0] === T_STRING && self::isMemberName($tokens, $tokenIndex)) {
                        continue;
                    }
                    $name = ltrim($token[1], '\\');
                    if ($token[0] === T_NAME_QUALIFIED) {
                        $prefix = strstr($name, '\\', true);
                        $resolved = $aliases[strtolower($prefix)] ?? null;
                        if ($resolved !== null) { $name = $resolved . substr($name, strlen($prefix)); }
                    }
                    if ($id === 'core') {
                        self::ensure(!preg_match('/^(Illuminate|Laravel|Chatom|AgenTools|AgenToolsPlatform|Python|Psr\\\\Http)(\\\\|$)/i', $name), 'forbidden core import: ' . $name);
                        self::ensure(!str_contains($name, '\\') || str_starts_with($name, 'KnowKit\\')
                            || str_starts_with($name, 'Random\\'), 'forbidden vendor namespace in core: ' . $name);
                        if (str_starts_with($name, 'KnowKit\\')) {
                            $target = explode('\\', $name)[1];
                            self::ensure(isset($architecture['coreLayers'][$layer]), 'unknown core layer');
                            self::ensure(isset($architecture['coreLayers'][$target]), 'forbidden optional/unknown core namespace: ' . $target);
                            self::ensure($target === $layer || in_array($target, $architecture['coreLayers'][$layer], true), 'forbidden core layer edge');
                        }
                    } else {
                        foreach ($architecture['packages'] as $targetId => $target) {
                            if ($targetId !== 'core' && $targetId !== $id && str_starts_with($name, $target['namespace'])) {
                                self::ensure(in_array($targetId, $definition['dependsOn'], true), 'forbidden optional package import');
                            }
                        }
                    }
                }
                self::ensure($declared, 'missing PHP namespace');
            }
        }
        return ['packages' => $installed, 'phpFiles' => $files];
    }

    /** Member names such as $runtime->python are not imports or class references. */
    private static function isMemberName(array $tokens, int $index): bool
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            $token = $tokens[$previous];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        }
        return false;
    }

    /** Resolve explicit namespace aliases from PHP tokens, never comments or string literals.
     * Unsupported import forms retain the existing fail-closed qualified-name checks.
     * @return array<string,string> */
    private static function namespaceAliases(array $tokens): array
    {
        $aliases = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_USE) { continue; }
            $parts = [];
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $part = $tokens[$j];
                if (is_array($part) && in_array($part[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) { continue; }
                if ($part === ';') { break; }
                $parts[] = $part;
                if (count($parts) > 3 || !is_array($part)) { break; }
            }
            if (count($parts) === 3 && is_array($parts[0]) && is_array($parts[1]) && is_array($parts[2])
                && in_array($parts[0][0], [T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_STRING], true)
                && $parts[1][0] === T_AS && $parts[2][0] === T_STRING) {
                $aliases[strtolower($parts[2][1])] = ltrim($parts[0][1], '\\');
            }
        }
        return $aliases;
    }

    public function fixtures(array $manifest): void
    {
        self::ensure(($manifest['schemaVersion'] ?? '') === 'check-group:1', 'unknown fixture manifest schema');
        self::ensure(is_string($manifest['fixtureVersion'] ?? null) && $manifest['fixtureVersion'] !== ''
            && is_string($manifest['versionRationale'] ?? null) && trim($manifest['versionRationale']) !== '', 'missing fixture version/rationale');
        self::ensure(!empty($manifest['rights']['license']) && !empty($manifest['rights']['origin']), 'missing fixture license/provenance');
        self::ensure(!empty($manifest['fixtures']), 'missing fixture entries');
        foreach ($manifest['fixtures'] as $entry) {
            self::ensure(!empty($entry['expected']), 'missing fixture expected outcome');
            self::ensure(hash_file('sha256', $this->file($entry['path'])) === $entry['sha256'], 'fixture digest mismatch');
        }
        if (isset($manifest['components']['composerLock'])) {
            $lock = $manifest['components']['composerLock'];
            self::ensure(hash_file('sha256', $this->file($lock['path'])) === $lock['sha256'], 'fixture component lock digest mismatch');
        }
    }

    public function config(): array
    {
        require_once $this->file('vendor/autoload.php');
        $compose = \Symfony\Component\Yaml\Yaml::parseFile($this->file('compose.yaml'));
        $ci = \Symfony\Component\Yaml\Yaml::parseFile($this->file('.github/workflows/ci.yml'));
        self::ensure(isset($compose['services']['postgres'], $compose['services']['pgvector'], $ci['jobs']['bootstrap']), 'missing infrastructure config');
        foreach ($compose['services'] as $service) {
            self::ensure(isset($service['tmpfs'], $service['healthcheck']) && empty($service['volumes']), 'test database must be disposable');
            foreach ($service['ports'] as $port) {
                self::ensure(str_starts_with($port, '127.0.0.1:'), 'test database must bind loopback');
            }
        }
        foreach (['composer.json', 'packages/core/composer.json', 'compose.yaml', '.env.example', '.github/workflows/ci.yml'] as $file) {
            $content = file_get_contents($this->file($file));
            self::ensure(!preg_match('~(?:/home/|/Users/|[A-Z]:\\\\Users\\\\|sk-[a-zA-Z0-9]{20,}|ghp_[a-zA-Z0-9]{20,})~', $content), 'nonportable config or credential');
        }
        $this->registry();
        return ['files' => 6];
    }

    public function select(array $args, array $registry): array
    {
        $ids = array_column($registry['groups'], 'id');
        if (count($args) === 1 && $args[0] === 'all') {
            return $ids;
        }
        if (($args[0] ?? '') === '--profile') {
            $profile = $args[1] ?? '';
            if ($profile === 'local-developed') {
                self::ensure(count($args) === 4 && $args[2] === '--manifest', 'local-developed requires --manifest <file>');
                $manifest = $this->json($args[3]);
                self::ensure(($manifest['schemaVersion'] ?? '') === 'qa-profile:1', 'unknown profile manifest schema');
                $selected = $manifest['selected'] ?? [];
                $excluded = $manifest['notSelected'] ?? [];
            } else {
                self::ensure(count($args) === 2 && isset($registry['profiles'][$profile]), 'unknown QA profile');
                $selected = $registry['profiles'][$profile]['selected'];
                $excluded = $registry['profiles'][$profile]['notSelected'];
            }
            $all = array_merge($selected, $excluded);
            self::ensure($selected !== [] && count($all) === count(array_unique($all))
                && array_diff($ids, $all) === [] && array_diff($all, $ids) === [], 'profile must explicitly partition full registry');
            return $selected;
        }
        self::ensure(count($args) === 1 && in_array($args[0], $ids, true), 'unknown QA group');
        return $args;
    }

    public function run(array $args): array
    {
        $registry = $this->registry();
        $selected = $this->select($args, $registry);
        $results = [];
        foreach ($registry['groups'] as $group) {
            if (!in_array($group['id'], $selected, true)) {
                $results[] = ['id' => $group['id'], 'status' => 'NOT_SELECTED', 'qualification' => 'PENDING'];
                continue;
            }
            try {
                $results[] = $this->group($group);
            } catch (Throwable $error) {
                $results[] = ['id' => $group['id'], 'status' => 'FAIL', 'error' => $error->getMessage()];
            }
        }
        $checks = [];
        if ($args[0] === 'all' || $args[0] === '--profile') {
            foreach ($registry['checks'] as $id => $argv) {
                $result = $this->process($argv);
                $checks[] = ['id' => $id, 'exit' => $result['exit']];
            }
        }
        $failed = array_filter($results, static fn(array $result): bool => $result['status'] === 'FAIL');
        $badChecks = array_filter($checks, static fn(array $check): bool => $check['exit'] !== 0);
        return ['schemaVersion' => 'qa-report:1', 'outcome' => $failed || $badChecks ? 'failure' : 'success',
            'groups' => $results, 'checks' => $checks];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $qa = new Qa(dirname(__DIR__));
        $args = array_slice($argv, 1);
        if (($args[0] ?? '') === '--check') {
            Qa::ensure(count($args) === 2 && in_array($args[1], ['dag', 'config'], true), 'unknown static check');
            $report = ['outcome' => 'success', 'check' => $args[1], 'details' => $qa->{$args[1]}()];
        } else {
            $report = $qa->run($args);
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit($report['outcome'] === 'success' ? 0 : 1);
    } catch (Throwable $error) {
        echo json_encode(['outcome' => 'failure', 'error' => $error->getMessage()], JSON_THROW_ON_ERROR), "\n";
        exit(1);
    }
}
