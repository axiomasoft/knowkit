<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Adapter\Origins\HttpResponseReader;
use KnowKit\Adapter\Origins\NativeHttps;
use KnowKit\Adapter\Origins\NativeResolver;
use KnowKit\Application\Origins\FetchRemote;
use KnowKit\Application\Origins\OriginPolicy;
use KnowKit\Domain\Origins\FetchLimits;
use KnowKit\Domain\Origins\RemoteResponse;
use KnowKit\Domain\Origins\ResolvedOrigin;
use KnowKit\Domain\Outcome;
use KnowKit\Port\OriginResolver;
use KnowKit\Port\OriginTransport;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';

final class Resolver implements OriginResolver
{
    public array $answers = [['93.184.216.34']];
    public array $seen = [];

    public function addresses(string $host, ?int $deadline = null): array
    {
        $this->seen[] = $host;

        return $this->answers[min(count($this->seen) - 1, count($this->answers) - 1)];
    }
}

/** Qualifies orchestration only; native transport has separate framing and pre-send tests below. */
final class Transport implements OriginTransport
{
    public array $responses = [];
    public array $sent = [];
    public ?string $peer = null;

    public function get(ResolvedOrigin $origin, FetchLimits $limits, int $deadline): RemoteResponse
    {
        $origin->assertPeer($this->peer ?? $origin->address);
        $this->sent[] = $origin;

        return array_shift($this->responses) ?? new RemoteResponse(status: 200, headers: ['content-type' => 'text/plain'], bytes: 'safe');
    }
}

#[Group('security:hardening')]
final class OriginTest extends HardeningCase
{
    public function testAllForbiddenAddressesAndMixedAnswersStopBeforeConnect(): void
    {
        $dns = new Resolver();
        $transport = new Transport();
        $fetch = $this->fetch(dns: $dns, transport: $transport);
        foreach ($this->cases()['forbiddenAddresses'] as $address) {
            foreach ([[$address], ['93.184.216.34', $address]] as $answers) {
                $dns->answers = [$answers];
                $this->failure(fn () => $fetch->fetch('https://docs.example.org/file'), 'unsafe_origin');
            }
        }
        self::assertSame([], $transport->sent);
        foreach ([[], array_fill(0, 65, '93.184.216.34')] as $answers) {
            $dns->answers = [$answers];
            $this->failure(fn () => $fetch->fetch('https://docs.example.org/'),
                code: 'origin_dns_unavailable', outcome: Outcome::Unavailable);
        }
        self::assertSame([], $transport->sent);
        $dns->answers = [['2606:4700:4700::1111']];
        self::assertSame('safe', $fetch->fetch('https://docs.example.org/file')->bytes());
    }

    public function testProtocolsPortsUserinfoFragmentsAndAllowlistRejectBeforeDns(): void
    {
        $dns = new Resolver();
        $transport = new Transport();
        $fetch = $this->fetch(dns: $dns, transport: $transport);
        foreach ($this->cases()['forbiddenUrls'] as $url) {
            $this->failure(fn () => $fetch->fetch($url), 'unsafe_origin');
        }
        $this->failure(fn () => (new FetchRemote(new OriginPolicy(), $dns, $transport))->fetch('https://docs.example.org/'), 'unsafe_origin');
        self::assertSame([], $dns->seen);
        self::assertSame([], $transport->sent);
    }

    public function testRebindingAndChangedActualPeerNeverSendRequest(): void
    {
        $dns = new Resolver();
        $transport = new Transport();
        $transport->responses = [$this->redirect('/next')];
        $dns->answers = [['93.184.216.34'], ['127.0.0.1']];
        $this->failure(fn () => $this->fetch(dns: $dns, transport: $transport)->fetch('https://docs.example.org/'), 'unsafe_origin');
        self::assertCount(1, $transport->sent);
        self::assertCount(2, $dns->seen);
        foreach (['10.0.0.1' => 'unsafe_origin', '1.1.1.1' => 'origin_peer_mismatch'] as $peer => $code) {
            $transport = new Transport();
            $transport->peer = $peer;
            $this->failure(fn () => $this->fetch(dns: new Resolver(), transport: $transport)->fetch('https://docs.example.org/'), $code);
            self::assertSame([], $transport->sent);
        }
    }

    public function testForbiddenRedirectsAndFourthHopAreRefused(): void
    {
        foreach (['https://other.example.org/', 'https://127.0.0.1/', 'file:///etc/passwd', '//other.example.org/',
            'https://user:password@docs.example.org/', 'https://docs.example.org:8443/'] as $target) {
            $transport = new Transport();
            $transport->responses = [$this->redirect($target)];
            $this->failure(fn () => $this->fetch(dns: new Resolver(), transport: $transport)->fetch('https://docs.example.org/'), 'unsafe_origin');
            self::assertCount(1, $transport->sent);
        }
        $transport = new Transport();
        $transport->responses = array_fill(0, 4, $this->redirect('/again'));
        $this->failure(fn () => $this->fetch(dns: new Resolver(), transport: $transport)->fetch('https://docs.example.org/'), 'origin_redirect_limit');
        self::assertCount(4, $transport->sent);
    }

