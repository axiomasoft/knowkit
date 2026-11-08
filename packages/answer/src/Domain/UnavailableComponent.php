<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum UnavailableComponent: string
{
    case Transport = 'transport';
    case HttpClient = 'http_client';
    case Credentials = 'credentials';
    case ModelBinding = 'model_binding';
    case RetrievalProfile = 'retrieval_profile';
    case Payload = 'payload';
    case Ledger = 'ledger';
}
