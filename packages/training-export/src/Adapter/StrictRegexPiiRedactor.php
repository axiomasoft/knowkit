<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Adapter;

use KnowKit\Domain\{DomainFailure, Outcome, ProfileId};
use KnowKit\TrainingExport\Port\PiiRedactor;

final readonly class StrictRegexPiiRedactor implements PiiRedactor
{
    // Only an explicitly labelled lesson ID is exempt from the bare passport rule.
    private const BARE_PASSPORT = '/\b(?:урок|lesson)\h+\d{2}\h\d{2}\h\d{6}\b(*SKIP)(*F)|\b\d{2}\s\d{2}\s\d{6}\b/ui';

    private const REDACT_RULES = [
        // Private keys / PEM headers
        '/-----BEGIN [A-Z0-9 ]+PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]+PRIVATE KEY-----/' => '[REDACTED_KEY]',
        // JWT tokens (three base64url segments starting with eyJ)
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => '[REDACTED_JWT]',
        // API keys & bearer tokens
        '/\b(?:sk|pk|rk)-(?:live-|test-|proj-)?[A-Za-z0-9_-]{16,}\b/' => '[REDACTED_API_KEY]',
        '/\bghp_[A-Za-z0-9]{20,}\b/' => '[REDACTED_API_KEY]',
        '/\bAKIA[0-9A-Z]{16}\b/' => '[REDACTED_API_KEY]',
        '/\bBearer\s+[A-Za-z0-9._\-]{16,}\b/i' => 'Bearer [REDACTED_TOKEN]',
        '/\b(?:api[_-]?key|secret[_-]?key|access[_-]?token)\s*[:=]\s*["\']?[A-Za-z0-9._\-]{12,}["\']?/i' => '[REDACTED_SECRET]',
        // Email addresses
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u' => '[REDACTED_EMAIL]',
        // SNILS (XXX-XXX-XXX XX or XXX-XXX-XXX-XX)
        '/\b\d{3}-\d{3}-\d{3}[\s\-]\d{2}\b/' => '[REDACTED_SNILS]',
        // INN (with keyword prefix, 10 or 12 digits)
        '/(?<=\bИНН|\bINN)\s*[:#№]?\s*\d{10}(?:\d{2})?\b/ui' => ' [REDACTED_INN]',
        // Handle explicit passport labels before considering lesson IDs.
        '/\b(?:паспорт|passport)\s*(?:серия|series)?\s*[:#№]?\s*\d{2}\s*\d{2}\s*(?:№|#)?\s*\d{6}\b/ui' => '[REDACTED_PASSPORT]',
        '/\b(?:серия|series)\s*[:#№]?\s*\d{2}\s*\d{2}\s*(?:№|#)?\s*\d{6}\b/ui' => '[REDACTED_PASSPORT]',
        '/\b\d{2}\s\d{2}\s*[№#]\s*\d{6}\b/u' => '[REDACTED_PASSPORT]',
        self::BARE_PASSPORT => '[REDACTED_PASSPORT]',
        // Phones need a cue. Dashed groups such as 123-456-7890 are ticket and
        // order ids in a support transcript, not phones. Compact +7/8 numbers
        // and a tel/phone label are the forms that were previously leaked.
        '/(?<![\w.])\+(?:[\s\-().]*\d){10,15}(?!\w|\.\d)/' => '[REDACTED_PHONE]',
        '/(?<![\w.\-])8(?:[\s\-().]*\d){10}(?!\w|\.\d)/' => '[REDACTED_PHONE]',
        '/(?<![\w.])\(\d{3}\)[\s\-.]?\d{3}[\s\-.]?\d{2}[\s\-.]?\d{2}(?!\w|\.\d)/' => '[REDACTED_PHONE]',
        '/\b(?:тел(?:ефон)?|phone|mobile|mob|моб)\.?\s*[:#]?\s*\+?(?:[\s\-().]*\d){10,15}\b/ui' => '[REDACTED_PHONE]',
    ];

    private const RESIDUAL_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]+PRIVATE KEY-----/',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
        '/\b(?:sk|pk|rk)-(?:live-|test-|proj-)?[A-Za-z0-9_-]{16,}\b/',
        '/\bghp_[A-Za-z0-9]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
        '/\b\d{3}-\d{3}-\d{3}[\s\-]\d{2}\b/',
        '/\b(?:ИНН|INN)\s*[:#№]?\s*\d{10}(?:\d{2})?\b/ui',
        '/\b(?:паспорт|passport)\s*(?:серия|series)?\s*[:#№]?\s*\d{2}\s*\d{2}\s*(?:№|#)?\s*\d{6}\b/ui',
        '/\b(?:серия|series)\s*[:#№]?\s*\d{2}\s*\d{2}\s*(?:№|#)?\s*\d{6}\b/ui',
        '/\b\d{2}\s\d{2}\s*[№#]\s*\d{6}\b/u',
        self::BARE_PASSPORT,
        '/(?<![\w.])\+(?:[\s\-().]*\d){10,15}(?!\w|\.\d)/',
        '/(?<![\w.\-])8(?:[\s\-().]*\d){10}(?!\w|\.\d)/',
        '/(?<![\w.])\(\d{3}\)[\s\-.]?\d{3}[\s\-.]?\d{2}[\s\-.]?\d{2}(?!\w|\.\d)/',
        '/\b(?:тел(?:ефон)?|phone|mobile|mob|моб)\.?\s*[:#]?\s*\+?(?:[\s\-().]*\d){10,15}\b/ui',
    ];

    public function id(): ProfileId
    {
        return new ProfileId('strict-regex-v1');
    }

    public function redact(string $utf8): string
    {
        $result = $utf8;
        foreach (self::REDACT_RULES as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $result);
            if ($replaced === null) {
                throw new DomainFailure(Outcome::Denied, 'pii_redaction_failed');
            }
            $result = $replaced;
        }

        return $result;
    }

    public function containsResidualPii(string $utf8): bool
    {
        foreach (self::RESIDUAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $utf8) === 1) {
                return true;
            }
        }

        return false;
    }

    public function redactOrFail(string $utf8): string
    {
        $redacted = $this->redact($utf8);
        if ($this->containsResidualPii($redacted)) {
            throw new DomainFailure(Outcome::Denied, 'pii_redaction_failed');
        }

        return $redacted;
    }
}
