<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum EgressCategory: string
{
    case Question = 'QUESTION';
    case History = 'HISTORY';
    case Evidence = 'EVIDENCE';
    case SystemInstruction = 'SYSTEM_INSTRUCTION';
}
