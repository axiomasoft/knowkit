<?php
declare(strict_types=1);

namespace KnowKit\Application\Catalog;

use KnowKit\Domain\{DomainFailure,Outcome};

/** For privileged origin adapters. Opaque catalog origin refs are never passed to network/filesystem APIs. */
final class OriginBoundary
{
    /** Validate every initial/redirect URL and ALL DNS answers before any privileged connection. */
    public static function https(string $url,array $resolvedAddresses): string
    {
        $p=parse_url($url);
        if ($p===false || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])
            || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443) || preg_match('/[\x00-\x20\\\\]/',$url)
            || $resolvedAddresses===[]) { throw new DomainFailure(Outcome::Denied,'unsafe_origin'); }
        $host=trim($p['host'],'[]');
        if (filter_var($host,FILTER_VALIDATE_IP)!==false) { $resolvedAddresses[]=$host; }
        foreach ($resolvedAddresses as $ip) {
            if (!is_string($ip) || filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false
                || str_contains($ip,':') && (!str_starts_with(strtolower($ip),'2') && !str_starts_with(strtolower($ip),'3'))) {
                throw new DomainFailure(Outcome::Denied,'unsafe_origin');
            }
            $ranges=str_contains($ip,':')?['2001::/23','2001:db8::/32','2002::/16']:
                ['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'];
            foreach ($ranges as $range) { if (self::inRange($ip,$range)) { throw new DomainFailure(Outcome::Denied,'unsafe_origin'); } }
        }
        return $url;
    }
    private static function inRange(string $ip,string $cidr): bool
    {
        [$network,$bits]=explode('/',$cidr); $address=inet_pton($ip); $prefix=inet_pton($network); $bits=(int)$bits;
        if ($address===false || $prefix===false || strlen($address)!==strlen($prefix)) { return false; }
        $bytes=intdiv($bits,8); $tail=$bits%8;
        return substr($address,0,$bytes)===substr($prefix,0,$bytes)
            && ($tail===0 || ((ord($address[$bytes])^ord($prefix[$bytes])) & (255<<(8-$tail)))===0);
    }
    public static function storageKey(string $key): string
    {
        if (strlen($key)>512 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D',$key)!==1 || str_contains($key,'..')) {
            throw new DomainFailure(Outcome::Denied,'unsafe_path');
        }
        return $key;
    }
}
