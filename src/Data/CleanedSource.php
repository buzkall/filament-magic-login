<?php

namespace Arzcode\FilamentMagicLogin\Data;

final readonly class CleanedSource
{
    /**
     * @param  array<int, int>  $unresolvedLines  Lines still mentioning the package.
     */
    public function __construct(
        public string $code,
        public bool $changed,
        public array $unresolvedLines,
    ) {}

    public function isClean(): bool
    {
        return $this->unresolvedLines === [];
    }
}
