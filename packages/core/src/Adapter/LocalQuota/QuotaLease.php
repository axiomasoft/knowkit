<?php

declare(strict_types=1);

namespace KnowKit\Adapter\LocalQuota;

/** OS lock lifetime, never a time-expiring permit that a still-running job could outlive. */
final class QuotaLease
{
    /** @param resource $handle */
    public function __construct(private mixed $handle) {}

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
