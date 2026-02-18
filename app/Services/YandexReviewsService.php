<?php

namespace App\Services;

use App\Models\Review;
use App\Models\YandexSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;

class YandexReviewsService
{
    private CookieJar $cookieJar;
    private string $userAgent;
    private ?string $cachedCsrfToken = null;
    private ?string $cachedHtml = null;
    private ?string $currentProxy = null;
    private int $proxyIndex = 0;
    private string $baseDomain = 'yandex.ru';
    private float $fetchStartedAt = 0;
    private ?int $workingPaginationVariant = null;
    private ?string $secChUa = null;
    private ?string $secChUaPlatform = null;
    private ?string $cachedSessionId = null;
    private ?string $cachedReqId = null;

    private const USER_AGENTS = [
        // Chrome 143 — Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
        // Chrome 143 — macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
        // Firefox 147 — Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0',
        // Safari 18.4 — macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15',
        // Chrome 143 — Linux
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
    ];

    // Per-rating filtered queries bypass Yandex's ~600 review cap.
    // 22 pages × 50 = 1100 per rating bucket — more than enough per star group.
    private const MAX_PAGES = 22;
    private const PAGE_SIZE = 50;
    private const MAX_RETRIES = 3;

    // Hard time limit for the entire fetch operation (seconds).
    // Increased to 480s to accommodate per-rating fetch passes (up to 5 extra passes).
    private const FETCH_TIMEOUT_SECONDS = 480;

    // Delay between requests (microseconds) — helps avoid captcha
    private const PAGE_DELAY_US = 500000;        // 0.5s between pages
    private const BRANCH_DELAY_US = 2000000;     // 2s between branches
    private const CAPTCHA_BACKOFF_US = 5000000;   // 5s after captcha

    public function __construct()
    {
        $this->cookieJar = new CookieJar();
        $this->pickRandomUserAgent();
        $this->rotateProxy();
    }

    /**
     * Pick a random user agent and derive matching Sec-Ch-Ua* headers.
     */
    private function pickRandomUserAgent(): void
    {
        $index = array_rand(self::USER_AGENTS);
        $this->userAgent = self::USER_AGENTS[$index];

        // Derive Sec-Ch-Ua* headers that are consistent with the chosen UA
        if (str_contains($this->userAgent, 'Firefox')) {
            // Firefox does not send Sec-Ch-Ua headers
            $this->secChUa = null;
            $this->secChUaPlatform = null;
        } elseif (str_contains($this->userAgent, 'Safari/605')) {
            // Safari does not send Sec-Ch-Ua headers
            $this->secChUa = null;
            $this->secChUaPlatform = null;
        } elseif (str_contains($this->userAgent, 'Macintosh')) {
            $this->secChUa = '"Chromium";v="143", "Not_A Brand";v="24"';
            $this->secChUaPlatform = '"macOS"';
        } elseif (str_contains($this->userAgent, 'Linux')) {
            $this->secChUa = '"Chromium";v="143", "Not_A Brand";v="24"';
            $this->secChUaPlatform = '"Linux"';
        } else {
            $this->secChUa = '"Chromium";v="143", "Not_A Brand";v="24"';
            $this->secChUaPlatform = '"Windows"';
        }
    }

