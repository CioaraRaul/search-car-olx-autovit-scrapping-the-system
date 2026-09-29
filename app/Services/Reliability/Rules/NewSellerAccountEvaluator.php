<?php

namespace App\Services\Reliability\Rules;

use App\Services\Reliability\ReliabilityFlag;

/**
 * Flags a seller whose account was registered this calendar year — a
 * statistical suspicion signal (per the used-car-evaluator skill's "soft
 * scam warnings"), not proof of a scam on its own. Populated by
 * AutovitDetailFetcher (a "registration-date" badge) and OlxDetailFetcher
 * ("Pe OLX din <date>") — both read from the same ad-page fetch already done
 * for damage/consumption checking, at no extra request cost.
 */
class NewSellerAccountEvaluator implements ReliabilityRuleEvaluator
{
    public function evaluate(array $listing): array
    {
        $registeredYear = $listing['seller_registered_year'] ?? null;

        if ($registeredYear === null || (int) $registeredYear !== (int) date('Y')) {
            return [];
        }

        return [new ReliabilityFlag(
            'new-seller-account',
            "Seller account registered this year ({$registeredYear}) — a common trait of scam or flipper listings, though not proof on its own.",
            (int) config('car_knowledge.new_seller_account.penalty'),
        )];
    }
}
