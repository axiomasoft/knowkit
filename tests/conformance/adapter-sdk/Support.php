<?php
declare(strict_types=1);

namespace KnowKit\Tests\Conformance\AdapterSdk;

use KnowKit\Conformance\{AdapterHarness, SchemaLoader};
use KnowKit\Examples\CustomAdapter\FakeHost;
use KnowKit\Registry\HostProfile;

require_once dirname(__DIR__,3) . '/packages/core/conformance/autoload.php';
require_once dirname(__DIR__,3) . '/examples/custom-adapter/FakeHost.php';

final class Support
{
    private static ?AdapterHarness $harness = null;

    public static function root(): string { return dirname(__DIR__,3); }
    public static function harness(): AdapterHarness
    {
        // Schemas and the authored corpus are immutable for the process. A negative manifest uses its own harness.
        return self::$harness ??= new AdapterHarness(SchemaLoader::load(self::root() . '/schemas'));
    }
    public static function profile(FakeHost $host, string $name = 'project-a'): HostProfile
    { return HostProfile::fromJson((string) file_get_contents(self::root() . '/tests/fixtures/host-profiles/' . $name . '.json'),$host->registry()); }
    public static function document(string $name = 'project-a'): \KnowKit\Domain\Contract\JsonObject
    { return \KnowKit\Domain\Contract\StrictJson::decode((string) file_get_contents(self::root() . '/tests/fixtures/host-profiles/' . $name . '.json')); }
    public static function child(array $args): array
    {
        $process = proc_open([PHP_BINARY,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::root());
        if (!is_resource($process)) { throw new \RuntimeException('Child unavailable'); }
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return ['exit'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
    }
}
