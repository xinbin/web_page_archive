<?php

namespace Drupal\web_page_archive\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\web_page_archive\Parser\SitemapParser;
use Drupal\web_page_archive\Plugin\CaptureUtilityInterface;

/**
 * Defines the Web Page Archive entity.
 *
 * @ConfigEntityType(
 *   id = "web_page_archive",
 *   label = @Translation("Web Page Archive"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\web_page_archive\WebPageArchiveListBuilder",
 *     "form" = {
 *       "add" = "Drupal\web_page_archive\Form\WebPageArchiveForm",
 *       "edit" = "Drupal\web_page_archive\Form\WebPageArchiveForm",
 *       "delete" = "Drupal\web_page_archive\Form\WebPageArchiveDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\web_page_archive\WebPageArchiveHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "web_page_archive",
 *   admin_permission = "administer web page archive",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/development/web-page-archive/{web_page_archive}",
 *     "add-form" = "/admin/config/development/web-page-archive/add",
 *     "edit-form" = "/admin/config/development/web-page-archive/{web_page_archive}/edit",
 *     "delete-form" = "/admin/config/development/web-page-archive/{web_page_archive}/delete",
 *     "collection" = "/admin/config/development/web-page-archive"
 *   },
 *   config_export = {
 *     "id",
 *     "uuid",
 *     "label",
 *     "sitemap_url",
 *     "cron_schedule",
 *     "capture_screenshot",
 *     "capture_html",
 *     "capture_utilities"
 *   }
 * )
 */
class WebPageArchive extends ConfigEntityBase implements WebPageArchiveInterface, EntityWithPluginCollectionInterface {

  /**
   * The Web Page Archive ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Web Page Archive label.
   *
   * @var string
   */
  protected $label;

  /**
   * The XML sitemap URL.
   *
   * @var uri
   */
  protected $sitemap_url;

  /**
   * The cron schedule.
   *
   * @var string
   */
  protected $cron_schedule;

  /**
   * Boolean indicating if entity captures HTML.
   *
   * @var bool
   */
  protected $capture_html;

  /**
   * Boolean indicating if entity captures screenshot.
   *
   * @var bool
   */
  protected $capture_screenshot;

  /**
   * The array of capture utilities for this archive.
   *
   * @var array
   */
  protected $capture_utilities = [];

  /**
   * Holds the collection of capture utilities that are used by this archive.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $capture_utility_collection;

  /**
   * Retrieves the Sitemap URL.
   */
  public function getSitemapUrl() {
    return $this->sitemap_url;
  }

  /**
   * Retrieves the Cron schedule.
   */
  public function getCronSchedule() {
    return $this->cron_schedule;
  }

  /**
   * Retrieves whether or not entity captures HTML.
   */
  public function isHtmlCapturing() {
    return $this->capture_html;
  }

  /**
   * Returns whether or not entity captures screenshot.
   */
  public function isScreenshotCapturing() {
    return $this->capture_screenshot;
  }

  /**
   * {@inheritdoc}
   */
  public function getCaptureUtility($capture_utility) {
    return $this->getCaptureUtilities()->get($capture_utility);
  }

  /**
   * {@inheritdoc}
   */
  public function getCaptureUtilities() {
    if (!$this->capture_utility_collection) {
      $this->capture_utility_collection = new DefaultLazyPluginCollection($this->captureUtilityPluginManager(), $this->capture_utilities);
    }
    return $this->capture_utility_collection;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return ['capture_utilities' => $this->getCaptureUtilities()];
  }

  /**
   * {@inheritdoc}
   */
  public function addCaptureUtility(array $configuration) {
    $configuration['uuid'] = $this->uuidGenerator()->generate();
    $this->getCaptureUtilities()->addInstanceId($configuration['uuid'], $configuration);
    return $configuration['uuid'];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteCaptureUtility(CaptureUtilityInterface $capture_utility) {
    $this->getCaptureUtilities()->removeInstanceId($capture_utility->getUuid());
    return $this;
  }

  /**
   * Determines if entity has an instance of the specified plugin id.
   *
   * @param string $id
   *   Capture utility plugin id.
   */
  public function hasCaptureUtilityInstance($id) {
    foreach ($this->capture_utilities as $utility) {
      if ($utility['id'] == $id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Deletes a capture utility by id.
   *
   * @param string $id
   *   Capture utility plugin id.
   */
  public function deleteCaptureUtilityById($id) {
    foreach ($this->capture_utilities as $utility) {
      if ($utility['id'] == $id) {
        $this->getCaptureUtilities()->removeInstanceId($utility['uuid']);
      }
    }
    return $this;
  }

  /**
   * Queues the archive to run.
   */
  public function queueRun() {
    // TODO: Interact with state api to indicate running status.
    try {
      // Retrieve sitemap contents.
      $sitemap_parser = new SitemapParser();
      $urls = $sitemap_parser->parse($this->getSitemapUrl());
      $this->captureUrls($urls);
    }
    catch (Exception $e) {
      // TODO: What to do here? (future task)
      drupal_set_message($e->getMessage(), 'warning');
    }
  }

  /**
   * Captures and stores the specified URL results.
   */
  private function captureUrls(array $urls = []) {
    foreach ($urls as $url) {
      foreach ($this->getCaptureUtilities() as $utility) {
        try {
          // TODO: Send this to Queue API.
          $capture_response = $utility->captureUrl($url)->getResponse();
          // TODO: Store result: $capture_response->getSerialized().
        }
        catch (Exception $e) {
          // TODO: What to do here? (future task)
          drupal_set_message($e->getMessage(), 'warning');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    // TODO: Handle this nonsense in plugins instead (future task).
    $plugin_options = [
      [
        'id' => 'HtmlCaptureUtility',
        'isCapturing' => $this->isHtmlCapturing(),
      ],
      [
        'id' => 'ScreenshotCaptureUtility',
        'isCapturing' => $this->isScreenshotCapturing(),
      ],
    ];

    foreach ($plugin_options as $option) {
      if (!$this->hasCaptureUtilityInstance($option['id']) && $option['isCapturing']) {
        $this->addCaptureUtility(['id' => $option['id']]);
      }
      elseif (!$option['isCapturing']) {
        $this->deleteCaptureUtilityById($option['id']);
      }
    }

    return parent::save();
  }

  /**
   * Wraps the search plugin manager.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   A search plugin manager object.
   */
  protected function captureUtilityPluginManager() {
    return \Drupal::service('plugin.manager.capture_utility');
  }

}
