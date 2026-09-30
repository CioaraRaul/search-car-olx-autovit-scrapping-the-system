<?php

namespace App\Services\Reliability;

/**
 * Accepts a listing only if its title names a model we know is a sedan or
 * estate AND a sensible buy for this buyer — a whitelist, not a blacklist.
 *
 * Site body-type filters just forward whatever category the seller ticked, so
 * a coupe/hatchback can pass a body_type=sedan,break criterion (a BMW Seria 2
 * did). A blacklist of "bad" models can never be complete, so anything not
 * explicitly listed in config('car_knowledge.body_type_guard') is rejected.
 * It is a criteria filter (like the price filter), not a reliability score.
 */
class BodyTypeGuard
{
    public const PREMIUM_BRAND = 'premium-brand';

    public const REJECTED_BODY = 'rejected-body-keyword';

    public const UNLISTED_MODEL = 'unlisted-model';

    public const BODY_UNCONFIRMED = 'body-unconfirmed';

    /**
     * @return string|null null when the listing is acceptable, otherwise a reason code
     */
    public function rejectionReason(string $title): ?string
    {
        $haystack = strtolower(trim($title));
        $config = config('car_knowledge.body_type_guard');

        if ($haystack === '') {
            return self::UNLISTED_MODEL;
        }

        if ($this->anyKeywordMatches($config['premium_brands'], $haystack)) {
            return self::PREMIUM_BRAND;
        }

        if ($this->anyKeywordMatches($config['rejected_keywords'], $haystack)) {
            return self::REJECTED_BODY;
        }

        if ($this->anyGroupMatches($config['always_sedan_or_estate'], $haystack)) {
            return null;
        }

        if ($this->anyGroupMatches($config['needs_body_keyword'], $haystack)) {
            return $this->anyKeywordMatches($config['body_keywords'], $haystack)
                ? null
                : self::BODY_UNCONFIRMED;
        }

        return self::UNLISTED_MODEL;
    }

    public function isAcceptable(string $title): bool
    {
        return $this->rejectionReason($title) === null;
    }

    /**
     * @param  array<int, array<int, string>>  $groups
     */
    private function anyGroupMatches(array $groups, string $haystack): bool
    {
        foreach ($groups as $group) {
            foreach ($group as $keyword) {
                if (! $this->containsWord($haystack, $keyword)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function anyKeywordMatches(array $keywords, string $haystack): bool
    {
        foreach ($keywords as $keyword) {
            if ($this->containsWord($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** Whole-word match, so "mini" doesn't hit "minivan" and "3" doesn't hit "2013". */
    private function containsWord(string $haystack, string $keyword): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote(strtolower($keyword), '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}
