<?php

declare(strict_types=1);

namespace KnowKit\Tests\Training;

require_once __DIR__ . '/TrainingTestSupport.php';

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\{DomainFailure, Outcome, ProfileId, Ulid, UtcInstant};
use KnowKit\TrainingExport\Adapter\StrictRegexPiiRedactor;
use KnowKit\TrainingExport\Application\{ExportAdmissionGate, TrainingExportJobHandler};
use KnowKit\TrainingExport\Domain\TrainingFormat;
use KnowKit\TrainingExport\Port\PiiRedactor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('training:export')]
final class PiiGuardTest extends TestCase
{
    public function testStrictRegexRedactsEmailPhoneJwtApiKeysPassportInnSnils(): void
    {
        $redactor = new StrictRegexPiiRedactor();

        $cases = [
            ['alice.ivanova@example.org', '[REDACTED_EMAIL]'],
            ['+7 (999) 123-45-67', '[REDACTED_PHONE]'],
            ['+79991234567', '[REDACTED_PHONE]'],
            ['89991234567', '[REDACTED_PHONE]'],
            ['8 999 123 45 67', '[REDACTED_PHONE]'],
            ['тел. 9991234567', '[REDACTED_PHONE]'],
            ['+1 202 555 0143', '[REDACTED_PHONE]'],
            ['45 09 123456', '[REDACTED_PASSPORT]'],
            ['+79991234567.', '[REDACTED_PHONE]'],
            ['паспорт 45 09 123456', '[REDACTED_PASSPORT]'],
            ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c', '[REDACTED_JWT]'],
            ['sk-live-abcdefghijklmnopqrstuvwxyz1234', '[REDACTED_API_KEY]'],
            ['паспорт серия 45 09 № 123456', '[REDACTED_PASSPORT]'],
            ['ИНН: 7707083893', '[REDACTED_INN]'],
            ['112-233-445 95', '[REDACTED_SNILS]'],
        ];

        foreach ($cases as [$input, $expectedMarker]) {
            $redacted = $redactor->redact('User data: ' . $input . ' end.');
            self::assertStringContainsString($expectedMarker, $redacted);
            self::assertStringNotContainsString($input, $redacted);
            self::assertFalse($redactor->containsResidualPii($redacted));
        }
    }

    public function testSupportTicketIdsAndLessonNumbersAreNotPassportsOrPhones(): void
    {
        $redactor = new StrictRegexPiiRedactor();
        $samples = [
            'заказ 123-456-7890',
            'INC-123-456-78-90',
            'ticket 100-200-30-40',
            'урок 12 05 123456',
            'баллы 12-34-56-78',
        ];

        foreach ($samples as $sample) {
            $redacted = $redactor->redact('Запись: ' . $sample . ' конец.');
            self::assertStringContainsString($sample, $redacted);
            self::assertStringNotContainsString('REDACTED', $redacted);
            self::assertFalse($redactor->containsResidualPii($redacted));
        }
    }

    public function testInvariantsRejectKeysThatWouldBeRedacted(): void
    {
        $redactor = new StrictRegexPiiRedactor();
        foreach (['alice@example.org', '89991234567', 'Bearer abcdefghijklmnop'] as $key) {
            $trace = TrainingTestSupport::makeTrace(lockedInvariants: [$key => true]);
            $gate = new ExportAdmissionGate(
                TrainingTestSupport::allowAccessPolicy($trace->scope),
                TrainingTestSupport::allowRightsPolicy(),
                TrainingTestSupport::allowDependencyStore(),
                $redactor,
            );
            try {
                $gate->admit($trace);
                self::fail('Expected denial for a sensitive invariant key');
            } catch (DomainFailure $error) {
                self::assertSame('pii_redaction_failed', $error->errorCode);
            }
        }
    }

    public function testManifestRedactorIdMustMatchAdmission(): void
    {
        $trace = TrainingTestSupport::makeTrace();
        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );
        $this->expectException(\InvalidArgumentException::class);
        new TrainingExportJobHandler($gate, new ProfileId('different-redactor'));
    }

    public function testResidualPiiPostScanGuardFailsClosed(): void
    {
        $leakyRedactor = new class implements PiiRedactor {
            public function id(): ProfileId
            {
                return new ProfileId('leaky-stub-v1');
            }

            public function redact(string $utf8): string
            {
                return $utf8;
            }

            public function containsResidualPii(string $utf8): bool
            {
                return str_contains($utf8, 'SECRET_LEAK');
            }
        };

        $trace = TrainingTestSupport::makeTrace(
            messages: [
                ['role' => 'user', 'content' => 'Question with SECRET_LEAK'],
                ['role' => 'assistant', 'content' => 'Answer [#1]'],
            ],
        );

        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            $leakyRedactor,
        );

        try {
            $gate->admit($trace);
            self::fail('Expected DomainFailure pii_redaction_failed');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Denied, $failure->outcome);
            self::assertSame('pii_redaction_failed', $failure->errorCode);
        }
    }

    public function testAuditSummaryContainsOnlyTraceIdsAndReasonCodesNeverPlaintext(): void
    {
        $secretPlaintext = 'TOP_SECRET_USER_EMAIL_alice@corp.local_DO_NOT_LOG';
        $leakyRedactor = new class implements PiiRedactor {
            public function id(): ProfileId
            {
                return new ProfileId('strict-regex-v1');
            }

            public function redact(string $utf8): string
            {
                return $utf8;
            }

            public function containsResidualPii(string $utf8): bool
            {
                return str_contains($utf8, 'TOP_SECRET_USER_EMAIL');
            }
        };

        $trace = TrainingTestSupport::makeTrace(
            traceId: new Ulid('01JTR000000000000000000099'),
            messages: [
                ['role' => 'user', 'content' => $secretPlaintext],
                ['role' => 'assistant', 'content' => 'Reply [#1]'],
            ],
        );

        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            $leakyRedactor,
        );

        $handler = new TrainingExportJobHandler($gate, $leakyRedactor->id());
        $result = $handler->handle(
            exportId: new Ulid('01JEX000000000000000000001'),
            tenantId: $trace->scope->tenantId,
            format: TrainingFormat::GroundedSftV1,
            createdAt: new UtcInstant('2026-11-27T12:00:00Z'),
            traces: [$trace],
            validator: TrainingTestSupport::validator(),
        );

        self::assertSame(0, $result['manifest']->includedCount);
        self::assertSame([], $result['manifest']->snapshotRefs);
        self::assertSame([], $result['manifest']->layerStackDigests);
        self::assertSame(['pii_redaction_failed' => 1], $result['manifest']->excludedCountsByReason);
        self::assertSame([
            ['traceId' => '01JTR000000000000000000099', 'reason' => 'pii_redaction_failed'],
        ], $result['auditExcluded']);

        $serializedManifest = Jcs::canonicalize($result['manifest']->toWire());
        $serializedAudit = json_encode($result['auditExcluded'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secretPlaintext, $serializedManifest);
        self::assertStringNotContainsString($secretPlaintext, $serializedAudit);
    }
}
