<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Contracts;

use KnowKit\Answer\Domain\ModelBindingRef;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Port\ConversationBindingResolver;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\KbId;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;
use RuntimeException;

final class Fixture implements ConversationBindingResolver
{
    public int $reads = 0;
    public bool $unavailable = false;

    public function __construct(
        public ConversationBinding $binding,
    ) {}

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corpus(): array
    {
        static $corpus = null;

        return $corpus ??= json_decode(file_get_contents(self::root() . '/tests/fixtures/chatom/routes/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Installed host bytes win. AgenTools must fail closed when that host is absent.
     * DeepTutor bytes are not retained in the public tree; the pinned blob is the witness.
     */
    public static function inspectedSha256(string $donor, string $relative, string $sourceUrl): string
    {
        static $hashes = [];
        $key = $donor . "\0" . $relative;
        if (isset($hashes[$key])) {
            return $hashes[$key];
        }
        $local = self::inspectedFile(donor: $donor, relative: $relative);
        if ($local !== null) {
            $hash = hash_file('sha256', $local);
            if ($hash === false) {
                throw new RuntimeException('Cannot hash inspected donor: ' . $relative);
            }

            return $hashes[$key] = $hash;
        }
        if ($donor === 'agentools') {
            throw new RuntimeException('Installed AgenTools host is absent: ' . $relative);
        }

        return $hashes[$key] = self::remoteDonorSha256($sourceUrl);
    }

    private static function inspectedFile(string $donor, string $relative): ?string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException('Invalid donor witness path');
        }
        $root = self::root();
        $candidates = [];
        if ($donor === 'agentools') {
            $candidates[] = $root . '/packages/agentools-bridge/vendor/agentools/platform-core/' . $relative;
        }
        $candidates[] = $root . '/.swissknifeman/orch/donor-cache/' . $donor . '/' . $relative;
        $rootReal = realpath($root);
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($rootReal !== false && $real !== false && is_file($real)
                && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    private static function remoteDonorSha256(string $sourceUrl): string
    {
        if (!preg_match('#\Ahttps://github\.com/HKUDS/DeepTutor/blob/([0-9a-f]{40})/([A-Za-z0-9_./-]+)\z#', $sourceUrl, $match)
            || str_contains($match[2], '..')) {
            throw new RuntimeException('Unpinned donor witness URL');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Donor witness unavailable without a local cache or the curl extension');
        }
        $url = 'https://raw.githubusercontent.com/HKUDS/DeepTutor/' . $match[1] . '/' . $match[2];
        $cache = sys_get_temp_dir() . '/knowkit-donor-' . hash('sha256', $url);
        $cached = is_file($cache) ? file_get_contents($cache) : false;
        if (is_string($cached) && $cached !== '') {
            return hash('sha256', $cached);
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'knowkit-tests',
        ]);
        $bytes = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($bytes) || $bytes === '' || $status !== 200) {
            throw new RuntimeException('Donor witness unavailable: ' . $sourceUrl);
        }
        $temporary = $cache . '.part';
        file_put_contents($temporary, $bytes, LOCK_EX);
        rename($temporary, $cache);

        return hash('sha256', $bytes);
    }

    /** @param array<string, string> $changes */
    public static function conversation(array $changes = []): ConversationRef
    {
        $values = array_replace(self::corpus()['conversation'], $changes);

        return new ConversationRef(
            tenantId: new TenantId($values['tenantId']),
            spaceId: $values['spaceId'],
            channelId: $values['channelId'],
            botId: $values['botId'],
            bindingId: $values['bindingId'],
        );
    }

    /** @param array<string, string> $changes */
    public static function binding(array $changes = [], ?ConversationRef $conversation = null): ConversationBinding
    {
        $values = array_replace(self::corpus()['binding'], $changes);
        $conversation ??= self::conversation();
        $kb = new KbId($values['kbId']);
        $scope = new Scope(
            tenantId: $conversation->tenantId,
            kbId: $kb,
            actorId: new ActorId($values['actorId']),
            purpose: $values['purpose'],
            policyEpoch: new Decimal($values['policyEpoch']),
            hostScopeHash: new Sha256($values['hostScopeHash']),
        );

        return new ConversationBinding(
            conversation: $conversation,
            route: ConversationRoute::from($values['route']),
            kbId: $kb,
            modelBindingRef: new ModelBindingRef($values['modelBindingRef']),
            hostScopeInputs: $scope,
            viewerBindingRef: $values['viewerBindingRef'],
            bindingRevision: $values['bindingRevision'],
        );
    }

    public function resolve(ConversationRef $conversation): ConversationBinding
    {
        $this->reads++;

        if ($this->unavailable) {
            throw new RuntimeException('Host facts unavailable; protected diagnostic must not escape');
        }

        return $this->binding;
    }
}
