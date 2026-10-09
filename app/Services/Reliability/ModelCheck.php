<?php

namespace App\Services\Reliability;

use App\Models\ModelReputation;
use App\Support\TitleText;
use Illuminate\Support\Collection;

/**
 * Stage 1 of the checks: is this specific make+model one we know to be a good car?
 * Looks the ad title up in the editable model_reputations table. A title that matches no
 * known model, or a model marked "avoid", is not acceptable — the car is dropped before the
 * ad page is fetched, so it never spends any of the daily fetch budget.
 */
class ModelCheck
{
    /** @var Collection<int, ModelReputation>|null */
    private ?Collection $rows = null;

    /** The known model the title names, or null when it names none (missing/unknown model). */
    public function find(?string $title): ?ModelReputation
    {
        $haystack = TitleText::normalize((string) $title);

        if ($haystack === '') {
            return null;
        }

        $matches = $this->rows()->filter(fn (ModelReputation $row) => $this->matches($row->keywords, $haystack));

        // "Skoda Fabia" and "Skoda Octavia" never overlap, but a broad row could sit inside a
        // narrower one — the row with the most specific (longest) keywords wins.
        return $matches->sortByDesc(fn (ModelReputation $row) => $this->specificity($row))->first();
    }

    /** True when the model is known and not marked "avoid". */
    public function isAcceptable(?string $title): bool
    {
        $row = $this->find($title);

        return $row !== null && $row->verdict !== ModelReputation::AVOID;
    }

    /** Why the title is rejected: 'unknown-model' or 'avoid-model', null when acceptable. */
    public function rejectionReason(?string $title): ?string
    {
        $row = $this->find($title);

        if ($row === null) {
            return 'unknown-model';
        }

        return $row->verdict === ModelReputation::AVOID ? 'avoid-model' : null;
    }

    /**
     * @return Collection<int, ModelReputation>
     */
    private function rows(): Collection
    {
        return $this->rows ??= ModelReputation::query()->where('active', true)->get();
    }

    /**
     * @param  array<int, array<int, string>>  $groups
     */
    private function matches(array $groups, string $haystack): bool
    {
        foreach ($groups as $group) {
            foreach ($group as $keyword) {
                if (! TitleText::containsWord($haystack, $keyword)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function specificity(ModelReputation $row): int
    {
        return max(array_map(fn (array $group) => strlen(implode('', $group)), $row->keywords));
    }
}
