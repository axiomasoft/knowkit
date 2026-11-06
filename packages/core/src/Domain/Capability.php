<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Capability: string
{
    case ParserText = 'parser.text';
    case ParserMarkdown = 'parser.markdown';
    case ParserOcr = 'parser.ocr';
    case ParserTables = 'parser.tables';
    case ParserImages = 'parser.images';
    case ChunkerStructural = 'chunker.structural';
    case LexicalPrefilter = 'lexical.prefilter';
    case VectorPrefilter = 'vector.prefilter';
    case EmbeddingDocuments = 'embedding.documents';
    case EmbeddingQuery = 'embedding.query';
    case RerankSubset = 'rerank.subset';
    case CompleteInventory = 'source.inventory.complete';
    case ImmutableStorage = 'storage.immutable';
    case DeleteStorage = 'storage.delete';
    case ExactTokenizer = 'tokenizer.exact';
    case JobFencing = 'job.fencing';
    case OutboxDelivery = 'outbox.delivery';
}
