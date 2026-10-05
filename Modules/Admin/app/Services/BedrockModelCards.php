<?php

namespace Modules\Admin\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads AWS Bedrock "model card" documentation pages to enrich discovered
 * inference profiles with capabilities, context window, product id and pricing.
 *
 * AWS exposes none of this through the control-plane API, so the public docs
 * are the source of truth. Results are cached to avoid re-scraping on every call.
 */
class BedrockModelCards
{
    private const CACHE_TTL = 86400; // 24 hours

    private const DOCS_BASE = 'https://docs.aws.amazon.com/bedrock/latest/userguide/';

    /** AWS docs (CloudFront) reject requests without a browser-like User-Agent. */
    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
        'Accept' => 'text/html',
    ];

    /**
     * Vendor prefixes used in AWS card slugs (e.g. "mistral-ai-devstral-...").
     * Bifrost model ids use a shorter provider token ("mistral."), so cards are
     * also indexed with the vendor prefix stripped. Multi-token vendors are
     * listed before their single-token forms so the longer prefix wins.
     */
    private const CARD_VENDOR_PREFIXES = [
        'mistral-ai', 'stability-ai', 'moonshot-ai', 'ai21-labs',
        'amazon', 'anthropic', 'cohere', 'meta', 'ai21', 'stability',
        'deepseek', 'twelvelabs', 'writer', 'nvidia', 'openai', 'qwen',
        'google', 'minimax', 'moonshotai', 'luma', 'zai', 'xai',
    ];

    private function indexUrl(): string
    {
        $configured = (string) config('services.bedrock.model_cards_url');

        return $configured !== '' ? $configured : self::DOCS_BASE.'model-cards.html';
    }

    /**
     * Resolve card metadata for each model id. Returns a map keyed by model id;
     * values are the parsed detail array, or null when no card could be matched.
     *
     * @param  array<int, string>  $modelIds
     * @return array<string, array<string, mixed>|null>
     */
    public function detailsFor(array $modelIds): array
    {
        $index = $this->cardIndex();

        $urlByModel = [];
        foreach ($modelIds as $id) {
            $url = $this->resolveCardUrl($id, $index);
            if ($url !== null) {
                $urlByModel[$id] = $url;
            }
        }

        $pages = $this->fetchCards(array_values(array_unique($urlByModel)));

        $out = [];
        foreach ($modelIds as $id) {
            $url = $urlByModel[$id] ?? null;
            $html = $url !== null ? ($pages[$url] ?? '') : '';
            $out[$id] = ($url !== null && $html !== '')
                ? $this->parseCard($html, $id) + ['card_url' => $url]
                : null;
        }

        return $out;
    }

    /**
     * Build a map of normalized model key => absolute card URL from the index page.
     *
     * @return array<string, string>
     */
    private function cardIndex(): array
    {
        return Cache::remember('bedrock:model-card-index', self::CACHE_TTL, function (): array {
            try {
                $resp = Http::withHeaders(self::HEADERS)->timeout(15)->get($this->indexUrl());
                $body = $resp->ok() ? $resp->body() : '';
            } catch (\Throwable) {
                $body = '';
            }

            $cards = [];
            if (preg_match_all('/model-card-([a-z0-9-]+)\.(?:html|md)/i', $body, $matches)) {
                foreach ($matches[1] as $slug) {
                    $slug = strtolower($slug);
                    $url = self::DOCS_BASE.'model-card-'.$slug.'.html';
                    $cards[$this->normalize($slug)] = $url;

                    // Also index without the leading vendor segment so provider-short
                    // ids (e.g. "mistral.devstral-2-123b") match vendor-prefixed cards
                    // (e.g. "mistral-ai-devstral-2-123b").
                    foreach (self::CARD_VENDOR_PREFIXES as $vendor) {
                        if (str_starts_with($slug, $vendor.'-')) {
                            $key = $this->normalize(substr($slug, strlen($vendor) + 1));
                            if ($key !== '' && ! isset($cards[$key])) {
                                $cards[$key] = $url;
                            }
                            break;
                        }
                    }
                }
            }

            return $cards;
        });
    }

    /**
     * @param  array<string, string>  $index
     */
    private function resolveCardUrl(string $modelId, array $index): ?string
    {
        $base = (string) preg_replace('/^(global|us|ca|eu|apac)\./i', '', $modelId);
        $norm = $this->normalize($base);
        $name = $this->normalize(str_contains($base, '.') ? substr($base, strpos($base, '.') + 1) : $base);

        if ($norm === '' && $name === '') {
            return null;
        }

        // Exact match on the full id or the provider-stripped model name.
        foreach ([$norm, $name] as $candidate) {
            if ($candidate !== '' && isset($index[$candidate])) {
                return $index[$candidate];
            }
        }

        // Longest card key that is a prefix of the full id or the model name
        // (handles trailing -v1:0 etc. and vendor-prefixed card slugs).
        $best = null;
        $bestLen = 0;
        foreach ($index as $key => $url) {
            if ($key === '') {
                continue;
            }
            foreach ([$norm, $name] as $candidate) {
                if ($candidate !== '' && str_starts_with($candidate, $key) && strlen($key) > $bestLen) {
                    $best = $url;
                    $bestLen = strlen($key);
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        // Fallback: the model id (or name) is a prefix of a card key.
        foreach ($index as $key => $url) {
            if (($norm !== '' && str_starts_with($key, $norm)) || ($name !== '' && str_starts_with($key, $name))) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Fetch card pages, caching each URL individually and requesting misses concurrently.
     *
     * @param  array<int, string>  $urls
     * @return array<string, string>
     */
    private function fetchCards(array $urls): array
    {
        $result = [];
        $missing = [];
        foreach ($urls as $url) {
            $cached = Cache::get($this->cacheKey($url));
            if ($cached !== null) {
                $result[$url] = $cached;
            } else {
                $missing[] = $url;
            }
        }

        foreach (array_chunk($missing, 10) as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn (string $url) => $pool->as($url)->withHeaders(self::HEADERS)->timeout(15)->get($url),
                $chunk,
            ));

            foreach ($chunk as $url) {
                $resp = $responses[$url] ?? null;
                $body = $resp instanceof Response && $resp->ok() ? $resp->body() : '';
                Cache::put($this->cacheKey($url), $body, self::CACHE_TTL);
                $result[$url] = $body;
            }
        }

        return $result;
    }

    /**
     * Extract the fields we care about from a card's HTML.
     *
     * @return array<string, mixed>
     */
    private function parseCard(string $html, string $modelId): array
    {
        $text = $this->toText($html);

        return [
            'capabilities' => $this->parseCapabilities($text, $modelId),
            'context_window' => $this->parseTokenCount($text, 'Context window'),
            'max_output_tokens' => $this->parseTokenCount($text, 'Max(?:imum)? output tokens'),
            'product_id' => $this->parseProductId($text),
            'bedrock_runtime' => $this->parseRuntimeSupported($text),
            'regions' => $this->parseRegionalAvailability($text),
        ] + $this->parseInRegionPricing($text);
    }

    /**
     * Flatten HTML into text while preserving table cells, rows and yes/no icons.
     */
    private function toText(string $html): string
    {
        $html = preg_replace('/<img[^>]*icon-yes[^>]*>/i', ' [YES] ', $html);
        $html = preg_replace('/<img[^>]*icon-no[^>]*>/i', ' [NO] ', $html);
        $html = preg_replace('#</(?:td|th)>#i', ' | ', $html);
        $html = preg_replace('#</tr>#i', "\n", $html);
        $html = preg_replace('#</(?:p|li|h[1-6]|div|section)>#i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function parseCapabilities(string $text, string $modelId): array
    {
        $isEmbedding = stripos($modelId, 'embed') !== false
            || preg_match('/\[YES\]\s*Embedding/i', $text) === 1;

        if ($isEmbedding) {
            return ['embeddings'];
        }

        $capabilities = ['chat'];
        if (preg_match('/tool (?:use|calling)/i', $text) === 1) {
            $capabilities[] = 'tool_use';
        }
        if (preg_match('/structured output/i', $text) === 1) {
            $capabilities[] = 'structured_output';
        }

        return $capabilities;
    }

    private function parseTokenCount(string $text, string $labelPattern): ?int
    {
        if (preg_match('/'.$labelPattern.':\s*\|?\s*([\d.,]+)\s*([MK]?)(?:\s*tokens)?/i', $text, $m) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '', $m[1]);
        $unit = strtoupper($m[2] ?? '');

        return (int) round(match ($unit) {
            'M' => $value * 1_000_000,
            'K' => $value * 1_000,
            default => $value,
        });
    }

    private function parseProductId(string $text): ?string
    {
        return preg_match('/Marketplace product ID:\s*\|?\s*(prod-[a-z0-9]+)/i', $text, $m) === 1
            ? $m[1]
            : null;
    }

    private function parseRuntimeSupported(string $text): ?bool
    {
        if (preg_match('/bedrock-runtime\s*\|\s*\[(YES|NO)\]/i', $text, $m) === 1) {
            return strtoupper($m[1]) === 'YES';
        }

        return null;
    }

    /**
     * Parse the "Regional Availability" table into a map of region id => the
     * three inference options (In-Region, Geo Cross-Region, Global Cross-Region).
     * Rows in the flattened text look like:
     *   "ca-central-1 (Canada Central) | [YES] | [NO] | [NO]".
     *
     * @return array<string, array{in_region: bool, geo: bool, global: bool}>
     */
    private function parseRegionalAvailability(string $text): array
    {
        $regions = [];

        $pattern = '/\b((?:us-gov|[a-z]{2})-[a-z]+-\d+)\b[^\n|]*\|\s*\[(YES|NO)\]\s*\|\s*\[(YES|NO)\]\s*\|\s*\[(YES|NO)\]/i';
        if (preg_match_all($pattern, $text, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $regions[strtolower($row[1])] = [
                    'in_region' => strtoupper($row[2]) === 'YES',
                    'geo' => strtoupper($row[3]) === 'YES',
                    'global' => strtoupper($row[4]) === 'YES',
                ];
            }
        }

        return $regions;
    }

    /**
     * Report, per model id, whether a given AWS region is available and via which
     * inference options, using the parsed model-card Regional Availability table.
     *
     * `supported` is true when any option (in-region/geo/global) is offered for
     * the region, false when the card lists regions but not this one, and null
     * when no card or no availability table could be resolved (unknown).
     *
     * @param  array<int, string>  $modelIds
     * @return array<string, array{supported: bool|null, in_region: bool, geo: bool, global: bool, card_url: ?string}>
     */
    public function regionSupportFor(array $modelIds, string $region): array
    {
        $details = $this->detailsFor($modelIds);
        $region = strtolower($region);

        $out = [];
        foreach ($modelIds as $id) {
            $card = $details[$id] ?? null;
            $regions = is_array($card) ? ($card['regions'] ?? []) : [];
            $row = $regions[$region] ?? null;

            $supported = match (true) {
                $row !== null => $row['in_region'] || $row['geo'] || $row['global'],
                $regions !== [] => false,
                default => null,
            };

            $out[$id] = [
                'supported' => $supported,
                'in_region' => (bool) ($row['in_region'] ?? false),
                'geo' => (bool) ($row['geo'] ?? false),
                'global' => (bool) ($row['global'] ?? false),
                'card_url' => is_array($card) ? ($card['card_url'] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{input_cost: float|null, output_cost: float|null}
     */
    private function parseInRegionPricing(string $text): array
    {
        if (preg_match_all('/In-Region\s*\|([^\n]*)/i', $text, $rows)) {
            foreach ($rows[1] as $row) {
                if (preg_match_all('/\$\s*([\d.,]+)/', $row, $mm)) {
                    $values = array_map(fn ($v) => (float) str_replace(',', '', $v), $mm[1]);

                    // Input is the first column by AWS convention; output is the highest
                    // price in the row (cache-read/write columns are always cheaper).
                    return [
                        'input_cost' => $values[0],
                        'output_cost' => max($values),
                    ];
                }
            }
        }

        return ['input_cost' => null, 'output_cost' => null];
    }

    private function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/v(\d)/', '$1', $value);

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    private function cacheKey(string $url): string
    {
        return 'bedrock:model-card:'.md5($url);
    }
}
