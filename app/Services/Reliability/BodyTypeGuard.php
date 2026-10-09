<?php

namespace App\Services\Reliability;

use App\Support\TitleText;

/**
 * Decides whether a listing is really a sedan or estate (and not a premium-brand
 * car with costly repairs), using the title AND the ad description.
 *
 * Site body-type filters just forward whatever category the seller ticked, so a
 * coupe/hatchback can pass a body_type=sedan,break criterion (a BMW Seria 2
 * did). Any make/model is allowed except:
 *   1. premium brands;
 *   2. titles with a non-sedan word (coupe, hatchback, SUV, ...);
 *   3. models that are never a sedan/estate (hatchbacks, SUVs, MPVs, vans),
 *      unless the title names an estate version ("Golf Variant");
 *   4. cars where neither title nor description says sedan/berlina/estate/...
 *      (models that are only ever sedan/estate, like the Dacia Logan, skip this).
 *
 * A criteria filter (like the price filter), not a reliability score. The word
 * lists live in config('car_knowledge.body_type_guard').
 */
class BodyTypeGuard
{
    public const PREMIUM_BRAND = 'premium-brand';

    public const REJECTED_BODY = 'rejected-body-keyword';

    public const HATCHBACK_ONLY = 'hatchback-only-model';

    public const BODY_UNCONFIRMED = 'body-unconfirmed';

    /**
     * The rejections that need only the title — safe to apply BEFORE spending a
     * request on the ad page.
     *
     * @return string|null null when nothing in the title rules the car out
     */
    public function hardRejection(string $title): ?string
    {
        $haystack = $this->normalize($title);
        $config = config('car_knowledge.body_type_guard');

        if ($this->anyKeywordMatches($config['premium_brands'], $haystack)) {
            return self::PREMIUM_BRAND;
        }

        if ($this->anyKeywordMatches($config['rejected_keywords'], $haystack)) {
            return self::REJECTED_BODY;
        }

        if ($this->anyGroupMatches($config['hatchback_only_models'], $haystack)
            && ! $this->anyKeywordMatches($config['estate_keywords'], $haystack)) {
            return self::HATCHBACK_ONLY;
        }

        return null;
    }

    /**
     * The full decision. $description null = the ad page has not been read, so
     * only the title is available to confirm the body style.
     *
     * @return string|null null when the listing is acceptable, otherwise a reason code
     */
    public function rejectionReason(string $title, ?string $description = null): ?string
    {
        $reason = $this->hardRejection($title);

        if ($reason !== null) {
            return $reason;
        }

        $config = config('car_knowledge.body_type_guard');
        $normalizedTitle = $this->normalize($title);

        if ($this->anyGroupMatches($config['always_sedan_or_estate'], $normalizedTitle)) {
            return null;
        }

        $text = $normalizedTitle.' '.$this->normalize((string) $description);
        $bodyWords = array_merge($config['sedan_keywords'], $config['estate_keywords']);

        return $this->anyKeywordMatches($bodyWords, $text) ? null : self::BODY_UNCONFIRMED;
    }

    public function isAcceptable(string $title, ?string $description = null): bool
    {
        return $this->rejectionReason($title, $description) === null;
    }

    private function normalize(string $text): string
    {
        return TitleText::normalize($text);
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

    private function containsWord(string $haystack, string $keyword): bool
    {
        return TitleText::containsWord($haystack, $keyword);
    }
}
