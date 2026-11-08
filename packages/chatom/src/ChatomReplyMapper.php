<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Documents\Data\MessageDocument;
use KnowKit\Answer\Domain\AnsweredContent;

/** Deterministic final.v1 bytes: plain text plus opaque immutable citation handles.
 * No source paths, quotes in preview metadata, extension nodes or model-authored markup.
 */
final readonly class ChatomReplyMapper
{
    public const SLOT = 'final.v1';

    public function map(AnsweredContent $content): MessageDocument
    {
        $references = [];
        foreach ($content->citations as $index => $citation) {
            $number = $index + 1;
            $references[] = "[{$number}] knowkit:citation:{$citation->id()->value}";
        }

        return MessageDocument::fromPlainText($content->text . "\n\n" . implode("\n", $references));
    }
}
