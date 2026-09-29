<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Fetches one OLX ad's own page and best-effort-detects damage/fuel
 * consumption from its free-text description — OLX has no structured field
 * for either (unlike Autovit's __NEXT_DATA__), so this is keyword matching,
 * not reliable structured data. See the Romanian phrase lists below.
 */
class OlxDetailFetcher
{
    /**
     * Phrases that mean "explicitly NOT damaged" — stripped out of the text
     * before damage-keyword matching, so "fără accident" doesn't get flagged
     * just because it contains the substring "accident".
     *
     * @var array<int, string>
     */
    private const NEGATION_PHRASES = [
        'fara accident', 'fără accident',
        'fara nicio avarie', 'fără nicio avarie',
        'fara daune', 'fără daune',
        'fara avarii', 'fără avarii',
        'neaccidentata', 'neaccidentată', 'neaccidentat',
        'neavariata', 'neavariată', 'neavariat',
        'nu a fost accidentata', 'nu a fost accidentată', 'nu a fost accidentat',
        'nu a fost avariata', 'nu a fost avariată', 'nu a fost avariat',
    ];

    /**
     * Phrases indicating the car IS damaged, checked only after negation
     * phrases have been stripped out of the text.
     *
     * @var array<int, string>
     */
    private const DAMAGE_PHRASES = [
        'avariata', 'avariată', 'avariat',
        'accidentata', 'accidentată', 'accidentat',
        'lovita', 'lovită', 'lovit',
        'daune majore', 'avarii',
    ];

    /**
     * @return array{damaged: ?bool, fuelConsumptionL100km: ?float, sellerRegisteredYear: ?int}
     */
    public function fetch(string $url): array
    {
        $guard = RobotsTxtGuard::for(config('scraping.olx.base_url'));

        if (! $guard->isAllowed($url)) {
            throw new RuntimeException("OLX's robots.txt disallows this URL, refusing to fetch: {$url}");
        }

        $response = Http::withUserAgent(config('scraping.user_agent'))
            ->timeout(20)
            ->get($url);

        $response->throw();

        $html = $response->body();
        $description = $this->extractDescription($html);

        return [
            'damaged' => $this->detectDamaged($description),
            'fuelConsumptionL100km' => $this->parseFuelConsumption($description),
            'sellerRegisteredYear' => $this->parseSellerRegisteredYear($this->extractMemberSince($html)),
        ];
    }

    /**
     * Runs the same keyword detection as fetch(), but against already-known
     * text (e.g. a listing's title) — lets a caller check for self-disclosed
     * damage before spending an HTTP request on the full ad page.
     */
    public function detectDamaged(string $text): ?bool
    {
        $normalized = mb_strtolower($text);
        $masked = str_ireplace(self::NEGATION_PHRASES, ' ', $normalized);

        foreach (self::DAMAGE_PHRASES as $phrase) {
            if (str_contains($masked, $phrase)) {
                return true;
            }
        }

        foreach (self::NEGATION_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return false;
            }
        }

        return null;
    }

    private function extractDescription(string $html): string
    {
        $crawler = new Crawler($html);
        $descriptionNode = $crawler->filter('[data-testid="ad_description"]');

        if ($descriptionNode->count() === 0) {
            return '';
        }

        // Emotion (CSS-in-JS) sometimes embeds a <style> tag inside this
        // container; a naive text() call would include its raw CSS rules.
        $descriptionNode->filter('style')->each(function (Crawler $style) {
            foreach ($style as $node) {
                $node->parentNode?->removeChild($node);
            }
        });

        return trim($descriptionNode->text('', true));
    }

    /**
     * Renders as e.g. "Pe OLX din martie 2026" (Romanian month name + year).
     */
    private function extractMemberSince(string $html): string
    {
        $node = (new Crawler($html))->filter('[data-testid="member-since"]');

        return $node->count() > 0 ? trim($node->text('', true)) : '';
    }

    private function parseSellerRegisteredYear(string $memberSinceText): ?int
    {
        if (! preg_match('/\b((?:19|20)\d{2})\b/', $memberSinceText, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Prefers an explicit combined ("mixt") figure when present — some OLX
     * descriptions include a full copy-pasted spec block, occasionally with
     * a combined figure Autovit doesn't even give directly. Falls back to
     * averaging urban + extra-urban, same as AutovitDetailFetcher.
     */
    private function parseFuelConsumption(string $description): ?float
    {
        $normalized = mb_strtolower($description);

        if (preg_match('/mixt[^\d]{0,20}([\d.,]+)\s*l\s*\/\s*100\s*km/u', $normalized, $matches)) {
            return $this->toFloat($matches[1]);
        }

        $urban = $this->extractConsumptionFigure($normalized, 'urban');
        $extraUrban = $this->extractConsumptionFigure($normalized, 'extra-?urban');

        $values = array_filter([$urban, $extraUrban], fn (?float $value) => $value !== null);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    private function extractConsumptionFigure(string $normalized, string $labelPattern): ?float
    {
        if (! preg_match('/'.$labelPattern.'[^\d]{0,20}([\d.,]+)\s*l\s*\/\s*100\s*km/u', $normalized, $matches)) {
            return null;
        }

        return $this->toFloat($matches[1]);
    }

    private function toFloat(string $raw): float
    {
        return (float) str_replace(',', '.', $raw);
    }
}
