<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Cross-field rules that JSON Schema cannot express (wire-contract.md#semantic-rules).
 * Run only after a document is schema-valid; every rule reports code + exact instance path.
 */
final class SemanticRules
{
    /** @return list<array{code: string, path: string}> */
    public static function check(string $schemaId, JsonObject $doc): array
    {
        $name = explode(':', $schemaId)[3] ?? '';
        $errors = [];
        switch ($name) {
            case 'document-ir':
                $errors = self::documentIr($doc);
                break;
            case 'decision-receipt':
                if (HashDomain::DecisionReceipt->digest($doc->without('receiptDigest')) !== $doc->get('receiptDigest')) {
                    $errors[] = self::error('digest_mismatch', '/receiptDigest');
                }
                break;
            case 'worker-result':
                if (HashDomain::WorkerResult->digest($doc->without('resultDigest')) !== $doc->get('resultDigest')) {
                    $errors[] = self::error('digest_mismatch', '/resultDigest');
                }
                break;
            case 'domain-event':
                if (HashDomain::DomainEventPayload->digest($doc->get('payload')) !== $doc->get('payloadDigest')) {
                    $errors[] = self::error('digest_mismatch', '/payloadDigest');
                }
                $payload = $doc->get('payload');
                if ($doc->get('type') === 'usage.prepared_disclosure' && $payload->get('kind') === 'navigation'
                    && $payload->get('revisionRef')->get('tenantId') !== $doc->get('tenantId')) {
                    $errors[] = self::error('binding_mismatch', '/payload/revisionRef/tenantId');
                }
                break;
            case 'job-envelope':
                if ($doc->get('profileFingerprint') !== $doc->get('parserSignature')->get('profileDigest')) {
                    $errors[] = self::error('binding_mismatch', '/profileFingerprint');
                }
                break;
            case 'evidence-set':
                $errors = self::evidenceSet($doc);
                break;
            case 'index-manifest':
                $counts = $doc->get('counts');
                if ($counts->get('members') !== count($doc->get('members'))) {
                    $errors[] = self::error('count_mismatch', '/counts/members');
                }
                if ($counts->get('resources') !== count($doc->get('resources'))) {
                    $errors[] = self::error('count_mismatch', '/counts/resources');
                }
                $chunks = 0;
                foreach ($doc->get('members') as $member) {
                    $chunks += $member->get('chunkCount');
                }
                if ($counts->get('chunks') !== $chunks) {
                    $errors[] = self::error('count_mismatch', '/counts/chunks');
                }
                break;
            case 'deletion-task':
                $states = array_map(static fn(JsonObject $resource): string => $resource->get('state'), $doc->get('requiredResources'));
                if (self::deletionStatus($states) !== $doc->get('status')) {
                    $errors[] = self::error('status_mismatch', '/status');
                }
                break;
            case 'selection':
                foreach ($doc->get('candidateRefs') as $index => $ref) {
                    if ($ref->get('tenantId') !== $doc->get('tenantId')) {
                        $errors[] = self::error('binding_mismatch', '/candidateRefs/' . $index . '/tenantId');
                    }
                }
                break;
            case 'usage':
                foreach ($doc->get('fragmentRefs') as $index => $ref) {
                    if ($ref->get('chunkRef')->get('tenantId') !== $doc->get('tenantId')) {
                        $errors[] = self::error('binding_mismatch', '/fragmentRefs/' . $index . '/chunkRef/tenantId');
                    }
                }
                break;
        }
        return $errors;
    }

    /**
     * Aggregate status of a frozen deletion inventory: all completed -> done; some completed ->
     * partial; all unsupported -> unsupported; any unknown/unsupported without progress -> unknown;
     * otherwise pending (pending/in_flight).
     *
     * @param list<string> $states
     */
    public static function deletionStatus(array $states): string
    {
        $completed = array_filter($states, static fn(string $state): bool => in_array($state, ['deleted', 'absent'], true));
        if (count($completed) === count($states)) {
            return 'done';
        }
        if ($completed !== []) {
            return 'partial';
        }
        $unsupported = array_filter($states, static fn(string $state): bool => $state === 'unsupported');
        if (count($unsupported) === count($states)) {
            return 'unsupported';
        }
        foreach ($states as $state) {
            if ($state === 'unknown' || $state === 'unsupported') {
                return 'unknown';
            }
        }
        return 'pending';
    }

    /** @return array{code: string, path: string} */
    private static function error(string $code, string $path): array
    {
        return ['code' => $code, 'path' => $path];
    }

    /** @return list<array{code: string, path: string}> */
    private static function evidenceSet(JsonObject $doc): array
    {
        $errors = [];
        $total = 0;
        $revisions = $versions = [];
        foreach ($doc->get('fragments') as $index => $fragment) {
            $prefix = '/fragments/' . $index;
            $text = $fragment->get('text');
            $total += strlen($text);
            if (hash('sha256', $text) !== $fragment->get('fragmentDigest')) {
                $errors[] = self::error('digest_mismatch', $prefix . '/fragmentDigest');
            }
            $citation = $fragment->get('citation');
            if ($citation->get('contentDigest') !== $fragment->get('fragmentDigest')) {
                $errors[] = self::error('digest_mismatch', $prefix . '/citation/contentDigest');
            }
            if ($citation->get('tenantId') !== $doc->get('tenantId')) {
                $errors[] = self::error('binding_mismatch', $prefix . '/citation/tenantId');
            }
            // One immutable SourceVersion has one sourceDigest and one DocumentRevision one irDigest.
            $version = $citation->get('tenantId') . '/' . $citation->get('sourceVersionId');
            $revision = $version . '/' . $citation->get('documentRevisionId');
            if (($versions[$version] ?? $citation->get('sourceDigest')) !== $citation->get('sourceDigest')) {
                $errors[] = self::error('binding_mismatch', $prefix . '/citation/sourceDigest');
            }
            if (($revisions[$revision] ?? $citation->get('irDigest')) !== $citation->get('irDigest')) {
                $errors[] = self::error('binding_mismatch', $prefix . '/citation/irDigest');
            }
            $versions[$version] ??= $citation->get('sourceDigest');
            $revisions[$revision] ??= $citation->get('irDigest');
            if ((count($citation->get('spans')) > 1) !== ($fragment->get('joiner') !== null)) {
                $errors[] = self::error('binding_mismatch', $prefix . '/joiner');
            }
        }
        if ($doc->get('snapshotRef')->get('tenantId') !== $doc->get('tenantId')) {
            $errors[] = self::error('binding_mismatch', '/snapshotRef/tenantId');
        }
        if ($total !== $doc->get('totalBytes')) {
            $errors[] = self::error('count_mismatch', '/totalBytes');
        }
        if (HashDomain::EvidenceSet->digest($doc->without('evidenceSetDigest')) !== $doc->get('evidenceSetDigest')) {
            $errors[] = self::error('digest_mismatch', '/evidenceSetDigest');
        }
        return $errors;
    }

    /**
     * @param array<string, JsonObject> $pages
     * @param array<string, JsonObject> $segments
     * @param list<array{code: string, path: string}> $errors
     */
    private static function locator(JsonObject $locator, string $blockId, string $prefix, array $pages, array $segments, array &$errors): void
    {
        if ($locator->get('blockId') !== $blockId) {
            $errors[] = self::error('binding_mismatch', $prefix . '/blockId');
        }
        if ($locator->get('kind') === 'page') {
            $page = $pages[$locator->get('pageId')] ?? null;
            if ($page === null) {
                $errors[] = self::error('unknown_reference', $prefix . '/pageId');
                return;
            }
            if ($page->get('physicalNo') !== $locator->get('physicalNo')) {
                $errors[] = self::error('binding_mismatch', $prefix . '/physicalNo');
            }
            if ($page->get('printedLabel') !== $locator->get('printedLabel')) {
                $errors[] = self::error('binding_mismatch', $prefix . '/printedLabel');
            }
            $text = $page->get('text');
        } else {
            $segment = $segments[$locator->get('segmentId')] ?? null;
            if ($segment === null) {
                $errors[] = self::error('unknown_reference', $prefix . '/segmentId');
                return;
            }
            $text = $segment->get('text');
        }
        $length = strlen($text);
        if ($locator->get('byteEnd') > $length) {
            $errors[] = self::error('span_out_of_range', $prefix . '/byteEnd');
            return;
        }
        foreach (['byteStart', 'byteEnd'] as $field) {
            $offset = $locator->get($field);
            if ($offset < $length && (ord($text[$offset]) & 0xC0) === 0x80) {
                $errors[] = self::error('utf8_boundary', $prefix . '/' . $field);
            }
        }
    }

    /** @return list<array{code: string, path: string}> */
    private static function documentIr(JsonObject $ir): array
    {
        $errors = [];
        $pages = $segments = $blocks = $assets = [];
        foreach ($ir->get('pages') as $page) {
            $pages[$page->get('pageId')] = $page;
        }
        foreach ($ir->get('segments') as $segment) {
            $segments[$segment->get('segmentId')] = $segment;
        }
        foreach ($ir->get('blocks') as $block) {
            $blocks[$block->get('blockId')] = $block;
        }
        foreach ($ir->get('assets') as $asset) {
            $assets[$asset->get('assetId')] = true;
        }
        $tally = ['ok' => 0, 'empty' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (['pages', 'segments'] as $collection) {
            foreach ($ir->get($collection) as $index => $unit) {
                $prefix = '/' . $collection . '/' . $index;
                if (hash('sha256', $unit->get('text')) !== $unit->get('textDigest')) {
                    $errors[] = self::error('digest_mismatch', $prefix . '/textDigest');
                }
                if ($unit->get('status') === 'ok' && $unit->get('text') === '') {
                    $errors[] = self::error('page_status_mismatch', $prefix . '/status');
                }
                if ($unit->get('status') !== 'ok' && $unit->get('text') !== '') {
                    $errors[] = self::error('page_status_mismatch', $prefix . '/text');
                }
                $tally[$unit->get('status')]++;
            }
        }
        $pageList = $ir->get('pages');
        for ($index = 1; $index < count($pageList); $index++) {
            if ($pageList[$index]->get('physicalNo') <= $pageList[$index - 1]->get('physicalNo')) {
                $errors[] = self::error('order_violation', '/pages/' . $index . '/physicalNo');
            }
        }
        $blockList = $ir->get('blocks');
        foreach ($blockList as $index => $block) {
            $prefix = '/blocks/' . $index;
            $blockId = $block->get('blockId');
            if ($index > 0 && $block->get('order') <= $blockList[$index - 1]->get('order')) {
                $errors[] = self::error('order_violation', $prefix . '/order');
            }
            $parent = $block->get('parentId');
            if ($parent !== null) {
                if ($parent === $blockId) {
                    $errors[] = self::error('self_reference', $prefix . '/parentId');
                } elseif (!isset($blocks[$parent])) {
                    $errors[] = self::error('unknown_reference', $prefix . '/parentId');
                } else {
                    $seen = [$blockId => true];
                    $cursor = $parent;
                    while ($cursor !== null && isset($blocks[$cursor])) {
                        if (isset($seen[$cursor])) {
                            if ($cursor === $blockId) {
                                $errors[] = self::error('reference_cycle', $prefix . '/parentId');
                            }
                            break;
                        }
                        $seen[$cursor] = true;
                        $cursor = $blocks[$cursor]->get('parentId');
                    }
                }
            }
            foreach ($block->get('provenance')->get('supportingRefs') as $refIndex => $ref) {
                $refPath = $prefix . '/provenance/supportingRefs/' . $refIndex;
                if ($ref === $blockId) {
                    $errors[] = self::error('self_reference', $refPath);
                } elseif (!isset($blocks[$ref]) && !isset($assets[$ref])) {
                    $errors[] = self::error('unknown_reference', $refPath);
                }
            }
            foreach ((array) $block->get('assetRefs') as $refIndex => $ref) {
                if (!isset($assets[$ref])) {
                    $errors[] = self::error('unknown_reference', $prefix . '/assetRefs/' . $refIndex);
                }
            }
            foreach ($block->get('spans') as $spanIndex => $span) {
                self::locator($span, $blockId, $prefix . '/spans/' . $spanIndex, $pages, $segments, $errors);
            }
            $table = $block->get('table');
            if ($table instanceof JsonObject) {
                $headers = [];
                foreach ($table->get('cells') as $cell) {
                    if ($cell->get('header') === true) {
                        $headers[$cell->get('cellId')] = true;
                    }
                }
                foreach ($table->get('cells') as $cellIndex => $cell) {
                    $cellPath = $prefix . '/table/cells/' . $cellIndex;
                    if ($cell->get('row') + $cell->get('rowSpan') > $table->get('rows')
                        || $cell->get('column') + $cell->get('colSpan') > $table->get('columns')) {
                        $errors[] = self::error('table_cell_out_of_range', $cellPath);
                    }
                    foreach ($cell->get('headerRefs') as $refIndex => $ref) {
                        if (!isset($headers[$ref])) {
                            $errors[] = self::error('unknown_reference', $cellPath . '/headerRefs/' . $refIndex);
                        }
                    }
                    foreach ($cell->get('noteRefs') as $refIndex => $ref) {
                        if (!isset($blocks[$ref])) {
                            $errors[] = self::error('unknown_reference', $cellPath . '/noteRefs/' . $refIndex);
                        }
                    }
                    foreach ($cell->get('spans') as $spanIndex => $span) {
                        self::locator($span, $blockId, $cellPath . '/spans/' . $spanIndex, $pages, $segments, $errors);
                    }
                }
            }
        }
        foreach ($ir->get('assets') as $index => $asset) {
            if ($asset->get('pageId') !== null && !isset($pages[$asset->get('pageId')])) {
                $errors[] = self::error('unknown_reference', '/assets/' . $index . '/pageId');
            }
        }
        $coverage = $ir->get('coverage');
        $counts = $coverage->get('counts');
        $expected = ['processed' => $tally['ok'] + $tally['empty'], 'empty' => $tally['empty'],
            'failed' => $tally['failed'], 'skipped' => $tally['skipped']];
        foreach ($expected as $field => $number) {
            if ($counts->get($field) !== $number) {
                $errors[] = self::error('coverage_mismatch', '/coverage/counts/' . $field);
            }
        }
        if ($counts->has('expected') && $counts->get('expected') !== array_sum($tally)) {
            $errors[] = self::error('coverage_mismatch', '/coverage/counts/expected');
        }
        $hasError = false;
        foreach ($coverage->get('diagnostics') as $index => $diagnostic) {
            $hasError = $hasError || $diagnostic->get('severity') === 'error';
            if ($diagnostic->has('segmentId') && !isset($segments[$diagnostic->get('segmentId')]) && !isset($pages[$diagnostic->get('segmentId')])) {
                $errors[] = self::error('unknown_reference', '/coverage/diagnostics/' . $index . '/segmentId');
            }
        }
        if ($coverage->get('state') === 'complete'
            && ($tally['failed'] > 0 || $tally['skipped'] > 0 || $coverage->get('missingFeatures') !== [] || $hasError)) {
            $errors[] = self::error('coverage_mismatch', '/coverage/state');
        }
        return $errors;
    }
}
