<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Load\CgroupLimits;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class EnvironmentTest extends TestCase
{
    public function testAbsentLeafControllerUsesStrictestVisibleParentLimits(): void
    {
        $files = [
            '/cg/team/cpu.max' => "300000 100000\n",
            '/cg/cpu.max' => "100000 50000\n",
            '/cg/team/job/memory.max' => "max\n",
            '/cg/team/memory.max' => "2147483648\n",
            '/cg/memory.max' => "8589934592\n",
        ];
        $result = CgroupLimits::observe("0::/team/job\n", $this->mount('/', '/cg'), static fn (string $path): ?string => $files[$path] ?? null);
        self::assertSame('100000 50000', $result['processCpuMax']);
        self::assertSame('2147483648', $result['processMemoryMax']);
        self::assertSame(['cpu' => 2, 'memory' => 3], $result['cgroupLimitObservation']['observedFiles']);
        self::assertStringContainsString('hidden ancestors', $result['cgroupLimitObservation']['scope']);
    }

    public function testSubtreeMountResolvesMountRootAndNeverReadsOutsideItsView(): void
    {
        $files = ['/visible group/job/cpu.max' => 'max 100000', '/visible group/cpu.max' => '50000 100000',
            '/visible group/memory.max' => '4294967296', '/cpu.max' => '1 100000'];
        $reads = [];
        $result = CgroupLimits::observe("0::/tenant/job\n", $this->mount('/tenant', '/visible\\040group'),
            static function (string $path) use ($files, &$reads): ?string {
                $reads[] = $path;
                return $files[$path] ?? null;
            });
        self::assertSame('50000 100000', $result['processCpuMax']);
        self::assertSame('4294967296', $result['processMemoryMax']);
        self::assertSame(['/visible group/job/cpu.max', '/visible group/job/memory.max',
            '/visible group/cpu.max', '/visible group/memory.max'], $reads);
    }

    public function testNamespaceRootAndWiderMountKeepVisibleAncestorRestrictions(): void
    {
        $files = ['/cg/cpu.max' => '150000 100000', '/cg/memory.max' => 'max'];
        $read = static fn (string $path): ?string => $files[$path] ?? null;
        $root = CgroupLimits::observe("0::/\n", $this->mount('/', '/cg'), $read);
        self::assertSame('150000 100000', $root['processCpuMax']);
        $mounts = $this->mount('/team', '/subtree') . "\n" . $this->mount('/', '/cg');
        $child = CgroupLimits::observe("0::/team/job\n", $mounts, $read);
        self::assertSame('150000 100000', $child['processCpuMax']);
        self::assertSame('max', $child['processMemoryMax']);
    }

    public function testUnavailableAndMalformedObservationsRemainUnknown(): void
    {
        foreach ([null, "5:cpu:/legacy\n", "0::/../hidden\n"] as $membership) {
            $result = CgroupLimits::observe($membership, $this->mount('/', '/cg'),
                static function (string $path): ?string { self::fail('Unresolved cgroup path read'); });
            self::assertNull($result['processCpuMax']);
            self::assertNull($result['processMemoryMax']);
            self::assertSame('unavailable', $result['cgroupLimitObservation']['status']);
        }
        $absent = CgroupLimits::observe("0::/job\n", $this->mount('/', '/cg'), static fn (string $path): ?string => null);
        self::assertNull($absent['processCpuMax']);
        self::assertNull($absent['processMemoryMax']);
        $files = ['/cg/job/cpu.max' => 'invalid', '/cg/cpu.max' => 'max 100000',
            '/cg/job/memory.max' => '-1', '/cg/memory.max' => 'max'];
        $invalid = CgroupLimits::observe("0::/job\n", $this->mount('/', '/cg'), static fn (string $path): ?string => $files[$path] ?? null);
        self::assertNull($invalid['processCpuMax']);
        self::assertNull($invalid['processMemoryMax']);
        self::assertSame('invalid', $invalid['cgroupLimitObservation']['cpu']);
        self::assertSame('invalid', $invalid['cgroupLimitObservation']['memory']);
    }

    private function mount(string $root, string $point): string
    {
        return '37 28 0:31 ' . $root . ' ' . $point . ' rw - cgroup2 cgroup2 rw';
    }
}
