# URL Crawler

This script crawls a single website and extracts all URLs into a CSV file.

## Requirements
- PHP 8.3 or higher
- Composer

## Installation

1. Clone or download this repo.
2. Install dependencies:

```bash
composer install
```
3. Run sitemap generator ```php crawler.php https://6b.health```
4. Run for URLs with errors (40X/50Xs) ```php crawler.php https://6b.health```
5. Check output folder for results