    public function testIdentityFramingTransferAndMimeBudgets(): void
    {
        $reader = new HttpResponseReader();
        foreach ($this->cases()['badHttp'] as $case) {
            $stream = $this->stream($case['wire']);
            try {
                $this->failure(fn () => $reader->read(stream: $stream, limits: new FetchLimits(maxBytes: 8), deadline: hrtime(true) + 1_000_000_000),
                    code: $case['code'], outcome: Outcome::Incompatible);
            } finally {
                fclose($stream);
            }
        }
        $transport = new Transport();
        $transport->responses = [new RemoteResponse(status: 200, headers: ['content-type' => 'text/plain'], bytes: '<script>bad</script>')];
        $this->failure(fn () => $this->fetch(dns: new Resolver(), transport: $transport)->fetch('https://docs.example.org/'),
            code: 'origin_media_type', outcome: Outcome::Incompatible);
    }

    public function testCookiesDiscardedAndNativePeerCheckPrecedesHttpBytes(): void
    {
        $stream = $this->stream("HTTP/1.0 200 OK\r\nContent-Type: text/plain\r\nSet-Cookie: session=synthetic\r\nContent-Length: 4\r\n\r\nsafe");
        $response = (new HttpResponseReader())->read(stream: $stream, limits: new FetchLimits(), deadline: hrtime(true) + 1_000_000_000);
        fclose($stream);
        self::assertSame('safe', $response->bytes());
        self::assertArrayNotHasKey('set-cookie', $response->headers);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $origin = (new OriginPolicy(['docs.example.org']))->resolve(url: 'https://docs.example.org/', resolver: new Resolver());
            $method = new \ReflectionMethod(NativeHttps::class, 'verifyPeer');
            $this->failure(fn () => $method->invoke(new NativeHttps(), $pair[0], $origin), 'origin_peer_mismatch');
            stream_set_blocking($pair[1], false);
            self::assertSame('', fread($pair[1], 1024));
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
        $this->failure(fn () => (new NativeResolver('/unavailable/php'))->addresses('docs.example.org'),
            code: 'origin_dns_unavailable', outcome: Outcome::Unavailable);
    }

    public function testNativeRequestHasNoCredentialHeadersAndDeadlinesCannotDisableLimits(): void
    {
        $origin = (new OriginPolicy(['docs.example.org']))->resolve(url: 'https://docs.example.org/path', resolver: new Resolver());
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $previous = getenv('HTTPS_PROXY');
        putenv('HTTPS_PROXY=http://127.0.0.1:9');
        try {
            $method = new \ReflectionMethod(NativeHttps::class, 'writeRequest');
            $method->invoke(new NativeHttps(), $pair[0], $origin, hrtime(true) + 1_000_000_000);
            stream_set_blocking($pair[1], false);
            $wire = fread($pair[1], 4096);
            self::assertStringStartsWith("GET /path HTTP/1.0\r\nHost: docs.example.org\r\n", $wire);
            foreach (['Authorization:', 'Cookie:', 'Proxy-Authorization:', 'Referer:', '127.0.0.1'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $wire);
            }
            $this->failure(fn () => (new NativeHttps())->get(origin: $origin, limits: new FetchLimits(), deadline: hrtime(true) - 1),
                code: 'origin_timeout', outcome: Outcome::Unavailable);
        } finally {
            putenv($previous === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY=' . $previous);
            fclose($pair[0]);
            fclose($pair[1]);
        }
        try {
            new FetchLimits(maxBytes: 0);
            self::fail('Unlimited transfer accepted');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame('Finite connector ceilings required', $failure->getMessage());
        }
        $transport = new Transport();
        $transport->responses = array_fill(0, 3, $this->redirect('/again'));
        $dns = new Resolver();
        self::assertSame('safe', $this->fetch(dns: $dns, transport: $transport)->fetch('https://docs.example.org/')->bytes());
        self::assertCount(4, $transport->sent);
        self::assertCount(4, $dns->seen);
    }

    private function fetch(Resolver $dns, Transport $transport): FetchRemote
    {
        return new FetchRemote(policy: new OriginPolicy(['docs.example.org']), resolver: $dns, transport: $transport);
    }

    private function redirect(string $location): RemoteResponse
    {
        return new RemoteResponse(status: 302, headers: ['location' => $location], bytes: '');
    }

    /** @return resource */
    private function stream(string $wire): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $wire);
        rewind($stream);

        return $stream;
    }
}
