<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class Bbox
{
    public function __construct(public int $x0, public int $y0, public int $x1, public int $y1)
    {
        if ($x0 < 0 || $y0 < 0 || $x1 > 1000000 || $y1 > 1000000 || $x0 >= $x1 || $y0 >= $y1) {
            throw new \InvalidArgumentException('Integer page millionths required');
        }
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['space' => 'page-millionths-top-left-v1', 'origin' => 'top-left',
            'view' => 'rotated-page', 'x0' => $this->x0, 'y0' => $this->y0, 'x1' => $this->x1, 'y1' => $this->y1]);
    }
}
