<?php

namespace App\Services\Reliability;

/**
 * Site body-type filters just forward whatever category the seller picked
 * when posting the ad — there's no independent verification, so a listing
 * can pass a body_type=sedan,break criterion even when its actual model is
 * never sold as a sedan or estate (e.g. a VW Polo mislabeled "Berlina").
 *
 * This checks a listing's title against a conservative, fixed list of
 * hatchback-only models (config('car_knowledge.body_type_mismatch')) to catch
 * that specific, common mislabeling. It is a criteria filter, not a
 * reliability judgment — used alongside the price filter in the scrape
 * commands, not inside ReliabilityScorer.
 */
class BodyTypeMismatchDetector
{
    public function isHatchbackOnlyModel(string $title): bool
    {
        $haystack = strtolower($title);

        if ($haystack === '') {
            return false;
        }

        foreach (config('car_knowledge.body_type_mismatch.hatchback_only_models') as $group) {
            if ($this->allKeywordsMatch($group, $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function allKeywordsMatch(array $keywords, string $haystack): bool
    {
        foreach ($keywords as $keyword) {
            if (! str_contains($haystack, strtolower($keyword))) {
                return false;
            }
        }

        return true;
    }
}
