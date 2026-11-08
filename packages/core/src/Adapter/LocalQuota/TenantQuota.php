<?php

declare(strict_types=1);

namespace KnowKit\Adapter\LocalQuota;

use KnowKit\Adapter\LocalBlob\PrivateFilesystem;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\TenantId;

/** Single-host reference quota, shared across processes. Multi-host delivery needs a shared host gate. */
final readonly class TenantQuota
{
    public function __construct(private ?string $root = null) {}

    public function acquire(TenantId $tenant, string $lane, int $concurrent, int $perMinute): QuotaLease
    {
        if (!in_array($lane, ['search', 'ingest', 'worker'], true) || $concurrent < 1 || $concurrent > 1024
            || $perMinute < 1 || $perMinute > 1000000) {
            throw new \InvalidArgumentException('Finite named quota required');
        }
        $fs = $this->filesystem();
        $key = hash('sha256', $tenant->value) . '-' . $lane;
        $guard = $this->open(filesystem: $fs, key: $key . '-guard');
        if (!flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            throw new DomainFailure(Outcome::Unavailable, 'quota_exceeded');
        }
        try {
            return $this->admit(filesystem: $fs, key: $key, concurrent: $concurrent, perMinute: $perMinute);
        } finally {
            fclose($guard);
        }
    }

    private function admit(PrivateFilesystem $filesystem, string $key, int $concurrent, int $perMinute): QuotaLease
    {
        $slot = $this->slot(filesystem: $filesystem, key: $key, concurrent: $concurrent);
        try {
            $this->rate(filesystem: $filesystem, key: $key, perMinute: $perMinute);
        } catch (\Throwable $failure) {
            $slot->release();
            throw $failure;
        }

        return $slot;
    }

    private function slot(PrivateFilesystem $filesystem, string $key, int $concurrent): QuotaLease
    {
        for ($index = 0; $index < $concurrent; $index++) {
            $handle = $this->open(filesystem: $filesystem, key: $key . '-slot-' . $index);
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new QuotaLease($handle);
            }
            fclose($handle);
        }

        throw new DomainFailure(Outcome::Unavailable, 'quota_exceeded');
    }

    private function rate(PrivateFilesystem $filesystem, string $key, int $perMinute): void
    {
        $handle = $this->open(filesystem: $filesystem, key: $key . '-rate');
        try {
            $wire = stream_get_contents($handle, 4097);
            $state = $wire === '' ? ['until' => time() + 60, 'count' => 0] : json_decode($wire, true);
            if (!is_array($state) || !is_int($state['until'] ?? null) || !is_int($state['count'] ?? null)
                || $state['count'] < 0 || strlen($wire) > 4096) {
                throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
            }
            if (time() >= $state['until']) {
                $state = ['until' => time() + 60, 'count' => 0];
            }
            if ($state['count'] >= $perMinute) {
                throw new DomainFailure(Outcome::Unavailable, 'quota_exceeded');
            }
            $state['count']++;
            $bytes = json_encode($state, JSON_THROW_ON_ERROR);
            if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
            }
        } finally {
            fclose($handle);
        }
    }

    private function filesystem(): PrivateFilesystem
    {
        if (!function_exists('posix_geteuid')) {
            throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
        }
        $root = $this->root ?? sys_get_temp_dir() . '/knowkit-quotas-' . posix_geteuid();
        if (!is_dir($root)) {
            $mask = umask(0077);
            try {
                if (!@mkdir($root, 0700) && !is_dir($root)) {
                    throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
                }
            } finally {
                umask($mask);
            }
        }
        if (fileowner($root) !== posix_geteuid()) {
            throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
        }

        return new PrivateFilesystem($root);
    }

    /** @return resource */
    private function open(PrivateFilesystem $filesystem, string $key): mixed
    {
        $path = $filesystem->path($key);
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            try {
                return $filesystem->open(relative: $key, create: true);
            } catch (DomainFailure $failure) {
                if ($failure->errorCode !== 'staging_exists') {
                    throw $failure;
                }
            }
        }
        $verified = $filesystem->open($key);
        $handle = @fopen($path, 'r+b');
        $expected = fstat($verified);
        $actual = $handle === false ? false : fstat($handle);
        fclose($verified);
        if ($actual === false || $actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new DomainFailure(Outcome::Unavailable, 'quota_store_unavailable');
        }

        return $handle;
    }
}
