<?php
declare(strict_types=1);

// Explicit host test setup. No vendor runtime or PHPUnit is loaded.
require_once dirname(__DIR__) . '/packages/core/conformance/autoload.php';

use KnowKit\Conformance\{AdapterHarness, AdapterHost, SchemaLoader};
use KnowKit\Registry\HostProfile;

try {
    $options = [];
    for ($i = 1; $i < $argc; $i += 2) {
        $name = $argv[$i];
        if (!in_array($name,['--profile','--contract','--factory','--report','--manifest'],true) || !isset($argv[$i + 1]) || isset($options[$name])) {
            throw new InvalidArgumentException('Invalid arguments');
        }
        $options[$name] = $argv[$i + 1];
    }
    if (!isset($options['--profile'])) { throw new InvalidArgumentException('Profile required'); }
    $harness = new AdapterHarness(SchemaLoader::load(dirname(__DIR__) . '/schemas'),$options['--manifest'] ?? null);
    // PHP factory is trusted executable TEST configuration, supplied by the invoking developer.
    // It never comes from model/tool input or from the profile's semantic settings.
    $factoryPath = $options['--factory'] ?? dirname($options['--profile']) . '/factory.php';
    if (!is_file($factoryPath) || !is_file($options['--profile'])) { throw new InvalidArgumentException('Missing host setup'); }
    $factory = require $factoryPath;
    if (!$factory instanceof Closure) { throw new InvalidArgumentException('Explicit host factory required'); }
    $host = $factory($harness->fixture);
    if (!$host instanceof AdapterHost) { throw new InvalidArgumentException('Host contract required'); }
    $profileJson = file_get_contents($options['--profile']);
    if ($profileJson === false) { throw new RuntimeException('Profile read failed'); }
    $profile = HostProfile::fromJson($profileJson,$host->registry());
    $report = $harness->run($host,$profile,$options['--contract'] ?? 'all');
    $json = json_encode($report,JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (isset($options['--report']) && file_put_contents($options['--report'],$json) !== strlen($json)) {
        throw new RuntimeException('Report write failed');
    }
    echo $json;
    exit($report['ok'] ? 0 : 1);
} catch (\KnowKit\Domain\DomainFailure $failure) {
    echo json_encode(['schemaVersion'=>'adapter-report:1','ok'=>false,'outcome'=>$failure->outcome->value,'code'=>$failure->errorCode],JSON_THROW_ON_ERROR) . "\n";
    exit(1);
} catch (Throwable) {
    // Never reflect exception messages, source text, credentials, provider inputs or machine paths.
    echo "{\"schemaVersion\":\"adapter-report:1\",\"ok\":false,\"code\":\"conformance_setup_failed\"}\n";
    exit(1);
}
