<?php

namespace App\Services\Reliability;

final readonly class ReliabilityFlag
{
    public function __construct(
        public string $rule,
        public string $message,
        public int $penalty,
    ) {}

    /**
     * @return array{rule: string, message: string, penalty: int}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'message' => $this->message,
            'penalty' => $this->penalty,
        ];
    }
}
