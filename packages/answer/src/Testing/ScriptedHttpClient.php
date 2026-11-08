<?php

declare(strict_types=1);

namespace KnowKit\Answer\Testing;

use KnowKit\Answer\Profile\EffectiveProfile;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Offline PSR-18 fixture only. Never records credentials, URLs or outbound plaintext. */
final class ScriptedHttpClient implements ClientInterface
{
    private int $sends = 0;
    private int $bytes = 0;

    /** @param list<ResponseInterface|\Throwable|\Closure> $script */
    public function __construct(
        EffectiveProfile $profile,
        private array $script,
    ) {
        $profile->requireTest();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sends++;
        $this->bytes += $request->getBody()->getSize() ?? 0;
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if (!$next instanceof ResponseInterface) {
            throw new \UnderflowException('No scripted HTTP response');
        }

        return $next;
    }

    public function sendCount(): int
    {
        return $this->sends;
    }

    public function outboundBytes(): int
    {
        return $this->bytes;
    }

    public function __debugInfo(): array
    {
        return ['sendCount' => $this->sends, 'outboundBytes' => $this->bytes];
    }

    public function __serialize(): array
    {
        throw new \LogicException('HTTP fixture scripts cannot be serialized');
    }
}
