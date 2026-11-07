#!/usr/bin/env python3
"""Independent ASCII-key JCS/SHA256 fixture oracle; refuses changed v1 expectations.

Initial authoring derives the structured/Unicode shapes from P1.4's project-owned
golden corpus. No parser output is snapshotted and no optional parser is qualified.
Changed expectations require a new version plus a substantive rationale.
"""
import base64
import copy
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
BASE = ROOT / 'tests/fixtures/ir'
VERSION = '1.0.0'
RATIONALE = ('Initial source-integrity corpus: independently calculated SHA256 over literal original '
             'bytes, canonical UTF-8/JCS, and ordered spans. Structured/Unicode shapes adapt the '
             'project-authored P1.4 golden corpus; no external parser implementation is claimed.')


def canonical(value):
    # All keys in these fixtures are ASCII; values intentionally retain combining marks.
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf-8')


def sha(value):
    return hashlib.sha256(value).hexdigest()


def typed(domain, value):
    return sha(domain.encode() + b'\0' + canonical(value))


def write(relative, data):
    target = ROOT / relative
    if not isinstance(data, bytes):
        data = (json.dumps(data, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    if target.exists() and target.read_bytes() != data:
        raise RuntimeError('Versioned fixture changed; create a new version with rationale: ' + relative)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
    return sha(data)


def uid(n):
    return '01J' + str(n).zfill(23)


options = {'normalization': 'none', 'geometryRounding': 'half-even', 'languages': ['ru', 'ja']}
profile = {'name': 'synthetic-ir', 'version': '1', 'profileDigest': typed('knowkit:parser-profile:v1', options)}
source = b'%PDF synthetic original bytes; offsets belong to canonical text, never these bytes.\n'
source_path = 'tests/fixtures/ir/v1/original.bin'
write(source_path, source)
# Tiny synthetic PNG; binary identity only, no image-decoder qualification.
asset = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
asset_path = 'tests/fixtures/ir/v1/original.png'
write(asset_path, asset)
entries = []


def add(case_id, name, ir, expected):
    path = 'tests/fixtures/ir/v1/' + name + '.json'
    entry = {'id': case_id, 'path': path, 'sha256': write(path, ir), 'schemaId': 'urn:knowkit:schema:document-ir:1.0',
             'irDigest': sha(canonical(ir)), 'sourcePath': source_path, 'sourceDigest': sha(source), 'expected': expected}
    if ir['assets']:
        entry['assets'] = {'a1': {'path': asset_path, 'sha256': sha(asset), 'byteLength': str(len(asset))}}
    entries.append(entry)


def existing(name):
    path = ROOT / 'tests/fixtures/contracts/v1/valid' / name
    result = json.loads(path.read_bytes())
    result.update(tenantId=uid(1), sourceVersionId=uid(3), parserSignature=profile)
    return result


unicode_ir = existing('document-ir.unicode-multipage.json')
structured = existing('document-ir.structured.json')
structured['assets'][0].update(digest=sha(asset), byteLength=str(len(asset)))
ocr = existing('document-ir.ocr-uncertain.json')
add('IR-UNICODE', 'unicode', unicode_ir, {'status': 'valid', 'coverage': 'complete'})
add('IR-STRUCTURED', 'structured', structured, {'status': 'valid', 'coverage': 'partial'})
add('IR-OCR', 'ocr', ocr, {'status': 'valid', 'coverage': 'partial'})


def coverage(state, processed, empty=0, failed=0, skipped=0):
    return {'state': state, 'counts': {'expected': processed + failed + skipped, 'processed': processed, 'empty': empty,
                                     'failed': failed, 'skipped': skipped}, 'missingFeatures': [], 'diagnostics': []}


blank = copy.deepcopy(unicode_ir)
blank.update(pages=[copy.deepcopy(unicode_ir['pages'][1])], blocks=[], coverage=coverage('complete', 1, 1))
add('IR-BLANK', 'blank', blank, {'status': 'valid', 'coverage': 'complete'})
partial = copy.deepcopy(unicode_ir)
partial.update(pages=[{'pageId': 'p' + str(i), 'physicalNo': i, 'printedLabel': str(i), 'text': 'Page ' + str(i),
                       'textDigest': sha(('Page ' + str(i)).encode()), 'status': 'ok'} for i in range(1, 10)] +
                     [{'pageId': 'p10', 'physicalNo': 10, 'printedLabel': 'x', 'text': '', 'textDigest': sha(b''), 'status': 'failed'}],
               blocks=[], coverage=coverage('partial', 9, failed=1))
partial['coverage']['diagnostics'] = [{'segmentId': 'p10', 'code': 'scan_missing', 'severity': 'error'}]
add('IR-MISSING-SCAN', 'missing-scan', partial, {'status': 'valid', 'coverage': 'partial', 'missingPhysicalNos': [10]})
unknown = copy.deepcopy(partial)
unknown['coverage']['state'] = 'unknown'
unknown['coverage']['counts'].pop('expected')
add('IR-UNKNOWN', 'unknown', unknown, {'status': 'valid', 'coverage': 'unknown'})

segment = copy.deepcopy(blank)
segment.update(pages=[], segments=[{'segmentId': 's1', 'text': 'Cafe\u0301 ﬁ', 'textDigest': sha('Cafe\u0301 ﬁ'.encode()), 'status': 'ok'}],
               coverage=coverage('complete', 1))
locator = {'kind': 'segment', 'segmentId': 's1', 'blockId': 'b1', 'byteStart': 0, 'byteEnd': len('Cafe\u0301 ﬁ'.encode())}
segment['blocks'] = [{'blockId': 'b1', 'kind': 'text', 'parentId': None, 'order': 0, 'origin': 'source', 'spans': [locator],
                      'provenance': {'kind': 'verbatim', 'supportingRefs': []}}]
add('IR-SEGMENT', 'segment', segment, {'status': 'valid', 'coverage': 'complete'})
maximum = copy.deepcopy(segment)
maximum['blocks'][0]['order'] = 4294967295
maximum['pages'] = [{'pageId': 'max', 'physicalNo': 4294967295, 'printedLabel': 'max', 'text': '', 'textDigest': sha(b''), 'status': 'empty'}]
maximum['coverage'] = coverage('complete', 2, 1)
add('IR-UINT32', 'uint32', maximum, {'status': 'valid', 'coverage': 'complete'})


def negative(case_id, name, base, change, code, path):
    candidate = copy.deepcopy(base)
    change(candidate)
    add(case_id, name, candidate, {'status': 'invalid', 'code': code, 'path': path})


negative('IR-UTF8-SPLIT', 'utf8-split', unicode_ir, lambda x: x['blocks'][0]['spans'][0].update(byteStart=1), 'utf8_boundary', '/blocks/0/spans/0/byteStart')
negative('IR-CYCLE', 'cycle', unicode_ir, lambda x: x['blocks'][0].update(parentId='b2'), 'reference_cycle', '/blocks/0/parentId')
negative('IR-REF', 'unknown-ref', unicode_ir, lambda x: x['blocks'][0].update(parentId='absent'), 'unknown_reference', '/blocks/0/parentId')
negative('IR-PAGE-HASH', 'page-hash', unicode_ir, lambda x: x['pages'][0].update(textDigest='0' * 64), 'digest_mismatch', '/pages/0/textDigest')
negative('IR-READING-ORDER', 'reading-order', unicode_ir, lambda x: x['blocks'][1].update(order=0), 'order_violation', '/blocks/1/order')
negative('IR-BBOX-SPACE', 'bbox-space', unicode_ir, lambda x: x['blocks'][0]['spans'][0]['bbox'].update(space='inferred'), 'const', '/blocks/0/spans/0/bbox/space')
negative('IR-BBOX-RANGE', 'bbox-range', unicode_ir, lambda x: x['blocks'][0]['spans'][0]['bbox'].update(x1=1000001), 'maximum', '/blocks/0/spans/0/bbox/x1')
negative('IR-SEGMENT-PAGE', 'segment-page', segment, lambda x: x['blocks'][0]['spans'][0].update(physicalNo=1), 'forbidden_field', '/blocks/0/spans/0/physicalNo')
negative('IR-COVERAGE-LIE', 'coverage-lie', partial, lambda x: x['coverage'].update(state='complete'), 'coverage_mismatch', '/coverage/state')
negative('IR-EXPECTED-COUNT', 'expected-count', partial, lambda x: x['coverage']['counts'].update(expected=11), 'coverage_mismatch', '/coverage/counts/expected')

span_specs = [(0, 0), (1, 0), (2, 0), (1, 1)]
texts = {p['pageId']: p['text'].encode('utf-8') for p in unicode_ir['pages']}
parts = [texts[s['pageId']][s['byteStart']:s['byteEnd']].decode('utf-8')
         for i, j in span_specs for s in [unicode_ir['blocks'][i]['spans'][j]]]
joined = ' […] '.join(parts)
processing = {'sourceVersionId': uid(3), 'sourceDigest': sha(source), 'parserSignature': profile,
              'ocrProfileSignature': profile, 'chunkerSignature': profile, 'irSchema': 'ir-1.0',
              'indexProfileDigest': 'b' * 64, 'embeddingSpaceDigest': None}
manifest = {'schemaVersion': 'ir-validation-fixtures:1', 'fixtureVersion': VERSION, 'versionRationale': RATIONALE,
            'rights': {'license': 'LicenseRef-KnowKit-Private', 'provenance': 'Project-authored synthetic corpus; P1.4 shapes and P2.5 donor qualification.'},
            'externalAdaptersImplemented': False, 'profileOptions': options, 'parserProfileDigest': profile['profileDigest'],
            'processingDigest': typed('knowkit:processing-identity:v1', processing),
            'multiSpan': {'spanIndices': span_specs, 'joiner': ' […] ', 'text': joined, 'contentDigest': sha(joined.encode())},
            'normalizedMapping': {'normalized': 'Café', 'canonical': 'Cafe\u0301', 'normalizedStart': 0, 'normalizedEnd': 5,
                                  'canonicalStart': 0, 'canonicalEnd': 6}, 'cases': entries}
write('tests/fixtures/ir/manifest.v1.json', manifest)

