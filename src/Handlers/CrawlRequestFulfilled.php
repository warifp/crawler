<?php

namespace Spatie\Crawler\Handlers;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RedirectMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlerRobots;
use Spatie\Crawler\CrawlSubdomains;
use Spatie\Crawler\CrawlUrl;
use Spatie\Crawler\LinkAdder;
use function GuzzleHttp\Psr7\stream_for;

class CrawlRequestFulfilled
{
    /** @var \Spatie\Crawler\Crawler */
    protected $crawler;

    /** @var \Spatie\Crawler\LinkAdder */
    protected $linkAdder;

    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;

        $this->linkAdder = new LinkAdder($this->crawler);
    }

    public function __invoke(ResponseInterface $response, $index)
    {
        $robots = new CrawlerRobots($response, $this->crawler->mustRespectRobots());

        $crawlUrl = $this->crawler->getCrawlQueue()->getUrlById($index);

        if ($this->crawler->mayExecuteJavaScript()) {
            $html = $this->getBodyAfterExecutingJavaScript($crawlUrl->url);

            $response = $response->withBody(stream_for($html));
        }

        if ($robots->mayIndex()) {
            $this->handleCrawled($response, $crawlUrl);
        }

        if (! $this->crawler->getCrawlProfile() instanceof CrawlSubdomains) {
            if ($crawlUrl->url->getHost() !== $this->crawler->getBaseUrl()->getHost()) {
                return;
            }
        }

        if (! $robots->mayFollow()) {
            return;
        }

        $body = $this->convertBodyToString($response->getBody(), $this->crawler->getMaximumResponseSize());

        $redirectHistory = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
        if (! empty($redirectHistory)) {
            $baseUrl = new Uri(end($redirectHistory));
        } else {
            $baseUrl = $crawlUrl->url;
        }

        $this->linkAdder->addFromHtml($body, $baseUrl);

        usleep($this->crawler->getDelayBetweenRequests());
    }

    protected function handleCrawled(ResponseInterface $response, CrawlUrl $crawlUrl)
    {
        $this->crawler->getCrawlObservers()->crawled($crawlUrl, $response);
    }

    protected function convertBodyToString(StreamInterface $bodyStream, $readMaximumBytes = 1024 * 1024 * 2): string
    {
        $bodyStream->rewind();

        $body = $bodyStream->read($readMaximumBytes);

        return $body;
    }

    protected function getBodyAfterExecutingJavaScript(UriInterface $url): string
    {
        $browsershot = $this->crawler->getBrowsershot();

        $html = $browsershot->setUrl((string) $url)->bodyHtml();

        return html_entity_decode($html);
    }
}
