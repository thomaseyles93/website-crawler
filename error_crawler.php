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
    echo "Usage: php crawler_with_errors.php <start-url>\n";
    exit(1);
}

// Create folders if missing
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

// Generate filenames
$hostSanitized = preg_replace('/[^a-zA-Z0-9\-]/', '_', parse_url($seed, PHP_URL_HOST));
$timestamp = date('Ymd_His');
$outputFile = "$outputDir/{$hostSanitized}_{$timestamp}.csv";
$errorFile = "$outputDir/{$hostSanitized}_{$timestamp}_errors.csv";

$client = new Client([
    'headers' => ['User-Agent' => 'MyPhpCrawler/1.0 (+https://example.com)'],
    'allow_redirects' => true,
    'timeout' => 10,
    'http_errors' => false,
]);

$visited = [];
$errors = [];
$queue = new SplQueue();
$queue->enqueue($seed);
$host = parse_url($seed, PHP_URL_HOST);

echo "Starting full crawl on: $seed\n";
echo "Output file: $outputFile\n";
echo "Error log: $errorFile\n";

// Helper: Add links to queue
function enqueueLink(?string $href, SplQueue $queue, array &$visited, string $seed, string $host): void
{
    if (!$href) return;
    $abs = (string)\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($seed), new \GuzzleHttp\Psr7\Uri($href));
    $abs = preg_replace('/#.*$/', '', $abs);
    if (parse_url($abs, PHP_URL_HOST) !== $host) return;
    if (!isset($visited[$abs])) $queue->enqueue($abs);
}

// Try sitemap first
$sitemapUrl = rtrim($seed, '/') . '/sitemap.xml';
try {
    $res = $client->get($sitemapUrl);
    if ($res->getStatusCode() === 200) {
        echo "Found sitemap: $sitemapUrl\n";
        $xml = simplexml_load_string($res->getBody()->getContents());
        if ($xml && isset($xml->url)) {
            foreach ($xml->url as $urlEntry) {
                $url = (string)$urlEntry->loc;
                if (!isset($visited[$url])) {
                    $visited[$url] = true;
                    $queue->enqueue($url);
                }
            }
        }
    }
} catch (Exception $e) {
    echo "No sitemap found or failed to load.\n";
}

// ---------------- Crawl ----------------
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
        foreach ($batch as $url) yield new Request('GET', $url);
    };

    $pool = new Pool($client, $requests(), [
        'concurrency' => $concurrency,
        'fulfilled' => function ($response, $index) use (&$queue, &$visited, &$errors, $batch, $seed, $host) {
            $url = $batch[$index];
            $status = $response->getStatusCode();

            if ($status >= 400) {
                $errors[] = [$url, $status];  // just HTTP status
            }

            if ($status >= 200 && $status < 400) {
                $crawler = new Crawler((string)$response->getBody());
                $crawler->filter('a[href]')->each(
                    fn (Crawler $node) => enqueueLink($node->attr('href'), $queue, $visited, $seed, $host)
                );
            }
        },
        'rejected' => function ($reason, $index) use (&$errors, $batch) {
            $url = $batch[$index];
            if ($reason instanceof \GuzzleHttp\Exception\ConnectException) {
                $errors[] = [$url, 'Connection/Timeout'];  // short message
            } elseif ($reason instanceof \GuzzleHttp\Exception\RequestException && $reason->hasResponse()) {
                $errors[] = [$url, $reason->getResponse()->getStatusCode()];
            } else {
                $errors[] = [$url, 'Other Error'];
            }
        },
    ]);

    $pool->promise()->wait();
    usleep($delayUs);

    echo "Crawled: " . count($visited) . " | Errors: " . count($errors) . "\r";
}

echo "\nCrawl finished.\nSaving results...\n";

// Save URLs
$fp = fopen($outputFile, 'w');
foreach (array_keys($visited) as $url) fputcsv($fp, [$url], ',', '"', '\\');
fclose($fp);

// Save errors
if (!empty($errors)) {
    $fpErr = fopen($errorFile, 'w');
    fputcsv($fpErr, ['URL', 'Error'], ',', '"', '\\');
    foreach ($errors as $err) fputcsv($fpErr, $err, ',', '"', '\\');
    fclose($fpErr);
    echo "Saved error log: $errorFile (" . count($errors) . " errors)\n";
} else {
    echo "No errors found!\n";
}

echo "Total URLs: " . count($visited) . "\n";
echo "All done.\n";
