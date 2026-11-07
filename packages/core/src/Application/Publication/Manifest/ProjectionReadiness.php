<?php
declare(strict_types=1);
namespace KnowKit\Application\Publication\Manifest;

use KnowKit\Domain as D;
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain\Contract\JsonObject;

/** Optional projection providers are host supplied. No external adapter is implied by this contract. */
final readonly class ProjectionReadiness
{
    /** @var array<string,\Closure(Candidate): void> */
    private array $optional;
    /** @param array<array-key,mixed> $optional Named persisted projection probes, validated below. */
    public function __construct(array $optional = [])
    {
        $probes=[];
        foreach ($optional as $name=>$probe) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{1,63}\z/D', $name) !== 1 || $name === 'lexical' || !$probe instanceof \Closure) {
                throw new \InvalidArgumentException('Named optional projection probe required');
            }
            $probes[$name]=$probe;
        }
        $this->optional=$probes;
    }
    public function check(Candidate $candidate): JsonObject
    {
        $capabilities = ['lexical'=>new JsonObject(['required'=>true,'outcome'=>'success'])]; $degraded = [];
        $optional = $this->optional; ksort($optional, SORT_STRING);
        foreach ($optional as $name=>$probe) {
            try { $probe($candidate); $outcome = D\Outcome::Success; $code = null; }
            catch (D\DomainFailure $failure) { $outcome = $failure->outcome; $code = $failure->errorCode; }
            catch (\Throwable) { $outcome = D\Outcome::Unavailable; $code = 'projection_unavailable'; }
            $capabilities[$name] = new JsonObject(['required'=>false,'outcome'=>$outcome->value,'code'=>$code]);
            if ($outcome !== D\Outcome::Success) { $degraded[] = $name; }
        }
        return new JsonObject(['profile'=>$candidate->manifest->toWire()->get('profile')->get('id'),
            'capabilities'=>new JsonObject($capabilities),'degraded'=>$degraded]);
    }
}
