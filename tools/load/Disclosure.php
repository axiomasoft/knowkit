<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Disclosure\Fixture;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/retrieval/disclosure/bootstrap.php';

final class Disclosure
{
    public static function run(): array
    {
        $report = [];
        Fixture::run(function (Fixture $f) use (&$report): void {
            $samples = [];
            $cpu = Metrics::cpuMs();
            for ($i = 0; $i < 20; ++$i) {
                $selected = $f->select();
                $outcome = Metrics::timed(operation: fn () => $f->disclose($selected), samples: $samples);
                Qa::ensure($outcome->result->outcome === D\Outcome::Success, 'bounded disclosure failed');
                $fragment = $outcome->result->data->toWire()->get('fragments')[0];
                Qa::ensure($fragment->get('citation')->get('sourceVersionId') === $f->ref()->revisionRef->sourceRef->sourceVersionId->value,
                    'disclosure changed immutable source version');
            }
            $failures = self::verifyFailures($f);
            $cold = array_shift($samples);
            $warm = Metrics::percentiles($samples);
            $report = Report::save('disclosure', ['coldLatencyMs' => $cold, 'warmLatencyMs' => $warm,
                'cpuMs' => Metrics::cpuMs() - $cpu, 'targetWarmP95Ms' => 200, 'targetMet' => $warm['p95'] <= 200,
                ...$failures, 'remoteOwnerIo' => false,
                'postgres' => $f->db()->execute('SHOW server_version')->fetchColumn()]);
        });

        return $report;
    }

    private static function verifyFailures(Fixture $f): array
    {
        $before = $f->lexical->catalog->count('evidence_usages');
        $selected = $f->select();
        $bodies = $f->objects->bodies;
        $f->objects->bodies = [];
        $outage = $f->disclose($selected);
        Qa::ensure($outage->result->outcome === D\Outcome::Unavailable && $outage->result->data === null, 'object outage became empty/success');
        Qa::ensure($f->lexical->catalog->count('evidence_usages') === $before, 'outage committed disclosure usage');
        $f->objects->bodies = $bodies;
        $selected = $f->select();
        $f->revoke();
        $denied = $f->disclose($selected);
        Qa::ensure($denied->result->outcome === D\Outcome::Denied && $denied->result->data === null, 'post-revoke disclosure escaped');
        Qa::ensure($f->lexical->catalog->count('evidence_usages') === $before, 'denied attempt committed usage');

        return ['objectOutage' => $outage->result->outcome->value, 'afterCommittedRevoke' => $denied->result->outcome->value, 'usageCount' => $before];
    }
}