    /**
     * Get configured proxy list from env.
     * Format: YANDEX_PROXIES="http://user:pass@host:port,socks5://host:port,..."
     */
    private function getProxyList(): array
    {
        $proxies = config('services.yandex.proxies', env('YANDEX_PROXIES', ''));
        if (empty($proxies)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $proxies)));
    }

    /**
     * Rotate to next proxy from the list.
     */
    private function rotateProxy(): void
    {
        $proxies = $this->getProxyList();
        if (empty($proxies)) {
            $this->currentProxy = null;
            return;
        }
        $this->currentProxy = $proxies[$this->proxyIndex % count($proxies)];
        $this->proxyIndex++;
        Log::info('Rotated proxy', ['proxy' => preg_replace('/\/\/[^@]+@/', '//*:*@', $this->currentProxy)]);
    }

    /**
     * Detect base domain (yandex.ru or yandex.com) from a Yandex Maps URL.
     */
    private function detectDomain(string $url): void
    {
        if (preg_match('/yandex\.(com|ru)/i', $url, $m)) {
            $this->baseDomain = 'yandex.' . strtolower($m[1]);
        }
    }

    /**
     * Build a full Yandex Maps URL using the detected domain.
     */
    private function mapsUrl(string $path = ''): string
    {
        return 'https://' . $this->baseDomain . '/maps' . $path;
    }

    /**
     * Solve captcha using rucaptcha/2captcha API.
     * Supports Yandex SmartCaptcha (method=yandex) with fallback to reCAPTCHA (method=userrecaptcha).
     * Returns solved captcha token or null.
     */
    private function solveCaptcha(array $captchaData): ?string
    {
        $apiKey = config('services.yandex.captcha_api_key', env('CAPTCHA_API_KEY', ''));
        $apiUrl = config('services.yandex.captcha_api_url', env('CAPTCHA_API_URL', 'https://rucaptcha.com'));

        if (empty($apiKey)) {
            Log::warning('Captcha encountered but no CAPTCHA_API_KEY configured');
            return null;
        }

        $siteKey = $captchaData['key'] ?? $captchaData['sitekey'] ?? $captchaData['captchaKey']
            ?? $captchaData['data-sitekey'] ?? null;
        $pageUrl = $captchaData['url'] ?? $this->mapsUrl('/');

        if (!$siteKey) {
            Log::warning('Captcha sitekey not found in captcha data', ['keys' => array_keys($captchaData)]);
            return null;
        }

        // Detect captcha type: Yandex SmartCaptcha or Google reCAPTCHA
        $isYandexCaptcha = isset($captchaData['captchaType']) && str_contains($captchaData['captchaType'], 'smart')
            || isset($captchaData['type']) && in_array($captchaData['type'], ['smartCaptcha', 'smart_captcha', 'smart'])
            || str_contains($pageUrl, 'yandex');

        if ($isYandexCaptcha) {
            $method = 'yandex';
            $captchaParams = [
                'key' => $apiKey,
                'method' => 'yandex',
                'sitekey' => $siteKey,
                'pageurl' => $pageUrl,
                'json' => 1,
            ];
        } else {
            $method = 'userrecaptcha';
            $captchaParams = [
                'key' => $apiKey,
                'method' => 'userrecaptcha',
                'googlekey' => $siteKey,
                'pageurl' => $pageUrl,
                'json' => 1,
            ];
        }

        Log::info('Submitting captcha to solving service', [
            'method' => $method,
            'siteKey' => substr($siteKey, 0, 20) . '...',
        ]);

        try {
            // Submit captcha
            $submitResponse = Http::timeout(30)->post("{$apiUrl}/in.php", $captchaParams);

            $submitData = $submitResponse->json();
            if (($submitData['status'] ?? 0) !== 1) {
                Log::warning('Captcha submit failed', ['response' => $submitData]);
                return null;
            }

            $taskId = $submitData['request'];
            Log::info('Captcha submitted', ['taskId' => $taskId]);

            // Poll for result with respect to overall fetch timeout.
            $pollIntervalSeconds = 5;
            $remainingFetchSeconds = self::FETCH_TIMEOUT_SECONDS;
            if ($this->fetchStartedAt > 0) {
                $elapsed = (int) floor(microtime(true) - $this->fetchStartedAt);
                $remainingFetchSeconds = max(0, self::FETCH_TIMEOUT_SECONDS - $elapsed);
            }
            $maxWaitSeconds = min(120, $remainingFetchSeconds);
            $maxPolls = max(1, (int) floor($maxWaitSeconds / $pollIntervalSeconds));

            for ($i = 0; $i < $maxPolls; $i++) {
                if ($this->isTimedOut()) {
                    Log::warning('Stopping captcha polling due to overall fetch timeout');
                    return null;
                }

                sleep($pollIntervalSeconds);

                $resultResponse = Http::timeout(15)->get("{$apiUrl}/res.php", [
                    'key' => $apiKey,
                    'action' => 'get',
                    'id' => $taskId,
                    'json' => 1,
                ]);

                $resultData = $resultResponse->json();
                if (($resultData['status'] ?? 0) === 1) {
                    Log::info('Captcha solved successfully');
                    return $resultData['request'];
                }

                if (($resultData['request'] ?? '') !== 'CAPCHA_NOT_READY') {
                    Log::warning('Captcha solving failed', ['response' => $resultData]);
                    return null;
                }
            }

            Log::warning('Captcha solving timed out');
        } catch (\Exception $e) {
            Log::warning('Captcha solving error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Parse organization ID from Yandex Maps URL.
     * Supports multiple URL formats:
     *   - https://yandex.ru/maps/org/name/1234567890/reviews/
     *   - https://yandex.com/maps/org/name/1234567890/
     *   - https://yandex.ru/maps/-/abc123?oid=1234567890
     *   - https://yandex.ru/maps/org/1234567890/
     *   - Mobile: https://m.yandex.ru/maps/org/name/1234567890/
     */
    public function parseOrganizationId(string $url): ?string
    {
        // Normalize: trim whitespace, remove trailing fragments
        $url = trim($url);

        // Format: /org/name/1234567890 or /org/1234567890
        if (preg_match('/\/org\/(?:[^\/]+\/)?(\d{5,})/', $url, $matches)) {
            return $matches[1];
        }

        // Format: oid=1234567890 in query string
        if (preg_match('/[?&]oid=(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        // Format: ll= with oid in hash or similar
        if (preg_match('/oid=(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract human-readable organization name from URL slug.
     */
    public function parseOrganizationSlug(string $url): ?string
    {
        if (preg_match('/\/org\/([^\/]+)\/\d+/', $url, $matches)) {
            $slug = $matches[1];
            // Decode URL-encoded characters
            $slug = urldecode($slug);
            $name = str_replace(['_', '-'], ' ', $slug);
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            return $name;
        }
        return null;
    }

    /**
     * Fetch reviews from Yandex Maps using session-based approach.
     *
     * Strategy:
     * 1. Initialize session by visiting the org page (sets cookies, extracts embedded data)
     * 2. Try Yandex internal API with session cookies + CSRF token (with pagination)
     * 3. Parse HTML from initial page load for embedded reviews
     * 4. Fallback to demo data
     */
    /**
     * Check if the overall fetch operation has exceeded the time limit.
     */
    private function isTimedOut(): bool
    {
        return $this->fetchStartedAt > 0
            && (microtime(true) - $this->fetchStartedAt) >= self::FETCH_TIMEOUT_SECONDS;
    }

    public function fetchReviews(YandexSource $source): array
    {
        $this->fetchStartedAt = microtime(true);

        $orgId = $this->parseOrganizationId($source->url);

        if (!$orgId) {
            throw new \Exception('Не удалось определить ID организации из ссылки');
        }

        // Detect yandex.ru or yandex.com from the user's URL
        $this->detectDomain($source->url);

        Log::info('Starting review fetch', ['orgId' => $orgId, 'url' => $source->url, 'domain' => $this->baseDomain]);

        // Step 1: Initialize session - visit org page to get cookies + embedded data
        $embeddedData = $this->initializeSession($source->url, $orgId);

        // Step 2: Try Yandex API with established session cookies (supports pagination)
        $apiResult = $this->fetchReviewsViaApi($orgId, $source->url);
        if ($apiResult && !empty($apiResult['reviews'])) {
            Log::info('Got reviews from Yandex API', ['count' => count($apiResult['reviews'])]);
            // Merge organization info from embedded data if API didn't return it
            if (empty($apiResult['organization_name']) && !empty($embeddedData['organization_name'])) {
                $apiResult['organization_name'] = $embeddedData['organization_name'];
            }
            if (empty($apiResult['rating']) && !empty($embeddedData['rating'])) {
                $apiResult['rating'] = $embeddedData['rating'];
            }

            // Merge embedded HTML reviews with API reviews to maximize coverage
            if (!empty($embeddedData['reviews'])) {
                $apiResult = $this->mergeReviewSets($apiResult, $embeddedData['reviews']);
            }

            return $apiResult;
        }

        // Step 3: Use embedded data from HTML if we got reviews from it
        if (!empty($embeddedData['reviews'])) {
            Log::info('Using reviews from embedded page data', ['count' => count($embeddedData['reviews'])]);
            return $embeddedData;
        }

        // Step 4: Try parsing HTML DOM as last resort
        if ($this->cachedHtml) {
            try {
                $htmlResult = $this->parseHtmlDom($this->cachedHtml, $orgId);
                if (!empty($htmlResult['reviews'])) {
                    Log::info('Got reviews from HTML DOM parsing', ['count' => count($htmlResult['reviews'])]);
                    return $htmlResult;
                }
            } catch (\Exception $e) {
                Log::warning('HTML DOM parsing failed: ' . $e->getMessage());
            }
        }

        Log::error('All scraping strategies failed', ['orgId' => $orgId, 'domain' => $this->baseDomain]);

        throw new \Exception('Не удалось загрузить отзывы. Все стратегии получения данных не сработали. Попробуйте позже или проверьте ссылку.');
    }

    /**
     * Initialize session by visiting the organization page.
     * Sets cookies in the cookie jar and extracts embedded review data from HTML.
     */
    private function initializeSession(string $url, string $orgId): array
    {
        $result = [
            'organization_name' => null,
            'rating' => null,
            'total_reviews' => 0,
            'reviews' => [],
        ];

        // Ensure URL points to reviews tab
        $reviewsUrl = $url;
        if (!str_contains($url, '/reviews')) {
            $reviewsUrl = rtrim($url, '/') . '/reviews/';
        }

        Log::info('Initializing session', ['url' => $reviewsUrl]);

        $sessionHeaders = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];
        // Only Chrome sends Sec-Ch-Ua headers; Firefox and Safari do not
        if ($this->secChUa) {
            $sessionHeaders['Sec-Ch-Ua'] = $this->secChUa;
            $sessionHeaders['Sec-Ch-Ua-Mobile'] = '?0';
            $sessionHeaders['Sec-Ch-Ua-Platform'] = $this->secChUaPlatform;
        }

        // Retry session init up to MAX_RETRIES times
        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpGet($reviewsUrl, [], $sessionHeaders, 30);

                if ($response && $response->successful()) {
                    break;
                }

                Log::warning("Session init attempt {$attempt} failed", [
                    'status' => $response ? $response->status() : 'null',
                ]);
            } catch (\Exception $e) {
                Log::warning("Session init attempt {$attempt} error: " . $e->getMessage());
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep(1000000 * $attempt); // 1s, 2s backoff
            }
            $response = null;
        }

        if (!$response || !$response->successful()) {
            Log::warning('Session init failed after all retries');
            return $result;
        }

        try {
            $html = $response->body();
            $this->cachedHtml = $html;

            Log::info('Session initialized', [
                'status' => $response->status(),
                'body_length' => strlen($html),
                'cookies_count' => count($this->cookieJar->toArray()),
            ]);

            // Try to extract CSRF token from page HTML (multiple patterns)
            $csrfPatterns = [
                '/"csrfToken"\s*:\s*"([^"]+)"/',
                '/"csrf-token"\s*:\s*"([^"]+)"/',
                '/name="csrf-token"\s+content="([^"]+)"/',
            ];
            foreach ($csrfPatterns as $pattern) {
                if (preg_match($pattern, $html, $csrfMatch)) {
                    $this->cachedCsrfToken = $csrfMatch[1];
                    Log::info('Extracted CSRF token from page HTML');
                    break;
                }
            }

            // Extract sessionId from HTML (required for API calls)
            $sessionIdPatterns = [
                '/"sessionId"\s*:\s*"([^"]+)"/',
                '/sessionId[=:]([a-zA-Z0-9_-]+)/',
                '/"sid"\s*:\s*"([^"]+)"/',
            ];
            foreach ($sessionIdPatterns as $pattern) {
                if (preg_match($pattern, $html, $sidMatch)) {
                    $this->cachedSessionId = $sidMatch[1];
                    Log::info('Extracted sessionId from page HTML');
                    break;
                }
            }

            // Extract reqId from HTML (required for API calls)
            $reqIdPatterns = [
                '/"reqId"\s*:\s*"([^"]+)"/',
                '/"requestId"\s*:\s*"([^"]+)"/',
                '/reqId[=:]([a-zA-Z0-9_.-]+)/',
            ];
            foreach ($reqIdPatterns as $pattern) {
                if (preg_match($pattern, $html, $reqMatch)) {
                    $this->cachedReqId = $reqMatch[1];
                    Log::info('Extracted reqId from page HTML');
                    break;
                }
            }

            // Extract embedded data from the HTML
            $result = $this->extractFromHtml($html, $orgId);

        } catch (\Exception $e) {
            Log::warning('Session data extraction error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Get CSRF token from Yandex Maps API (using session cookies).
     */
    private function getCsrfToken(): ?string
    {
        if ($this->cachedCsrfToken) {
            return $this->cachedCsrfToken;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpGet($this->mapsUrl('/api/csrf-token'), [], [
                    'Accept' => '*/*',
                    'Referer' => $this->mapsUrl('/'),
                    'Sec-Fetch-Dest' => 'empty',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Site' => 'same-origin',
                    'X-Requested-With' => 'XMLHttpRequest',
                ], 10);

                if ($response && $response->successful()) {
                    $body = trim($response->body());
                    // Token might be returned as plain text or as JSON
                    if ($body && strlen($body) < 200) {
                        $json = json_decode($body, true);
                        $token = $json['token'] ?? $json['csrfToken'] ?? $body;
                        $this->cachedCsrfToken = $token;
                        Log::info('Got CSRF token from API', ['length' => strlen($token), 'attempt' => $attempt]);
                        return $token;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("CSRF token attempt {$attempt} failed: " . $e->getMessage());
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep(500000 * $attempt); // 0.5s, 1s, 1.5s backoff
            }
        }

        Log::warning('Failed to get CSRF token after all retries');
        return null;
    }

    /**
     * Fetch reviews via Yandex internal API with session cookies and pagination.
     * Tries ALL endpoints with multiple sort orders and merges results to maximize coverage.
     */
    private function fetchReviewsViaApi(string $orgId, ?string $sourceUrl = null): ?array
    {
        $csrfToken = $this->getCsrfToken();
        if (!$csrfToken) {
            Log::warning('No CSRF token available, skipping API strategy');
            return null;
        }

        // Try multiple API endpoints (using detected domain)
        $base = 'https://' . $this->baseDomain;
        $endpoints = [
            $base . '/maps/api/business/fetchReviews',
            $base . '/maps/api/business/getBusinessReviews',
            $base . '/maps-reviews-widget/fetchReviews',
        ];

        // Sort orders to try — different sorts may expose different reviews
        $sortOrders = ['by_time', 'by_rating', 'by_relevance'];

        $mergedResult = null;
        $allSeenIds = [];
        $allSeenFingerprints = [];

        foreach ($endpoints as $endpoint) {
            if ($this->isTimedOut()) break;

            // Reset cached pagination variant — different endpoints may use different schemes
            $this->workingPaginationVariant = null;

            foreach ($sortOrders as $sortOrder) {
                if ($this->isTimedOut()) break;

                $result = $this->fetchFromEndpoint($endpoint, $orgId, $csrfToken, $sortOrder);

                if (!$result || empty($result['reviews'])) {
                    continue;
                }

                Log::info('Got reviews from endpoint', [
                    'endpoint' => basename($endpoint),
                    'sort' => $sortOrder,
                    'count' => count($result['reviews']),
                ]);

                if (!$mergedResult) {
                    // First successful result — use as base
                    $mergedResult = $result;
                    foreach ($mergedResult['reviews'] as $review) {
                        $id = $review['yandex_id'] ?? null;
                        if ($id) {
                            $allSeenIds[$id] = true;
                        }
                        $fp = $this->reviewFingerprint($review);
                        if ($fp) {
                            $allSeenFingerprints[$fp] = true;
                        }
                    }
                } else {
                    // Merge additional results — add only new unique reviews
                    $added = 0;
                    foreach ($result['reviews'] as $review) {
                        $id = $review['yandex_id'] ?? null;
                        if ($id && isset($allSeenIds[$id])) {
                            continue;
                        }
                        $fp = $this->reviewFingerprint($review);
                        if ($fp && isset($allSeenFingerprints[$fp])) {
                            continue;
                        }
                        if ($id) {
                            $allSeenIds[$id] = true;
                        }
                        if ($fp) {
                            $allSeenFingerprints[$fp] = true;
                        }
                        $mergedResult['reviews'][] = $review;
                        $added++;
                    }
                    // Update org info if better data found
                    if (empty($mergedResult['organization_name']) && !empty($result['organization_name'])) {
                        $mergedResult['organization_name'] = $result['organization_name'];
                    }
                    if (empty($mergedResult['rating']) && !empty($result['rating'])) {
                        $mergedResult['rating'] = $result['rating'];
                    }
                    if ($result['total_reviews'] > $mergedResult['total_reviews']) {
                        $mergedResult['total_reviews'] = $result['total_reviews'];
                    }
                    if ($added > 0) {
                        Log::info("Merged {$added} additional reviews from endpoint", [
                            'endpoint' => basename($endpoint),
                            'sort' => $sortOrder,
                            'total_after_merge' => count($mergedResult['reviews']),
                        ]);
                    }
                }

                // If we already have all reviews, no need to try more sort orders on this endpoint
                if ($mergedResult && $mergedResult['total_reviews'] > 0
                    && count($mergedResult['reviews']) >= $mergedResult['total_reviews']) {
                    Log::info('Already fetched all reviews, skipping remaining sort orders');
                    break;
                }
            }

            // If all reviews fetched, skip remaining endpoints
            if ($mergedResult && $mergedResult['total_reviews'] > 0
                && count($mergedResult['reviews']) >= $mergedResult['total_reviews']) {
                break;
            }
        }

        // Per-rating fetch: if we still have fewer reviews than totalCount, try fetching
        // reviews filtered by each star rating (1-5) separately.
        // Yandex caps unfiltered results at ~600 — per-rating queries bypass this limit.
        if (!$this->isTimedOut() && $mergedResult && $mergedResult['total_reviews'] > 0
            && count($mergedResult['reviews']) < $mergedResult['total_reviews']) {

            $gap = $mergedResult['total_reviews'] - count($mergedResult['reviews']);
            Log::info('Starting per-rating fetch to fill gap', [
                'fetched' => count($mergedResult['reviews']),
                'total' => $mergedResult['total_reviews'],
                'gap' => $gap,
            ]);

            for ($stars = 1; $stars <= 5; $stars++) {
                if ($this->isTimedOut()) break;

                // Already have all reviews — stop
                if (count($mergedResult['reviews']) >= $mergedResult['total_reviews']) break;

                // Reset pagination variant — filtered queries may use different scheme
                $this->workingPaginationVariant = null;

                $csrfForRating = $this->cachedCsrfToken ?? $csrfToken;
                $result = $this->fetchFromEndpoint($endpoints[0], $orgId, $csrfForRating, 'by_time', $stars);

                if (!$result || empty($result['reviews'])) {
                    continue;
                }

                $added = 0;
                foreach ($result['reviews'] as $review) {
                    $id = $review['yandex_id'] ?? null;
                    if ($id && isset($allSeenIds[$id])) {
                        continue;
                    }
                    $fp = $this->reviewFingerprint($review);
                    if ($fp && isset($allSeenFingerprints[$fp])) {
                        continue;
                    }
                    if ($id) {
                        $allSeenIds[$id] = true;
                    }
                    if ($fp) {
                        $allSeenFingerprints[$fp] = true;
                    }
                    $mergedResult['reviews'][] = $review;
                    $added++;
                }

                if ($added > 0) {
                    Log::info("Per-rating fetch ({$stars} stars) added {$added} reviews", [
                        'total_now' => count($mergedResult['reviews']),
                        'total_expected' => $mergedResult['total_reviews'],
                    ]);
                }

                // Brief delay between rating groups
                usleep(self::BRANCH_DELAY_US);
            }

            Log::info('Per-rating fetch complete', [
                'total_fetched' => count($mergedResult['reviews']),
                'total_expected' => $mergedResult['total_reviews'],
            ]);
        }

        return $mergedResult;
    }

    /**
     * Merge additional reviews into a result set, deduplicating by yandex_id
     * and by content fingerprint for reviews without an ID.
     */
    private function mergeReviewSets(array $primary, array $additionalReviews): array
    {
        $seenIds = [];
        $seenFingerprints = [];

        foreach ($primary['reviews'] as $review) {
            $id = $review['yandex_id'] ?? null;
            if ($id) {
                $seenIds[$id] = true;
            }
            // Build content fingerprint for reviews without ID
            $fingerprint = $this->reviewFingerprint($review);
            if ($fingerprint) {
                $seenFingerprints[$fingerprint] = true;
            }
        }

        $added = 0;
        foreach ($additionalReviews as $review) {
            $id = $review['yandex_id'] ?? null;
            if ($id && isset($seenIds[$id])) {
                continue;
            }

            // Content-based dedup for reviews without yandex_id
            $fingerprint = $this->reviewFingerprint($review);
            if ($fingerprint && isset($seenFingerprints[$fingerprint])) {
                continue;
            }

            if ($id) {
                $seenIds[$id] = true;
            }
            if ($fingerprint) {
                $seenFingerprints[$fingerprint] = true;
            }

            $primary['reviews'][] = $review;
            $added++;
        }

        if ($added > 0) {
            Log::info("Merged {$added} additional reviews from embedded data", [
                'total_after_merge' => count($primary['reviews']),
            ]);
        }

        return $primary;
    }

    /**
     * Generate a fingerprint for a review based on author + text for content-based dedup.
     */
    private function reviewFingerprint(array $review): ?string
    {
        $author = trim($review['author_name'] ?? '');
        $text = trim($review['text'] ?? '');
        if (!$author && !$text) {
            return null;
        }
        return md5(mb_strtolower($author) . '|' . mb_strtolower($text));
    }

    /**
     * Fetch reviews from a specific API endpoint with full pagination.
     * Fetches ALL available pages with resilient stopping logic.
     */
    private function fetchFromEndpoint(string $endpoint, string $orgId, string $csrfToken, string $sortOrder = 'by_time', ?int $ratingFilter = null): ?array
    {
        $allReviews = [];
        $seenIds = [];
        $seenFingerprints = [];
        $organizationName = null;
        $rating = null;
        $totalCount = 0;
        $captchaRetries = 0;
        $maxCaptchaRetries = 5;
        $consecutiveDuplicatePages = 0;
        $consecutiveNullResponses = 0;  // No API response at all
        $consecutiveEmptyPages = 0;     // Response OK but no reviews parsed

        $endpointName = basename($endpoint);
        Log::info("Trying API endpoint: {$endpointName}", ['orgId' => $orgId, 'sort' => $sortOrder, 'ratingFilter' => $ratingFilter]);

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            // Check overall time limit
            if ($this->isTimedOut()) {
                Log::warning("Fetch timeout reached during pagination", [
                    'endpoint' => $endpointName,
                    'page' => $page,
                    'fetched' => count($allReviews),
                ]);
                break;
            }

            $data = $this->callReviewsApi($endpoint, $orgId, $csrfToken, $page, null, $sortOrder, $ratingFilter);

            if (!$data) {
                if ($page === 1) {
                    Log::info("Endpoint {$endpointName} returned no data on first page");
                    return null;
                }
                // Allow a few null responses before giving up (network glitches)
                $consecutiveNullResponses++;
                $nullTolerance = ($totalCount > 0 && count($allReviews) < $totalCount) ? 4 : 2;
                if ($consecutiveNullResponses >= $nullTolerance) {
                    Log::info("Stopping: {$consecutiveNullResponses} consecutive null responses");
                    break;
                }
                usleep(self::PAGE_DELAY_US);
                continue;
            }

            $consecutiveNullResponses = 0;

            // Handle captcha: try solving, rotate proxy, retry
            if (isset($data['captchaRequired']) || (isset($data['type']) && $data['type'] === 'captcha')) {
                Log::warning("Captcha required on endpoint {$endpointName}, page {$page}");

                if ($captchaRetries >= $maxCaptchaRetries || $this->isTimedOut()) {
                    Log::error("Stopping captcha retries", [
                        'retries' => $captchaRetries,
                        'timed_out' => $this->isTimedOut(),
                        'page' => $page,
                    ]);
                    break;
                }
                $captchaRetries++;

                // Try to solve captcha
                $solved = $this->solveCaptcha($data);
                if ($solved) {
                    // Retry with solved captcha token
                    $data = $this->callReviewsApi($endpoint, $orgId, $csrfToken, $page, $solved, $sortOrder, $ratingFilter);
                    if (!$data || isset($data['captchaRequired'])) {
                        $this->rotateProxy();
                        $this->resetSession();
                        usleep(self::CAPTCHA_BACKOFF_US);
                        $csrfToken = $this->getCsrfToken() ?? $csrfToken;
                        $page--; // Retry same page
                        continue;
                    }
                } else {
                    // No captcha solver — rotate proxy and retry
                    $this->rotateProxy();
                    $this->resetSession();
                    usleep(self::CAPTCHA_BACKOFF_US);
                    $csrfToken = $this->getCsrfToken() ?? $csrfToken;
                    $page--; // Retry same page
                    continue;
                }
            }

            $parsed = $this->parseApiResponse($data);

            // Extract org info from first successful page
            if ($page === 1 || (!$organizationName && $parsed['organization_name'])) {
                $organizationName = $parsed['organization_name'] ?? $organizationName;
                $rating = $parsed['rating'] ?? $rating;
                if ($parsed['total_reviews'] > $totalCount) {
                    $totalCount = $parsed['total_reviews'];
                }

                if ($totalCount > 0 && $page === 1) {
                    Log::info("API reports {$totalCount} total reviews", ['endpoint' => $endpointName]);
                }
            }

            // Update totalCount if a larger value is reported on subsequent pages
            if ($parsed['total_reviews'] > $totalCount) {
                $totalCount = $parsed['total_reviews'];
                Log::info("Updated total count from page {$page}", ['total' => $totalCount]);
            }

            if (empty($parsed['reviews'])) {
                $consecutiveEmptyPages++;
                // If we know there are more reviews, tolerate more empty pages
                $emptyTolerance = ($totalCount > 0 && count($allReviews) < $totalCount) ? 4 : 2;
                if ($consecutiveEmptyPages < $emptyTolerance) {
                    Log::info("Empty page {$page}, tolerating ({$consecutiveEmptyPages}/{$emptyTolerance})", [
                        'fetched' => count($allReviews),
                        'total' => $totalCount,
                    ]);
                    usleep(self::PAGE_DELAY_US);
                    continue;
                }
                break;
            }

            $consecutiveEmptyPages = 0;

            // Deduplicate: skip reviews we've already seen (by ID and by fingerprint)
            $newReviews = [];
            foreach ($parsed['reviews'] as $review) {
                $id = $review['yandex_id'] ?? null;
                if ($id && isset($seenIds[$id])) {
                    continue;
                }
                // Content-based dedup for reviews without unique ID
                $fp = $this->reviewFingerprint($review);
                if ($fp && isset($seenFingerprints[$fp])) {
                    continue;
                }
                if ($id) {
                    $seenIds[$id] = true;
                }
                if ($fp) {
                    $seenFingerprints[$fp] = true;
                }
                $newReviews[] = $review;
            }

            // If all reviews on this page are duplicates — tolerate a few before stopping
            if (empty($newReviews) && !empty($parsed['reviews'])) {
                $consecutiveDuplicatePages++;
                // More tolerance if we haven't reached totalCount yet
                $dupTolerance = ($totalCount > 0 && count($allReviews) < $totalCount) ? 3 : 2;
                Log::warning("Page {$page} returned only duplicate reviews ({$consecutiveDuplicatePages}/{$dupTolerance} consecutive)", [
                    'endpoint' => $endpointName,
                    'duplicates' => count($parsed['reviews']),
                    'fetched' => count($allReviews),
                    'total' => $totalCount,
                ]);
                if ($consecutiveDuplicatePages >= $dupTolerance) {
                    break;
                }
                usleep(self::PAGE_DELAY_US);
                continue;
            }

            $consecutiveDuplicatePages = 0;
            $allReviews = array_merge($allReviews, $newReviews);

            // Update CSRF token if a new one was returned
            if (!empty($data['csrfToken'])) {
                $this->cachedCsrfToken = $data['csrfToken'];
                $csrfToken = $data['csrfToken'];
            }

            Log::info("Fetched page {$page}", [
                'endpoint' => $endpointName,
                'reviews_on_page' => count($parsed['reviews']),
                'new_reviews' => count($newReviews),
                'total_so_far' => count($allReviews),
                'total_expected' => $totalCount,
            ]);

            // Stop if we've fetched all known reviews
            if ($totalCount > 0 && count($allReviews) >= $totalCount) {
                break;
            }

            // If fewer than page size — only stop if we've also reached totalCount or have no totalCount
            if (count($parsed['reviews']) < self::PAGE_SIZE) {
                if ($totalCount <= 0 || count($allReviews) >= $totalCount) {
                    break;
                }
                // Haven't reached totalCount yet — keep going
                Log::info("Page {$page} returned fewer reviews than page size, but haven't reached total", [
                    'on_page' => count($parsed['reviews']),
                    'fetched' => count($allReviews),
                    'total' => $totalCount,
                ]);
            }

            // Delay between pages to avoid rate limiting
            usleep(self::PAGE_DELAY_US);
        }

        if (empty($allReviews)) {
            return null;
        }

        Log::info("Pagination complete for {$endpointName}", [
            'total_fetched' => count($allReviews),
            'total_expected' => $totalCount,
        ]);

        return [
            'organization_name' => $organizationName,
            'rating' => $rating,
            'total_reviews' => max($totalCount, count($allReviews)),
            'reviews' => $allReviews,
        ];
    }

    /**
     * Reset session state for proxy rotation recovery.
     */
    private function resetSession(): void
    {
        $this->cookieJar = new CookieJar();
        $this->pickRandomUserAgent();
        $this->cachedCsrfToken = null;
        $this->cachedSessionId = null;
        $this->cachedReqId = null;
        $this->workingPaginationVariant = null;
    }

    /**
     * Compute the djb2 hash used by Yandex Maps to sign API requests.
     * The `s` parameter is required for fetchReviews — without it the API returns 403.
     *
     * Algorithm: standard djb2 (hash = 5381, hash = hash * 33 XOR char) masked to uint32.
     * Input: the full query string of all other parameters (sorted alphabetically, without `s`).
     */
    private function computeRequestSignature(array $params): string
    {
        // Sort params alphabetically by key (Yandex expects deterministic order)
        ksort($params);
        $queryString = http_build_query($params);

        $hash = 5381;
        $len = strlen($queryString);
        for ($i = 0; $i < $len; $i++) {
            // hash = hash * 33 ^ charCode  (unsigned 32-bit)
            $hash = (($hash << 5) + $hash) ^ ord($queryString[$i]);
            // Keep within 32-bit unsigned range
            $hash &= 0xFFFFFFFF;
        }

        return (string) $hash;
    }

    /**
     * Make a single API call to fetch reviews for a specific page.
     * Sends clean parameter set appropriate for the endpoint.
     */
    private function callReviewsApi(string $endpoint, string $orgId, string $csrfToken, int $page, ?string $captchaAnswer = null, string $sortOrder = 'by_time', ?int $ratingFilter = null): ?array
    {
        $offset = ($page - 1) * self::PAGE_SIZE;

        // Build base parameters. Yandex requires ajax, businessId, csrfToken,
        // sessionId, reqId, and the `s` signature hash over all other params.
        $commonParams = [
            'ajax' => '1',
            'businessId' => $orgId,
            'csrfToken' => $csrfToken,
            'locale' => 'ru_RU',
            'ranking' => $sortOrder,
        ];

        // Per-rating filter: fetch only reviews with a specific star count (1-5).
        // This bypasses the ~600 review cap per single API query.
        if ($ratingFilter !== null && $ratingFilter >= 1 && $ratingFilter <= 5) {
            $commonParams['rating'] = $ratingFilter;
        }

        // Add sessionId and reqId if available (extracted from HTML during session init)
        if ($this->cachedSessionId) {
            $commonParams['sessionId'] = $this->cachedSessionId;
        }
        if ($this->cachedReqId) {
            $commonParams['reqId'] = $this->cachedReqId;
        }

        // Yandex periodically changes pagination parameter contracts.
        // Try several compatible variants to avoid looping over the same small page.
        $paramsVariants = [
            // 1-based page indexing
            array_merge($commonParams, [
                'page' => $page,
                'pageSize' => self::PAGE_SIZE,
            ]),
            // 0-based page indexing
            array_merge($commonParams, [
                'page' => max(0, $page - 1),
                'pageSize' => self::PAGE_SIZE,
            ]),
            // offset/limit
            array_merge($commonParams, [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
            ]),
        ];

        if (str_contains($endpoint, 'maps-reviews-widget')) {
            // Widget endpoint uses oid instead of businessId
            $paramsVariants = array_map(function (array $params) use ($orgId) {
                $params['oid'] = $orgId;
                return $params;
            }, $paramsVariants);
        }

        if ($captchaAnswer) {
            $paramsVariants = array_map(function (array $params) use ($captchaAnswer) {
                $params['captchaAnswer'] = $captchaAnswer;
                return $params;
            }, $paramsVariants);
        }

        // Compute the `s` signature (djb2 hash) for each variant.
        // Must be added AFTER all other params are set, since `s` is derived from them.
        $paramsVariants = array_map(function (array $params) {
            $params['s'] = $this->computeRequestSignature($params);
            return $params;
        }, $paramsVariants);

        $headers = [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Referer' => $this->mapsUrl("/org/{$orgId}/reviews/"),
            'Origin' => 'https://' . $this->baseDomain,
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        if ($this->secChUa) {
            $headers['Sec-Ch-Ua'] = $this->secChUa;
            $headers['Sec-Ch-Ua-Mobile'] = '?0';
            $headers['Sec-Ch-Ua-Platform'] = $this->secChUaPlatform;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = null;
                $data = null;

                // If we already know which pagination variant works, try it directly (single request)
                if ($this->workingPaginationVariant !== null && isset($paramsVariants[$this->workingPaginationVariant])) {
                    $response = $this->httpGet($endpoint, $paramsVariants[$this->workingPaginationVariant], $headers, 15);
                    if ($response && $response->successful()) {
                        $candidate = $response->json();
                        if (is_array($candidate)) {
                            Log::info('API response received', [
                                'endpoint' => basename($endpoint),
                                'page' => $page,
                                'keys' => array_keys($candidate),
                                'has_reviews' => isset($candidate['reviews']) || isset($candidate['items']) || isset($candidate['data']),
                                'cached_variant' => $this->workingPaginationVariant + 1,
                            ]);
                            return $candidate;
                        }
                    }
                    // Cached variant failed — reset and try all variants below
                    Log::info('Cached pagination variant failed, trying all variants', [
                        'endpoint' => basename($endpoint),
                        'page' => $page,
                    ]);
                    $this->workingPaginationVariant = null;
                }

                foreach ($paramsVariants as $variantIndex => $params) {
                    $response = $this->httpGet($endpoint, $params, $headers, 15);

                    if (!$response || !$response->successful()) {
                        continue;
                    }

                    $candidate = $response->json();
                    if (!is_array($candidate)) {
                        continue;
                    }

                    $data = $candidate;
                    // Cache the working variant for subsequent pages
                    $this->workingPaginationVariant = $variantIndex;
                    if ($variantIndex > 0) {
                        Log::info('API pagination variant detected', [
                            'endpoint' => basename($endpoint),
                            'page' => $page,
                            'variant' => $variantIndex + 1,
                        ]);
                    }
                    break;
                }

                if (is_array($data)) {
                    Log::info('API response received', [
                        'endpoint' => basename($endpoint),
                        'page' => $page,
                        'keys' => array_keys($data),
                        'has_reviews' => isset($data['reviews']) || isset($data['items']) || isset($data['data']),
                    ]);
                    return $data;
                }

                Log::warning('API request failed', [
                    'endpoint' => basename($endpoint),
                    'status' => $response ? $response->status() : 'null',
                    'attempt' => $attempt,
                ]);
            } catch (\Exception $e) {
                Log::warning("API call attempt {$attempt} failed: " . $e->getMessage());
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep(1000000 * $attempt); // 1s, 2s backoff
            }
        }

        return null;
    }

    /**
     * Parse response from Yandex Maps API — handles multiple response formats.
     */
    private function parseApiResponse(array $data): array
    {
        $result = [
            'organization_name' => null,
            'rating' => null,
            'total_reviews' => 0,
            'reviews' => [],
        ];

        // Extract organization name from various possible keys
        $result['organization_name'] = $data['businessName']
            ?? $data['orgName']
            ?? $data['name']
            ?? $data['data']['businessName'] ?? null
            ?? $data['data']['name'] ?? null
            ?? $data['organization']['name'] ?? null
            ?? $data['business']['name'] ?? null;

        // Extract rating
        $result['rating'] = $this->extractFloatRating($data);
        if (!$result['rating'] && isset($data['rating']) && is_array($data['rating'])) {
            $result['rating'] = $this->extractFloatRating($data['rating']);
        }
        if (!$result['rating'] && isset($data['data']['rating'])) {
            $val = is_array($data['data']['rating'])
                ? $this->extractFloatRating($data['data']['rating'])
                : floatval($data['data']['rating']);
            if ($val >= 1 && $val <= 5) {
                $result['rating'] = $val;
            }
        }

        // Extract total count — check many possible paths
        $totalCount = 0;
        $totalPaths = [
            'totalCount', 'reviewCount', 'totalReviews', 'reviewsCount', 'ratingCount', 'total',
        ];
        foreach ($totalPaths as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && intval($data[$key]) > $totalCount) {
                $totalCount = intval($data[$key]);
            }
        }
        // Nested paths for total
        $nestedTotalPaths = [
            ['pager', 'totalCount'],
            ['pager', 'total'],
            ['data', 'totalCount'],
            ['data', 'total'],
            ['data', 'reviewCount'],
            ['meta', 'totalCount'],
            ['meta', 'total'],
            ['pagination', 'total'],
        ];
        foreach ($nestedTotalPaths as $path) {
            $current = $data;
            foreach ($path as $key) {
                $current = $current[$key] ?? null;
            }
            if ($current !== null && is_numeric($current) && intval($current) > $totalCount) {
                $totalCount = intval($current);
            }
        }
        // Deep fallback: inspect nested payload for explicit total/review counters.
        // Intentionally excludes ambiguous key "count" because it often equals page size.
        $deepTotal = $this->extractMaxNumericByKeys($data, [
            'totalCount', 'reviewCount', 'totalReviews', 'reviewsCount', 'ratingCount', 'reviewsTotal',
        ]);
        if ($deepTotal > $totalCount) {
            $totalCount = $deepTotal;
        }
        $result['total_reviews'] = $totalCount;

        if ($result['rating'] === null) {
            $result['rating'] = $this->extractFloatRatingDeep($data);
        }

        // Find reviews array — try multiple paths
        $reviews = null;
        $reviewPaths = [
            ['reviews'],
            ['items'],
            ['comments'],
            ['businessReviews'],
            ['data', 'reviews'],
            ['data', 'items'],
            ['data', 'comments'],
            ['data', 'businessReviews'],
            ['result', 'reviews'],
            ['result', 'items'],
            ['result', 'businessReviews'],
            ['response', 'reviews'],
            ['response', 'items'],
            ['response', 'businessReviews'],
            ['data'],
        ];

        foreach ($reviewPaths as $path) {
            $current = $data;
            foreach ($path as $key) {
                $current = $current[$key] ?? null;
                if (!is_array($current)) {
                    $current = null;
                    break;
                }
            }
            // Make sure we found a list of reviews, not a single object
            if ($current && is_array($current) && !empty($current) && isset($current[0])) {
                // Verify it looks like review data (has text, author, or rating keys)
                $sample = $current[0];
                if (is_array($sample) && (
                    isset($sample['text']) || isset($sample['author']) || isset($sample['rating']) ||
                    isset($sample['reviewId']) || isset($sample['comment']) || isset($sample['body']) ||
                    isset($sample['updatedTime']) || isset($sample['stars'])
                )) {
                    $reviews = $current;
                    break;
                }
            }
        }

        if (!$reviews) {
            // Fallback: deep search for any array of review-like objects.
            $reviews = $this->extractReviewListDeep($data);
            if (!$reviews) {
                return $result;
            }
        }

        foreach ($reviews as $review) {
            if (!is_array($review)) continue;

            $text = $review['text'] ?? $review['comment'] ?? $review['body'] ?? $review['reviewBody'] ?? '';

            $result['reviews'][] = [
                'author_name' => $this->extractAuthorName($review),
                'author_phone' => null,
                'rating' => $this->extractRating($review),
                'text' => is_string($text) ? $text : '',
                'branch_name' => $review['businessName'] ?? $review['branchName'] ?? $review['orgName'] ?? null,
                'published_at' => $this->extractDate($review),
                'yandex_id' => isset($review['reviewId']) ? (string) $review['reviewId']
                    : (isset($review['id']) ? (string) $review['id'] : null),
            ];
        }

        return $result;
    }

    /**
     * Deep search for review arrays in unknown API payload structures.
     */
    private function extractReviewListDeep(array $data): ?array
    {
        $stack = [$data];

        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            if (!empty($current) && isset($current[0]) && is_array($current[0])) {
                $sample = $current[0];
                if (
                    isset($sample['text']) || isset($sample['comment']) || isset($sample['body']) ||
                    isset($sample['author']) || isset($sample['authorName']) || isset($sample['reviewId'])
                ) {
                    return $current;
                }
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return null;
    }

    /**
     * Extract JSON from a script tag containing a named variable assignment.
     * Uses brace counting for robust extraction instead of fragile regex.
     */
    private function extractJsonFromScript(string $html, string $varName): ?array
    {
        // Find the assignment: window.__VAR__ = { ... };
        $pattern = '/window\.' . preg_quote($varName, '/') . '\s*=\s*/';
        if (!preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startPos = $m[0][1] + strlen($m[0][0]);

        // Find the opening brace
        if (!isset($html[$startPos]) || $html[$startPos] !== '{') {
            return null;
        }

        // Count braces to find the matching closing brace
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($html);
        $endPos = $startPos;

        for ($i = $startPos; $i < $len && $i < $startPos + 1000000; $i++) {
            $char = $html[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($char === '"' && !$escape) {
                $inString = !$inString;
                continue;
            }

            if ($inString) continue;

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $endPos = $i;
                    break;
                }
            }
        }

        if ($depth !== 0) {
            Log::warning("Failed to find closing brace for {$varName}");
            return null;
        }

        $jsonStr = substr($html, $startPos, $endPos - $startPos + 1);
        $data = json_decode($jsonStr, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("JSON decode error for {$varName}", [
                'error' => json_last_error_msg(),
                'json_length' => strlen($jsonStr),
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Sanitize review data before database insertion.
     * Trims whitespace, normalizes text, validates values.
     */
    private function sanitizeReviewData(array $data): array
    {
        // Trim and normalize text
        if (isset($data['text']) && is_string($data['text'])) {
            $data['text'] = trim($data['text']);
            // Normalize multiple whitespace/newlines
            $data['text'] = preg_replace('/\n{3,}/', "\n\n", $data['text']);
            $data['text'] = preg_replace('/[^\S\n]{2,}/', ' ', $data['text']);
        }

        // Trim author name
        if (isset($data['author_name']) && is_string($data['author_name'])) {
            $data['author_name'] = trim($data['author_name']);
            if ($data['author_name'] === '') {
                $data['author_name'] = 'Аноним';
            }
        }

        // Validate rating is in range
        if (isset($data['rating'])) {
            $rating = is_numeric($data['rating']) ? intval($data['rating']) : null;
            $data['rating'] = ($rating !== null && $rating >= 1 && $rating <= 5) ? $rating : null;
        }

        // Trim branch name
        if (isset($data['branch_name']) && is_string($data['branch_name'])) {
            $data['branch_name'] = trim($data['branch_name']) ?: null;
        }

        // Ensure yandex_id is a string or null
        if (isset($data['yandex_id'])) {
            $data['yandex_id'] = $data['yandex_id'] !== null ? (string)$data['yandex_id'] : null;
        }

        return $data;
    }

    /**
     * Extract all possible review data from the HTML page.
     */
    private function extractFromHtml(string $html, string $orgId): array
    {
        $result = [
            'organization_name' => null,
            'rating' => null,
            'total_reviews' => 0,
            'reviews' => [],
        ];

        // Strategy 1-3: Extract from embedded state variables
        $stateVariables = [
            '__PRELOADED_STATE__',
            '__INITIAL_STATE__',
            '__INITIAL_DATA__',
        ];

        foreach ($stateVariables as $varName) {
            $stateData = $this->extractJsonFromScript($html, $varName);
            if ($stateData) {
                Log::info("Found {$varName}", ['keys' => array_keys($stateData)]);
                $parsed = $this->parsePreloadedState($stateData, $orgId);
                if (!empty($parsed['reviews'])) {
                    return $parsed;
                }
                // Even without reviews, grab org info
                if (!empty($parsed['organization_name']) && empty($result['organization_name'])) {
                    $result['organization_name'] = $parsed['organization_name'];
                    $result['rating'] = $parsed['rating'];
                    $result['total_reviews'] = $parsed['total_reviews'];
                }
            }
        }

        // Strategy 4: Extract from config/data script tags (Yandex sometimes uses this format)
        // Uses extractJsonFromScript with proper brace-counting instead of fragile regex
        $skipVars = ['__PRELOADED_STATE__', '__INITIAL_STATE__', '__INITIAL_DATA__'];
        if (preg_match_all('/(?:var\s+)?(?:window\.)?(\w+)\s*=\s*\{/', $html, $varMatches)) {
            $uniqueVars = array_unique($varMatches[1]);
            foreach ($uniqueVars as $varName) {
                if (in_array($varName, $skipVars)) {
                    continue;
                }
                $stateData = $this->extractJsonFromScript($html, $varName);
                if ($stateData && is_array($stateData)) {
                    $parsed = $this->parsePreloadedState($stateData, $orgId);
                    if (!empty($parsed['reviews'])) {
                        Log::info("Found reviews in JS variable: {$varName}", ['count' => count($parsed['reviews'])]);
                        return $parsed;
                    }
                }
            }
        }

        // Strategy 5: Extract JSON-LD structured data
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $jsonLdMatches)) {
            foreach ($jsonLdMatches[1] as $jsonLd) {
                $ldData = json_decode($jsonLd, true);
                if ($ldData) {
                    $this->parseJsonLd($ldData, $result);
                }
            }
            if (!empty($result['reviews'])) {
                return $result;
            }
        }

        // Strategy 6: Extract aggregateRating from inline JSON
        if (preg_match('/"aggregateRating"\s*:\s*(\{[^}]+\})/s', $html, $matches)) {
            $ratingData = json_decode($matches[1], true);
            if ($ratingData) {
                $result['rating'] = floatval($ratingData['ratingValue'] ?? 0);
                $result['total_reviews'] = intval($ratingData['reviewCount'] ?? $ratingData['ratingCount'] ?? 0);
            }
        }

        // Strategy 7: Try to find review data in any JSON block on the page
        if (preg_match_all('/"reviews"\s*:\s*(\[.+?\])\s*[,}]/s', $html, $reviewMatches)) {
            foreach ($reviewMatches[1] as $jsonStr) {
                $reviews = json_decode($jsonStr, true);
                if ($reviews && is_array($reviews) && count($reviews) > 0 && is_array($reviews[0])) {
                    $hasReviewLikeData = isset($reviews[0]['text']) || isset($reviews[0]['author']) || isset($reviews[0]['rating']);
                    if ($hasReviewLikeData) {
                        Log::info('Found reviews in inline JSON', ['count' => count($reviews)]);
                        $parsed = $this->parseApiResponse(['reviews' => $reviews]);
                        if (!empty($parsed['reviews'])) {
                            $parsed['organization_name'] = $result['organization_name'];
                            $parsed['rating'] = $result['rating'];
                            $parsed['total_reviews'] = $result['total_reviews'] ?: count($parsed['reviews']);
                            return $parsed;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parse HTML DOM for reviews (last-resort DOM scraping).
     */
    private function parseHtmlDom(string $html, string $orgId): array
    {
        $result = [
            'organization_name' => null,
            'rating' => null,
            'total_reviews' => 0,
            'reviews' => [],
        ];

        $crawler = new Crawler($html);

        // Get org name with multiple selectors
        $orgNameSelectors = [
            'h1.orgpage-header-view__header',
            '[class*="business-title"]',
            '[class*="card-title-view__title"]',
            '[class*="orgpage-header"] h1',
            'h1[class*="title"]',
            '[class*="business-card-title"]',
            'h1',
        ];
        foreach ($orgNameSelectors as $selector) {
            try {
                $node = $crawler->filter($selector);
                if ($node->count() > 0) {
                    $text = trim($node->first()->text(''));
                    if ($text && mb_strlen($text) > 1 && mb_strlen($text) < 200) {
                        $result['organization_name'] = $text;
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Parse reviews with expanded selectors
        $reviewSelectors = [
            '.business-review-view__info',
            '[class*="business-reviews-card-view__review"]',
            '[class*="business-review-view"]',
            '[class*="business-reviews-card"]',
            '[class*="review-item"]',
            '[class*="reviews-review"]',
            '[class*="comment-item"]',
            '[itemprop="review"]',
        ];

        foreach ($reviewSelectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $nodes->each(function (Crawler $node) use (&$result) {
                        $review = $this->parseReviewNode($node);
                        if ($review && ($review['text'] || $review['rating'])) {
                            $result['reviews'][] = $review;
                        }
                    });
                    if (!empty($result['reviews'])) {
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        $result['total_reviews'] = $result['total_reviews'] ?: count($result['reviews']);

        return $result;
    }

    /**
     * Parse JSON-LD structured data for organization info and reviews.
     */
    private function parseJsonLd(array $data, array &$result): void
    {
        $items = isset($data['@graph']) ? $data['@graph'] : [$data];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            if (!$result['organization_name'] && isset($item['name'])) {
                $result['organization_name'] = $item['name'];
            }

            if (isset($item['aggregateRating'])) {
                $agg = $item['aggregateRating'];
                $result['rating'] = floatval($agg['ratingValue'] ?? 0);
                $result['total_reviews'] = intval($agg['reviewCount'] ?? $agg['ratingCount'] ?? 0);
            }

            if (isset($item['review']) && is_array($item['review'])) {
                foreach ($item['review'] as $review) {
                    if (!is_array($review)) continue;
                    $result['reviews'][] = [
                        'author_name' => is_array($review['author'] ?? null)
                            ? ($review['author']['name'] ?? 'Аноним')
                            : ($review['author'] ?? 'Аноним'),
                        'author_phone' => null,
                        'rating' => isset($review['reviewRating']['ratingValue'])
                            ? intval($review['reviewRating']['ratingValue']) : null,
                        'text' => $review['reviewBody'] ?? $review['description'] ?? '',
                        'branch_name' => null,
                        'published_at' => isset($review['datePublished'])
                            ? Carbon::parse($review['datePublished']) : now(),
                        'yandex_id' => null,
                    ];
                }
            }
        }
    }

    /**
     * Parse preloaded state JSON from page — deep search through nested structures.
     */
    private function parsePreloadedState(array $state, string $orgId = ''): array
    {
        $result = [
            'organization_name' => null,
            'rating' => null,
            'total_reviews' => 0,
            'reviews' => [],
        ];

        // Deep search for business/organization data
        $business = $this->deepFindBusiness($state, $orgId);
        if ($business) {
            $result['organization_name'] = $business['name'] ?? $business['title'] ?? $business['displayName'] ?? null;
            $result['rating'] = $this->extractFloatRating($business);
            $result['total_reviews'] = intval(
                $business['reviewCount'] ?? $business['totalReviews'] ?? $business['reviewsCount']
                ?? $business['ratingCount'] ?? 0
            );
        }

        // Deep search for reviews
        $reviews = $this->deepFindReviews($state);
        if ($reviews) {
            foreach ($reviews as $review) {
                if (!is_array($review)) continue;
                $text = $review['text'] ?? $review['comment'] ?? $review['body'] ?? $review['reviewBody'] ?? '';
                if (!$text && !isset($review['rating']) && !isset($review['stars'])) continue;

                $result['reviews'][] = [
                    'author_name' => $this->extractAuthorName($review),
                    'author_phone' => null,
                    'rating' => $this->extractRating($review),
                    'text' => is_string($text) ? $text : '',
                    'branch_name' => $review['businessName'] ?? $review['branchName'] ?? null,
                    'published_at' => $this->extractDate($review),
                    'yandex_id' => isset($review['reviewId']) ? (string) $review['reviewId']
                        : (isset($review['id']) ? (string) $review['id'] : null),
                ];
            }
        }

        return $result;
    }

    /**
     * Recursively search for business/organization data in a nested state object.
     */
    private function deepFindBusiness(array $state, string $orgId = '', int $depth = 0): ?array
    {
        if ($depth > 5) return null;

        // Direct paths
        $directPaths = [
            'businesses', 'business', 'organization', 'orgInfo',
            'businessCard', 'orgCard', 'company',
        ];
        foreach ($directPaths as $key) {
            if (isset($state[$key]) && is_array($state[$key])) {
                $val = $state[$key];
                // If it's a list, find by org ID or take first
                if (isset($val[0])) {
                    foreach ($val as $item) {
                        if (is_array($item) && isset($item['name'])) {
                            if ($orgId && isset($item['id']) && (string)$item['id'] === $orgId) {
                                return $item;
                            }
                        }
                    }
                    // Take first with a name
                    foreach ($val as $item) {
                        if (is_array($item) && isset($item['name'])) return $item;
                    }
                }
                // It's a single object with name
                if (isset($val['name']) || isset($val['title'])) {
                    return $val;
                }
            }
        }

        // Nested search
        $nestedPaths = [
            ['data', 'organization'], ['data', 'business'], ['store', 'business'],
            ['data', 'orgInfo'], ['result', 'business'], ['result', 'organization'],
            ['entities', 'business'], ['entities', 'organization'],
        ];
        foreach ($nestedPaths as $path) {
            $current = $state;
            foreach ($path as $key) {
                $current = $current[$key] ?? null;
                if (!is_array($current)) {
                    $current = null;
                    break;
                }
            }
            if ($current) {
                if (isset($current['name']) || isset($current['title'])) {
                    return $current;
                }
                // If it's a collection, find the right one
                if (isset($current[0])) {
                    foreach ($current as $item) {
                        if (is_array($item) && (isset($item['name']) || isset($item['title']))) {
                            return $item;
                        }
                    }
                }
            }
        }

        // Recursive search in nested arrays (limited depth)
        foreach ($state as $key => $value) {
            if (is_array($value) && !is_numeric($key)) {
                $found = $this->deepFindBusiness($value, $orgId, $depth + 1);
                if ($found) return $found;
            }
        }

        return null;
    }

    /**
     * Recursively search for reviews array in a nested state object.
     */
    private function deepFindReviews(array $state, int $depth = 0): ?array
    {
        if ($depth > 5) return null;

        // Direct and nested paths
        $paths = [
            ['reviews'], ['reviewItems'], ['businessReviews'],
            ['data', 'reviews'], ['store', 'reviews'], ['result', 'reviews'],
            ['entities', 'reviews'], ['data', 'items'], ['items'],
        ];

        foreach ($paths as $path) {
            $current = $state;
            foreach ($path as $key) {
                $current = $current[$key] ?? null;
                if (!is_array($current)) {
                    $current = null;
                    break;
                }
            }
            if ($current && is_array($current) && !empty($current)) {
                // Verify it looks like reviews (first item has text or rating)
                $first = is_array(reset($current)) ? reset($current) : null;
                if ($first && (isset($first['text']) || isset($first['rating']) || isset($first['author']) || isset($first['reviewId']))) {
                    return $current;
                }
            }
        }

        // Recursive search
        foreach ($state as $key => $value) {
            if (is_array($value) && !is_numeric($key)) {
                $found = $this->deepFindReviews($value, $depth + 1);
                if ($found) return $found;
            }
        }

        return null;
    }

    /**
     * Parse a single review DOM node.
     */
    private function parseReviewNode(Crawler $node): ?array
    {
        try {
            // Extract author name
            $authorName = 'Аноним';
            $authorSelectors = [
                '.business-review-view__author a',
                '.business-review-view__author span',
                '[class*="business-review-view__author"] a',
                '[class*="business-review-view__author"] span',
                '[class*="author-name"]',
                '[class*="author"] a',
                '[class*="user-name"] a',
                '[itemprop="author"]',
                '[class*="reviewer-name"]',
                '[class*="author"]',
                '[class*="user-name"]',
            ];
            foreach ($authorSelectors as $selector) {
                try {
                    $authorNode = $node->filter($selector);
                    if ($authorNode->count() > 0) {
                        $text = trim($authorNode->first()->text(''));
                        if ($text && $text !== 'Аноним') {
                            $authorName = $text;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            $authorName = $this->cleanAuthorName($authorName);

            // Extract review text
            $text = '';
            $textSelectors = [
                '.business-review-view__body-text',
                '[class*="business-review-view__body-text"]',
                '[class*="business-review-view__body"] span',
                '[class*="review-text"]',
                '[class*="review-body"]',
                '[class*="comment-text"]',
                '[itemprop="reviewBody"]',
                '[class*="text"]',
                '[class*="comment"]',
            ];
            foreach ($textSelectors as $selector) {
                try {
                    $textNode = $node->filter($selector);
                    if ($textNode->count() > 0) {
                        $t = trim($textNode->first()->text(''));
                        if ($t && mb_strlen($t) > 5) {
                            $text = $t;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            $rating = $this->extractRatingFromNode($node);
            $publishedAt = $this->extractDateFromNode($node);

            return [
                'author_name' => trim($authorName),
                'author_phone' => null,
                'rating' => $rating,
                'text' => trim($text),
                'branch_name' => null,
                'published_at' => $publishedAt,
                'yandex_id' => null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract rating from a review DOM node using multiple strategies.
     */
    private function extractRatingFromNode(Crawler $node): ?int
    {
        // Strategy 1: Count filled/active star elements
        $starSelectors = [
            '.business-rating-badge-view__stars [class*="business-rating-badge-view__star"][class*="_full"]',
            '[class*="business-rating-badge-view__star"][class*="_full"]',
            '[class*="inline-image_type_star"][class*="_full"]',
            '[class*="inline-image"][class*="_loaded"][class*="_full"]',
            '[class*="star"][class*="active"]',
            '[class*="star"][class*="full"]',
            '[class*="star"][class*="_full"]',
            '[class*="rating-star"][class*="active"]',
        ];
        foreach ($starSelectors as $selector) {
            try {
                $count = $node->filter($selector)->count();
                if ($count >= 1 && $count <= 5) {
                    return $count;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Strategy 2: aria-label/title on rating container
        $ratingContainerSelectors = [
            '.business-rating-badge-view__stars',
            '[class*="business-rating-badge-view"]',
            '[class*="business-rating"]',
            '[class*="rating"]',
            '[class*="stars"]',
        ];
        foreach ($ratingContainerSelectors as $selector) {
            try {
                $ratingNode = $node->filter($selector);
                if ($ratingNode->count() > 0) {
                    $first = $ratingNode->first();
                    $ariaLabel = $first->attr('aria-label') ?? $first->attr('title') ?? '';
                    if (preg_match('/(\d)\s*(?:из|\/)\s*5/u', $ariaLabel, $m)) {
                        return intval($m[1]);
                    }
                    if (preg_match('/(\d)/', $ariaLabel, $m)) {
                        $val = intval($m[1]);
                        if ($val >= 1 && $val <= 5) return $val;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Strategy 3: data-* attributes
        try {
            $ratingNode = $node->filter('[data-value], [data-rating], [data-score]');
            if ($ratingNode->count() > 0) {
                $first = $ratingNode->first();
                $val = intval($first->attr('data-value') ?? $first->attr('data-rating') ?? $first->attr('data-score') ?? 0);
                if ($val >= 1 && $val <= 5) return $val;
            }
        } catch (\Exception $e) {}

        // Strategy 4: itemprop="ratingValue"
        try {
            $ratingNode = $node->filter('[itemprop="ratingValue"]');
            if ($ratingNode->count() > 0) {
                $val = intval($ratingNode->first()->attr('content') ?? $ratingNode->first()->text(''));
                if ($val >= 1 && $val <= 5) return $val;
            }
        } catch (\Exception $e) {}

        // Strategy 5: Count _full class elements (Yandex BEM)
        try {
            $fullStars = $node->filter('[class*="_full"]');
            $count = $fullStars->count();
            if ($count >= 1 && $count <= 5) return $count;
        } catch (\Exception $e) {}

        return null;
    }

    /**
     * Extract date from a review DOM node.
     */
    private function extractDateFromNode(Crawler $node): Carbon
    {
        // Strategy 1: <time datetime="...">
        try {
            $timeNode = $node->filter('time[datetime]');
            if ($timeNode->count() > 0) {
                $datetime = $timeNode->first()->attr('datetime');
                if ($datetime) {
                    return Carbon::parse($datetime);
                }
            }
        } catch (\Exception $e) {}

        // Strategy 2: <meta itemprop="datePublished">
        try {
            $metaNode = $node->filter('[itemprop="datePublished"]');
            if ($metaNode->count() > 0) {
                $content = $metaNode->first()->attr('content') ?? $metaNode->first()->text('');
                if ($content) return Carbon::parse($content);
            }
        } catch (\Exception $e) {}

        // Strategy 3: date text in node
        $dateSelectors = [
            '[class*="business-review-view__date"]',
            '[class*="review-date"]',
            '[class*="date"]',
            'time',
            '[class*="ago"]',
        ];
        foreach ($dateSelectors as $selector) {
            try {
                $dateNode = $node->filter($selector);
                if ($dateNode->count() > 0) {
                    $dateText = trim($dateNode->first()->text(''));
                    if ($dateText) {
                        return $this->parseRussianDate($dateText);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return now();
    }

    /**
     * Extract float rating from top-level data.
     */
    private function extractFloatRating(array $data): ?float
    {
        foreach (['rating', 'orgRating', 'averageRating', 'totalRating', 'score', 'ratingValue'] as $key) {
            if (isset($data[$key])) {
                $val = is_array($data[$key])
                    ? ($data[$key]['value'] ?? $data[$key]['score'] ?? $data[$key]['average'] ?? null)
                    : $data[$key];
                if ($val !== null && is_numeric($val)) {
                    $float = floatval($val);
                    if ($float >= 1.0 && $float <= 5.0) {
                        return round($float, 2);
                    }
                    // Some APIs return rating as 0-10 scale
                    if ($float > 5.0 && $float <= 10.0) {
                        return round($float / 2, 2);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Recursively find maximum numeric value for known counter keys.
     */
    private function extractMaxNumericByKeys(array $data, array $keys): int
    {
        $max = 0;
        $keySet = array_fill_keys($keys, true);
        $stack = [$data];

        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }

                if (isset($keySet[$key]) && is_numeric($value)) {
                    $candidate = intval($value);
                    if ($candidate > $max) {
                        $max = $candidate;
                    }
                }
            }
        }

        return $max;
    }

    /**
     * Recursively find rating in nested payloads (e.g. aggregateRating.ratingValue).
     */
    private function extractFloatRatingDeep(array $data): ?float
    {
        $best = null;
        $stack = [$data];

        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            $candidate = $this->extractFloatRating($current);
            if ($candidate !== null && ($best === null || $candidate > $best)) {
                $best = $candidate;
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return $best;
    }

    /**
     * Extract author name from review data, handling various structures.
     */
    private function extractAuthorName(array $review): string
    {
        $author = $review['author'] ?? null;

        if (is_array($author)) {
            $name = $author['name'] ?? $author['displayName'] ?? $author['publicName'] ?? $author['login'] ?? null;
        } else {
            $name = $author ?? $review['authorName'] ?? $review['userName'] ?? $review['displayName'] ?? null;
        }

        if (!$name || !is_string($name) || trim($name) === '') {
            return 'Аноним';
        }

        return $this->cleanAuthorName($name);
    }

    /**
     * Clean author name by removing badge/level text that may be concatenated.
     */
    private function cleanAuthorName(string $name): string
    {
        // Remove Yandex user badges/levels that get concatenated with the author name
        $name = preg_replace('/\s*Знаток\s+города\s+\d+\s+уровня\s*/u', '', $name);
        $name = preg_replace('/\s*Активный\s+автор\s*/u', '', $name);
        $name = preg_replace('/\s*Местный\s+эксперт\s*/u', '', $name);
        // Match "Эксперт" only when preceded by space/start and followed by space/end/digit
        // to avoid corrupting names that contain "Эксперт" as part of a word
        $name = preg_replace('/(?:^|\s)Эксперт(?:\s+\d+\s+уровня)?(?:\s|$)/u', ' ', $name);
        $name = preg_replace('/\s*Новичок\s*/u', '', $name);
        $name = preg_replace('/\s*\d+\s+отзыв\w*\s*/u', '', $name);
        $name = preg_replace('/\s*\d+\s+оцен\w*\s*/u', '', $name);
        $name = preg_replace('/\s*\d+\s+фото\w*\s*/u', '', $name);
        // Clean up multiple spaces
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name) ?: 'Аноним';
    }

    /**
     * Extract rating from review data, handling various structures.
     */
    private function extractRating(array $review): ?int
    {
        $rating = $review['rating'] ?? null;

        if (is_array($rating)) {
            $rating = $rating['value'] ?? $rating['score'] ?? $rating['stars'] ?? null;
        }

        if ($rating !== null && !is_array($rating) && is_numeric($rating)) {
            $intRating = intval($rating);
            if ($intRating >= 1 && $intRating <= 5) {
                return $intRating;
            }
            // Handle 0-10 scale
            $floatRating = floatval($rating);
            if ($floatRating > 5 && $floatRating <= 10) {
                return max(1, min(5, intval(round($floatRating / 2))));
            }
        }

        foreach (['stars', 'score', 'mark', 'value'] as $key) {
            if (isset($review[$key]) && is_numeric($review[$key])) {
                $val = intval($review[$key]);
                if ($val >= 1 && $val <= 5) {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Extract date from review data, trying multiple possible keys.
     */
    private function extractDate(array $review): Carbon
    {
        $dateKeys = [
            'updatedTime', 'time', 'date', 'createdTime', 'publishedTime',
            'created', 'updated', 'datePublished', 'createdAt', 'publishedAt',
            'dateCreated', 'timestamp',
        ];

        foreach ($dateKeys as $key) {
            if (!empty($review[$key])) {
                $val = $review[$key];
                // Handle numeric timestamps
                if (is_numeric($val)) {
                    try {
                        // Unix timestamp (seconds or milliseconds)
                        $ts = intval($val);
                        if ($ts > 1e12) $ts = intval($ts / 1000); // ms to s
                        return Carbon::createFromTimestamp($ts);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                if (is_string($val)) {
                    try {
                        if (preg_match('/[а-яА-Я]/u', $val)) {
                            return $this->parseRussianDate($val);
                        }
                        return Carbon::parse($val);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return now();
    }

    /**
     * Parse Russian date string including relative dates.
     */
    private function parseRussianDate(string $dateStr): Carbon
    {
        $dateStr = mb_strtolower(trim($dateStr));

        if ($dateStr === 'сегодня') return Carbon::today();
        if ($dateStr === 'вчера') return Carbon::yesterday();
        if ($dateStr === 'позавчера') return Carbon::today()->subDays(2);

        // "N секунд/минут/часов/дней/недель/месяцев/лет назад"
        if (preg_match('/(\d+)\s+(секунд[уы]?|минут[уы]?|час(?:а|ов)?|день|дня|дней|недел[юиь]|месяц(?:а|ев)?|год|года|лет)\s+назад/u', $dateStr, $matches)) {
            $amount = intval($matches[1]);
            $unit = $matches[2];

            if (preg_match('/^секунд/u', $unit)) return Carbon::now()->subSeconds($amount);
            if (preg_match('/^минут/u', $unit)) return Carbon::now()->subMinutes($amount);
            if (preg_match('/^час/u', $unit)) return Carbon::now()->subHours($amount);
            if (preg_match('/^(день|дня|дней)$/u', $unit)) return Carbon::now()->subDays($amount);
            if (preg_match('/^недел/u', $unit)) return Carbon::now()->subWeeks($amount);
            if (preg_match('/^месяц/u', $unit)) return Carbon::now()->subMonths($amount);
            if (preg_match('/^(год|года|лет)$/u', $unit)) return Carbon::now()->subYears($amount);
        }

        // "секунду/минуту/час/день/неделю/месяц/год назад"
        if (preg_match('/^(секунду|минуту|час|день|неделю|месяц|год)\s+назад$/u', $dateStr, $matches)) {
            return match ($matches[1]) {
                'секунду' => Carbon::now()->subSecond(),
                'минуту' => Carbon::now()->subMinute(),
                'час' => Carbon::now()->subHour(),
                'день' => Carbon::now()->subDay(),
                'неделю' => Carbon::now()->subWeek(),
                'месяц' => Carbon::now()->subMonth(),
                'год' => Carbon::now()->subYear(),
                default => Carbon::now(),
            };
        }

        // "5 января 2024", "12 марта"
        $months = [
            'января' => 1, 'февраля' => 2, 'марта' => 3,
            'апреля' => 4, 'мая' => 5, 'июня' => 6,
            'июля' => 7, 'августа' => 8, 'сентября' => 9,
            'октября' => 10, 'ноября' => 11, 'декабря' => 12,
        ];

        foreach ($months as $name => $number) {
            if (mb_strpos($dateStr, $name) !== false) {
                if (preg_match('/(\d{1,2})\s+' . $name . '(?:\s+(\d{4}))?/u', $dateStr, $matches)) {
                    $day = intval($matches[1]);
                    $year = isset($matches[2]) ? intval($matches[2]) : Carbon::now()->year;
                    $date = Carbon::createFromDate($year, $number, $day)->startOfDay();
                    // If no year was specified and the date is in the future, use previous year
                    if (!isset($matches[2]) && $date->isFuture()) {
                        $date = $date->subYear();
                    }
                    return $date;
                }
            }
        }

        try {
            return Carbon::parse($dateStr);
        } catch (\Exception $e) {
            return now();
        }
    }

    /**
     * Make an HTTP GET request with shared cookie jar, proxy, and browser-like headers.
     */
    private function httpGet(string $url, array $query = [], array $extraHeaders = [], int $timeout = 20): ?\Illuminate\Http\Client\Response
    {
        $headers = array_merge([
            'User-Agent' => $this->userAgent,
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ], $extraHeaders);

        $options = ['cookies' => $this->cookieJar];

        // Apply proxy if configured
        if ($this->currentProxy) {
            $options['proxy'] = $this->currentProxy;
        }

        try {
            return Http::withHeaders($headers)
                ->withOptions($options)
                ->timeout($timeout)
                ->get($url, $query);
        } catch (\Exception $e) {
            Log::warning('HTTP request failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Full sync: delete old reviews and fetch all from scratch.
     * Uses a transaction to prevent data loss if something fails mid-sync.
     */
    public function syncReviews(YandexSource $source): YandexSource
    {
        $data = $this->fetchReviews($source);

        // Validate fetched data before deleting old reviews to prevent data loss
        if (empty($data['reviews'])) {
            Log::warning('Full sync fetched zero reviews, keeping existing data', [
                'source_id' => $source->id,
            ]);
            $source->update(['last_synced_at' => now()]);
            return $source->fresh();
        }

        DB::transaction(function () use ($source, $data) {
            // Delete old reviews inside transaction — will rollback if insert fails
            $source->reviews()->delete();

            // Batch insert new reviews — all old reviews are deleted so we only need create()
            $seenYandexIds = [];
            foreach ($data['reviews'] as $reviewData) {
                $reviewData = $this->sanitizeReviewData($reviewData);

                // Skip duplicate yandex_ids within the same batch (unique constraint)
                if (!empty($reviewData['yandex_id'])) {
                    if (isset($seenYandexIds[$reviewData['yandex_id']])) {
                        continue;
                    }
                    $seenYandexIds[$reviewData['yandex_id']] = true;
                }

                Review::create(array_merge($reviewData, ['yandex_source_id' => $source->id]));
            }
        });

        // Rating priority: use Yandex's reported rating (accurate across ALL reviews),
        // only fall back to computed average if Yandex didn't provide one
        $rating = null;
        if (isset($data['rating']) && $data['rating'] !== null && $data['rating'] > 0) {
            $rating = round(floatval($data['rating']), 2);
        }
        if ($rating === null && $source->reviews()->count() > 0) {
            $rating = round($source->reviews()->whereNotNull('rating')->avg('rating'), 2);
        }

        // total_reviews: use actual count of reviews stored in DB
        $totalReviews = $source->reviews()->count();

        $source->update([
            'organization_name' => $data['organization_name'] ?? $source->organization_name,
            'rating' => $rating,
            'total_reviews' => $totalReviews,
            'last_synced_at' => now(),
        ]);

        return $source->fresh();
    }

    /**
     * Incremental sync: only fetch new reviews since last sync.
     * Keeps existing reviews in DB, adds only new ones.
     */
    public function syncNewReviews(YandexSource $source): YandexSource
    {
        $data = $this->fetchReviews($source);

        if (empty($data['reviews'])) {
            $source->update(['last_synced_at' => now()]);
            return $source->fresh();
        }

        // Use hash map for O(1) lookups instead of O(n) in_array
        $existingIds = array_flip(
            $source->reviews()
                ->whereNotNull('yandex_id')
                ->pluck('yandex_id')
                ->toArray()
        );

        $newCount = 0;
        DB::transaction(function () use ($source, $data, $existingIds, &$newCount) {
            foreach ($data['reviews'] as $reviewData) {
                $reviewData = $this->sanitizeReviewData($reviewData);

                // Skip if we already have this review (O(1) hash lookup)
                if (!empty($reviewData['yandex_id']) && isset($existingIds[$reviewData['yandex_id']])) {
                    continue;
                }

                // Skip if no yandex_id and review looks like a duplicate by content
                if (empty($reviewData['yandex_id'])) {
                    $exists = $source->reviews()
                        ->where('author_name', $reviewData['author_name'])
                        ->where('text', $reviewData['text'] ?? '')
                        ->exists();
                    if ($exists) continue;
                }

                Review::create(array_merge($reviewData, ['yandex_source_id' => $source->id]));
                $newCount++;
            }
        });

        Log::info('Incremental sync complete', [
            'source_id' => $source->id,
            'new_reviews' => $newCount,
            'total_fetched' => count($data['reviews']),
        ]);

        // Update rating and count
        $rating = null;
        if (isset($data['rating']) && $data['rating'] !== null && $data['rating'] > 0) {
            $rating = round(floatval($data['rating']), 2);
        }
        if ($rating === null && $source->reviews()->count() > 0) {
            $rating = round($source->reviews()->whereNotNull('rating')->avg('rating'), 2);
        }

        // total_reviews: use actual count of reviews stored in DB
        $totalReviews = $source->reviews()->count();

        $source->update([
            'organization_name' => $data['organization_name'] ?? $source->organization_name,
            'rating' => $rating,
            'total_reviews' => $totalReviews,
            'last_synced_at' => now(),
        ]);

        return $source->fresh();
    }

    /**
     * Sync reviews for all sources (used by scheduled command).
     * Rotates proxy between branches to reduce captcha risk.
     */
    public function syncAllSources(bool $incremental = true): array
    {
        $sources = YandexSource::all();
        $results = [];

        foreach ($sources as $source) {
            try {
                // Rotate proxy for each branch
                $this->rotateProxy();
                $this->resetSession();

                if ($incremental) {
                    $updated = $this->syncNewReviews($source);
                } else {
                    $updated = $this->syncReviews($source);
                }

                $results[] = [
                    'source_id' => $source->id,
                    'organization' => $updated->organization_name,
                    'status' => 'ok',
                    'total_reviews' => $updated->total_reviews,
                    'rating' => $updated->rating,
                ];

                Log::info('Synced source', ['id' => $source->id, 'org' => $updated->organization_name]);
            } catch (\Exception $e) {
                $results[] = [
                    'source_id' => $source->id,
                    'organization' => $source->organization_name,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to sync source', [
                    'id' => $source->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Delay between branches to avoid rate limiting / captcha
            usleep(self::BRANCH_DELAY_US);
        }

        return $results;
    }
}
