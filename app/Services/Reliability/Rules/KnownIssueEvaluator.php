<?php

namespace App\Services\Reliability\Rules;

use App\Models\ReliabilityRule;
use App\Services\Reliability\ReliabilityFlag;

/**
 * Matches a listing's title+description against the DB-editable list of
 * known-problem-engine rules. Free-text keyword matching, not structured data —
 * see the Chapter 5 plan for why (listings don't store an engine code).
 */
class KnownIssueEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $haystack = strtolower(($listing['title'] ?? '').' '.($listing['description'] ?? ''));

        if ($haystack === '') {
            return [];
        }

        $flags = [];

        foreach (ReliabilityRule::query()->where('active', true)->get() as $rule) {
            if ($this->matchesAnyGroup($rule->keywords, $haystack)) {
                $flags[] = new ReliabilityFlag($rule->name, $rule->message, $rule->penalty);
            }
        }

        return $flags;
    }

    /**
     * @param  array<int, array<int, string>>  $groups
     */
    private function matchesAnyGroup(array $groups, string $haystack): bool
    {
        foreach ($groups as $group) {
            $allKeywordsMatch = true;

            foreach ($group as $keyword) {
                if (! str_contains($haystack, strtolower($keyword))) {
                    $allKeywordsMatch = false;

                    break;
                }
            }

            if ($allKeywordsMatch) {
                return true;
            }
        }

        return false;
    }
}
