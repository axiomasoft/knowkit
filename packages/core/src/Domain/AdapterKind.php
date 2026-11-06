<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum AdapterKind: string
{
    case Parser = 'parser';
    case Chunker = 'chunker';
    case Lexical = 'lexical';
    case Vector = 'vector';
    case Embedding = 'embedding';
    case Reranker = 'reranker';
    case SourceConnector = 'source_connector';
    case SourceReader = 'source_reader';
    case BlobStore = 'blob_store';
    case AccessPolicy = 'access_policy';
    case EgressPolicy = 'egress_policy';
    case Jobs = 'jobs';
    case Outbox = 'outbox';
    case Clock = 'clock';
    case Tokenizer = 'tokenizer';
}
