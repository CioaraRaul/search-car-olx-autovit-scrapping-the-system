<?php

namespace App\Services\Scraping;

use App\Enums\ListingSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finds the gallery photos of ONE ad, for the car-looks-evaluator skill.
 *
 * The photos stored on a listing are just search-result thumbnails (OLX often
 * only a "no thumbnail" placeholder), useless for judging how a car looks. The
 * real gallery is on the ad's own page. Both OLX and Autovit serve images from
 * the same CDN as `.../<file-id>/image;s=<width>x<height>`, so one extractor
 * covers both: take every such URL in page order, keep one per file id, and
 * re-request it at a size good enough to inspect paint and interior.
 *
 * Runs on demand for the few cars on the shortlist — never in the daily scrape.
 */
class AdPhotoFetcher
{
    private const IMAGE_URL = '#https://[a-z0-9.\-]*olxcdn\.com(?::443)?/v1/files/([^"\\\\\s,;]+)/image;s=\d+x\d+#i';

    /**
     * @return array<int, string> absolute image URLs, main gallery first
     */
    public function photoUrls(ListingSource $source, string $adUrl, int $max = 8): array
    {
        $baseUrl = config($source === ListingSource::Olx ? 'scraping.olx.base_url' : 'scraping.autovit.base_url');

        if (! RobotsTxtGuard::for($baseUrl)->isAllowed($adUrl)) {
            throw new RuntimeException("robots.txt disallows this URL, refusing to fetch: {$adUrl}");
        }

        $response = Http::withUserAgent(config('scraping.user_agent'))->timeout(25)->get($adUrl);
        $response->throw();

        // JSON inside the page escapes slashes ("https:\/\/...") — undo that before matching.
        $html = str_replace('\\/', '/', $response->body());

        preg_match_all(self::IMAGE_URL, $html, $matches, PREG_SET_ORDER);

        $urls = [];

        foreach ($matches as $match) {
            $fileId = $match[1];

            if (! isset($urls[$fileId])) {
                $urls[$fileId] = 'https://'.parse_url($match[0], PHP_URL_HOST)."/v1/files/{$fileId}/image;s=1024x768";
            }

            if (count($urls) >= $max) {
                break;
            }
        }

        return array_values($urls);
    }

    /**
     * Downloads the photos into $directory as 01.jpg, 02.jpg, ... and returns
     * the file paths that were saved.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    public function download(array $urls, string $directory): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the directory {$directory}");
        }

        $saved = [];

        foreach ($urls as $index => $url) {
            $response = Http::withUserAgent(config('scraping.user_agent'))->timeout(25)->get($url);

            if ($response->failed()) {
                continue;
            }

            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.sprintf('%02d.jpg', $index + 1);
            file_put_contents($path, $response->body());
            $saved[] = $path;
        }

        return $saved;
    }
}
