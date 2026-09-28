<?php

namespace App\Console\Commands;

use App\Models\SearchCriterion;
use App\Support\CriteriaCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('criteria:set {name} {value}')]
#[Description('Set a search-criteria parameter by name. Run criteria:help to see available parameters.')]
class CriteriaSet extends Command
{
    public function handle(): int
    {
        $name = $this->argument('name');
        $value = $this->argument('value');

        $definition = CriteriaCatalog::get($name);

        if ($definition === null) {
            $this->error("Unknown criteria parameter \"{$name}\". Run `php artisan criteria:help` to see available parameters.");

            return self::FAILURE;
        }

        if (! $this->valueIsValid($value, $definition)) {
            $expected = $definition['type'];

            if (isset($definition['allowed'])) {
                $expected .= ', one of: '.implode(', ', $definition['allowed']);
            }

            $this->error("Invalid value \"{$value}\" for \"{$name}\" (expected {$expected}).");

            return self::FAILURE;
        }

        SearchCriterion::updateOrCreate(['key' => $name], ['value' => $value]);

        $this->info("Set {$name} = {$value}");

        return self::SUCCESS;
    }

    /**
     * @param  array{type: string, allowed?: array<int, string>}  $definition
     */
    private function valueIsValid(string $value, array $definition): bool
    {
        if (isset($definition['allowed'])) {
            $parts = array_map('trim', explode(',', $value));

            foreach ($parts as $part) {
                if (! in_array($part, $definition['allowed'], true)) {
                    return false;
                }
            }

            return true;
        }

        return match ($definition['type']) {
            'int' => (bool) preg_match('/^\d+$/', $value),
            'float' => is_numeric($value),
            'string' => $value !== '',
            default => false,
        };
    }
}
