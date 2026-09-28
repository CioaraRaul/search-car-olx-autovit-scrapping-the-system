<?php

namespace App\Console\Commands;

use App\Models\SearchCriterion;
use App\Support\CriteriaCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('criteria:help')]
#[Description('List every available search-criteria parameter and its current value.')]
class CriteriaHelp extends Command
{
    public function handle(): int
    {
        $current = SearchCriterion::query()->pluck('value', 'key');

        $rows = [];

        foreach (CriteriaCatalog::definitions() as $name => $definition) {
            $type = $definition['type'];

            if (isset($definition['allowed'])) {
                $type .= ' ('.implode('/', $definition['allowed']).')';
            }

            $rows[] = [$name, $type, $definition['description'], $current->get($name, '—')];
        }

        $this->table(['Parameter', 'Type', 'Description', 'Current value'], $rows);

        return self::SUCCESS;
    }
}
