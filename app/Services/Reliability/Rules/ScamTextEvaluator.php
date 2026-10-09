<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;
use App\Support\TitleText;

/**
 * Stage 2 of the checks: does the ad's own wording read like a scam? Looks for the classic
 * patterns in the title and description — money asked before the buyer sees the car, a courier or
 * escrow service "holding" the car, a car shipped from abroad unseen. A heuristic: it can raise
 * suspicion, never prove a scam, so only unambiguous phrases are listed (a seller merely living
 * abroad, or a dealer offering financing, is NOT flagged).
 */
class ScamTextEvaluator implements ReliabilityRuleEvaluator
{
    /** @var array<string, string> pattern (on accent-free lower-case text) => explanation */
    private const PATTERNS = [
        '/\b(?:plata|plat\w+|achit\w*|transfer\w*|trimit\w*|virez\w*)\s+(?:in\s+)?avans\b|\bavans\s+(?:prin|via|pe)\s+(?:transfer|revolut|western|card|iban)/' => 'asks for an advance payment',
        '/\b(?:western\s*union|moneygram|escrow|transfer\s+bancar\s+inainte|plata\s+inainte)\b/' => 'asks for payment through an unusual channel before the buyer sees the car',
        '/\b(?:livrez|livrare|trimit)\s+(?:masina|autoturismul|automobilul)\b.{0,60}\b(?:curier|transportator|firma\s+de\s+transport|agent)\b|\bagent\s+de\s+transport\b|\bmasina\s+(?:se\s+afla|este)\s+(?:la|in)\s+(?:depozit|firma\s+de\s+transport)/' => 'offers to ship the car unseen through a courier or transport agent',
    ];

    public function evaluate(array $listing): array
    {
        $penalty = (int) config('car_knowledge.scam_text.penalty');

        if ($penalty <= 0) {
            return [];
        }

        $text = TitleText::normalize(($listing['title'] ?? '').' '.($listing['description'] ?? ''));
        $flags = [];

        foreach (self::PATTERNS as $pattern => $what) {
            if (preg_match($pattern, $text) === 1) {
                $flags[] = new ReliabilityFlag('scam-wording', "The ad {$what} — a classic scam pattern.", $penalty);
            }
        }

        return $flags;
    }
}
