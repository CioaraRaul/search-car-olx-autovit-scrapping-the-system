<?php

namespace App\Services\Reliability;

final readonly class ReliabilityScore
{
    /**
     * @param  array<int, ReliabilityFlag>  $flags
     */
    public function __construct(
        public int $score,
        public array $flags,
    ) {}

    /**
     * @return array<int, array{rule: string, message: string, penalty: int}>
     */
    public function flagsToArray(): array
    {
        return array_map(fn (ReliabilityFlag $flag) => $flag->toArray(), $this->flags);
    }
}
