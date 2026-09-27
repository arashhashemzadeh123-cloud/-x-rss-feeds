<?php
declare(strict_types=1);

const CACHE_TTL = 300;
const DEFAULT_LIMIT = 20;
const MAX_LIMIT = 50;
const CACHE_DB = __DIR__ . '/.xsukax-x-rss.sqlite';

header('Content-Type: application/rss+xml; charset=UTF-8');

function fail(string $message, int $status = 400): never
{
    http_response_code($status);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<rss version="2.0"><channel>';
    echo '<title>X RSS Generator Error</title>';
    echo '<description>' . htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</description>';
    echo '</channel></rss>';

    exit;
}

function getDatabase(): PDO
{
    $pdo = new PDO('sqlite:' . CACHE_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cache (
            username TEXT PRIMARY KEY,
            content TEXT NOT NULL,
            fetched_at INTEGER NOT NULL
        )'
    );

    return $pdo;
}

function fetchUrl(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' =>
                "User-Agent: Mozilla/5.0 (X11; Linux x86_64) " .
                "AppleWebKit/537.36 (KHTML, like Gecko) " .
                "Chrome/131.0 Safari/537.36\r\n" .
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $content = @file_get_contents($url, false, $context);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Unable to fetch X profile');
    }

    return $content;
}

function extractTweets(string $html): array
{
    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $tweets = [];

    $nodes = $xpath->query(
        '//article[@data-testid="tweet"]'
    );

    if (!$nodes) {
        return [];
    }

    foreach ($nodes as $article) {
        $textNodes = $xpath->query(
            './/*[@data-testid="tweetText"]',
            $article
        );

        $text = '';

        if ($textNodes && $textNodes->length > 0) {
            $text = trim($textNodes->item(0)->textContent);
        }

        $links = $xpath->query('.//a', $article);

        $tweetUrl = '';

        if ($links) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');

                if (preg_match(
                    '#^/[^/]+/status/[0-9]+#',
                    $href
                )) {
                    $tweetUrl = 'https://x.com' . $href;
                    break;
                }
            }
        }

        if ($tweetUrl === '') {
            continue;
        }

        $timeNodes = $xpath->query('.//time', $article);

        $date = '';

        if ($timeNodes && $timeNodes->length > 0) {
            $date = $timeNodes->item(0)->getAttribute('datetime');
        }

        $tweets[] = [
            'text' => $text,
            'url' => $tweetUrl,
            'date' => $date
        ];
    }

    return $tweets;
}

function createRSS(
    string $username,
    array $tweets
): string {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    $rss = $xml->createElement('rss');
    $rss->setAttribute('version', '2.0');

    $channel = $xml->createElement('channel');

    $title = $xml->createElement(
        'title',
        '@' . $username . ' — X RSS'
    );

    $description = $xml->createElement(
        'description',
        'RSS feed for X profile @' . $username
    );

    $link = $xml->createElement(
        'link',
        'https://x.com/' . $username
    );

    $channel->appendChild($title);
    $channel->appendChild($description);
    $channel->appendChild($link);

    foreach ($tweets as $tweet) {
        $item = $xml->createElement('item');

        $itemTitle = $tweet['text'] !== ''
            ? $tweet['text']
            : '@' . $username . ' post';

        $item->appendChild(
            $xml->createElement(
                'title',
                $itemTitle
            )
        );

        $item->appendChild(
            $xml->createElement(
                'link',
                $tweet['url']
            )
        );

        $item->appendChild(
            $xml->createElement(
                'guid',
                $tweet['url']
            )
        );

        if ($tweet['date'] !== '') {
            $timestamp = strtotime($tweet['date']);

            if ($timestamp !== false) {
                $item->appendChild(
                    $xml->createElement(
                        'pubDate',
                        gmdate('D, d M Y H:i:s O', $timestamp)
                    )
                );
            }
        }

        $description = $xml->createElement('description');

        $description->appendChild(
            $xml->createCDATASection(
                $tweet['text']
            )
        );

        $item->appendChild($description);

        $channel->appendChild($item);
    }

    $rss->appendChild($channel);
    $xml->appendChild($rss);

    return $xml->saveXML();
}

$username = trim(
    (string)($_GET['x'] ?? '')
);

$limit = (int)(
    $_GET['l'] ?? DEFAULT_LIMIT
);

if ($username === '') {
    fail('Missing username');
}

if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $username)) {
    fail('Invalid username');
}

$limit = max(
    1,
    min(MAX_LIMIT, $limit)
);

try {
    $pdo = getDatabase();

    $stmt = $pdo->prepare(
        'SELECT content, fetched_at
         FROM cache
         WHERE username = :username'
    );

    $stmt->execute([
        ':username' => $username
    ]);

    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        $cached &&
        (time() - (int)$cached['fetched_at']) < CACHE_TTL
    ) {
        echo $cached['content'];
        exit;
    }

    $url = 'https://x.com/' . rawurlencode($username);

    $html = fetchUrl($url);

    $tweets = extractTweets($html);

    if (!$tweets) {
        if ($cached) {
            echo $cached['content'];
            exit;
        }

        fail(
           
