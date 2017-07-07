<?php

namespace Drupal\web_page_archive\Parser;

use GuzzleHttp\Client;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Parses a remote XML sitemap.
 */
class SitemapParser {

  /**
   * Http client.
   *
   * @var object
   */
  private $httpClient;

  /**
   * Constructor for Sitemap Parser.
   */
  public function __construct($handler = NULL) {
    $client_options = [];
    if (isset($handler)) {
      $client_options['handler'] = $handler;
    }
    $this->httpClient = new Client($client_options);
  }

  /**
   * Parses a sitemap URL.
   *
   * @throws \GuzzleHttp\Exception\ClientException
   *   Exception when a client error is encountered (4xx codes).
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   HTTP Request exception.
   *
   * @throws \Symfony\Component\Serializer\Exception\UnexpectedValueException
   *   If XML decoding fails.
   */
  public function parse($url) {
    // Retrieve sitemap contents.
    $response = $this->httpClient->request('GET', $url);

    // Decode sitemap.
    $encoder = new XmlEncoder();
    $decoded = $encoder->decode($response->getBody(), 'xml');

    // Loop through each sitemap entry.
    $urls = [];
    if (!empty($decoded['url'])) {
      foreach ($decoded['url'] as $sitemap_entry) {
        // TODO: xhtml:link support?
        // See: https://support.google.com/webmasters/answer/2620865?hl=en
        $urls[] = $sitemap_entry['loc'];
      }
    }

    return $urls;
  }

}
