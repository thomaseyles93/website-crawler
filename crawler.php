<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

// ---------------- Setup ----------------
$seed = $argv[1] ?? null;
$concurrency = 2; //Careful upping this as it causes load on the server - run out of hours for mass
$delayUs = 200_000;

if (!$seed) {
    echo "Usage: php crawler.php <start-url>\n";
    exit(1);
}

// Create output folder if it doesn't exist
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Generate output file name based on URL + timestamp
$hostSanitized = preg_replace('/[^a-zA-Z0-9\-]/', '_', parse_url($seed, PHP_URL_HOST));
$timestamp = date('Ymd_His');
$outputFile = "$outputDir/{$hostSanitized}_{$timestamp}.csv";

$client = new Client([
    'headers' => ['User-Agent' => 'MyPhpCrawler/1.1 (+https://example.com)'],
    'allow_redirects' => true,
    'timeout' => 10,
    'http_errors' => false,
]);

$visited = [];
$queue = new SplQueue();
$queue->enqueue($seed);
$host = parse_url($seed, PHP_URL_HOST);

echo "Starting full crawl on: $seed\n";
echo "Output file: $outputFile\n";

function enqueueLink(?string $href, SplQueue $queue, array &$visited, string $seed, string $host): void
{
    if (!$href) return;
    $abs = (string)\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($seed), new \GuzzleHttp\Psr7\Uri($href));
    $abs = preg_replace('/#.*$/', '', $abs);
    if (parse_url($abs, PHP_URL_HOST) !== $host) return;
    if (!isset($visited[$abs])) $queue->enqueue($abs);
}

function parseSitemap(string $baseUrl, Client $client, SplQueue $queue, array &$visited): void
{
    $sitemapUrls = [
        rtrim($baseUrl, '/') . '/sitemap.xml',
        rtrim($baseUrl, '/') . '/sitemap_index.xml',
    ];

    foreach ($sitemapUrls as $sitemapUrl) {
        try {
            $res = $client->get($sitemapUrl);
            if ($res->getStatusCode() !== 200) continue;

            $body = (string)$res->getBody();
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);

            if (!$xml) continue;

            // Check if this is a sitemap index or URL list
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $s) {
                    $loc = (string)$s->loc;
                    if ($loc && !isset($visited[$loc])) {
                        echo "Discovered nested sitemap: $loc\n";
                        parseSitemap($loc, $client, $queue, $visited);
                    }
                }
            } elseif (isset($xml->url)) {
                foreach ($xml->url as $u) {
                    $loc = (string)$u->loc;
                    if ($loc && !isset($visited[$loc])) {
                        echo "Discovered URL from sitemap: $loc\n";
                        $queue->enqueue($loc);
                        $visited[$loc] = true;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore sitemap fetch failures
        }
    }
}

// ---------------- Step 1: Try loading sitemap ----------------
parseSitemap($seed, $client, $queue, $visited);

// ---------------- Step 2: Normal HTML link crawl ----------------
while (!$queue->isEmpty()) {
    $batch = [];
    while (!$queue->isEmpty() && count($batch) < $concurrency) {
        $url = $queue->dequeue();
        if (isset($visited[$url])) continue;
        $visited[$url] = true;
        $batch[] = $url;
    }

    if (empty($batch)) break;

    $requests = function() use ($batch) {
        foreach ($batch as $url) {
            yield new Request('GET', $url);
        }
    };

    $pool = new Pool($client, $requests(), [
        'concurrency' => $concurrency,
        'fulfilled' => function ($response, $index) use (&$queue, &$visited, $batch, $seed, $host) {
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 400) {
                $crawler = new Crawler((string)$response->getBody());
                $crawler->filter('a[href]')->each(
                    fn (Crawler $node) => enqueueLink($node->attr('href'), $queue, $visited, $seed, $host)
                );
            }
        },
        'rejected' => function ($reason, $index) use ($batch) {
            echo "Error fetching {$batch[$index]}: $reason\n";
        },
    ]);

    $pool->promise()->wait();

    usleep($delayUs);

    echo "URLs crawled so far: " . count($visited) . "\r";
}

// ---------------- Save the CSV ----------------
echo "\nCrawl finished. Saving to CSV...\n";
$fp = fopen($outputFile, 'w');
foreach (array_keys($visited) as $url) {
    fputcsv($fp, [$url], ',', '"', '\\');
}
fclose($fp);

echo "Done. Total URLs: " . count($visited) . "\n";
echo "Saved to: $outputFile\n";
