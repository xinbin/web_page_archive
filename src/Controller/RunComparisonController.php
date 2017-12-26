<?php

namespace Drupal\web_page_archive\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Tags;
use Drupal\Component\Utility\Unicode;
use Drupal\web_page_archive\Entity\RunComparisonInterface;
use Drupal\web_page_archive\Entity\Sql\WebPageArchiveRunStorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a route controller for comparing two run revisions.
 */
class RunComparisonController extends ControllerBase {

  private $runStorage;

  /**
   * Constructs a base class for autocompletion.
   *
   * @param \Drupal\web_page_archive\Entity\Sql\WebPageArchiveRunStorageInterface $run_storage
   *   Web page archive run storage.
   */
  public function __construct(WebPageArchiveRunStorageInterface $run_storage) {
    $this->runStorage = $run_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('web_page_archive_run')
    );
  }

  /**
   * Generates a label for the specified revision.
   */
  public static function generateRevisionLabel($vid, $name, $timestamp) {
    $timezone = \drupal_get_user_timezone();
    $date = \Drupal::service('date.formatter')
      ->format($timestamp, 'custom', 'Y-m-d H:i:s', $timezone);
    return "{$name}: {$date} ($vid)";
  }

  /**
   * Handler for autocomplete request.
   */
  public function handleRunAutocomplete(Request $request) {
    $results = [];
    $max_results = 15;
    $revisions = $this->runStorage->fullRevisionList();

    if ($input = $request->query->get('q')) {
      $typed_string = Tags::explode($input);
      $typed_string = Unicode::strtolower(array_pop($typed_string));
      $count = 0;
      foreach ($revisions as $revision) {
        $label = static::generateRevisionLabel($revision->vid, $revision->name, $revision->revision_created);
        if (stristr($label, $typed_string) !== FALSE) {
          $results[] = ['label' => $label, 'value' => $revision->vid];
          $count++;
          if ($count > $max_results) {
            break;
          }
        }
      }
    }

    return new JsonResponse($results);
  }

  /**
   * Generates a matrix of captured URLs for each run.
   */
  public static function generateRunMatrix($runs) {
    $urls = [];
    foreach ($runs as $run) {
      foreach ($run->getCapturedArray() as $captured_row) {
        if (empty($captured_row->getValue()['value'])) {
          continue;
        }

        $row_results = unserialize($captured_row->getValue()['value']);
        $response_type = $row_results['capture_response']->getId();
        $capture_key = $row_results['capture_url'];
        $run_id = $run->getRevisionId();
        $delta = $row_results['delta'];
        $urls[$response_type][$capture_key][$run_id][$delta] = $row_results;
      }
    }
    return $urls;
  }

  /**
   * Enqueues the run comparisons to be performed.
   */
  public static function enqueueRunComparisons(RunComparisonInterface $run_comparison) {
    // Retrieve and reset our queue.
    $queue = $run_comparison->getQueue();
    $queue->deleteQueue();
    $queue->createQueue();

    // Ensure we have two valid runs to compare.
    $run_entities = $run_comparison->getRunEntities();
    if (count($run_entities) !== 2) {
      throw new \Exception('Invalid comparison object');
    }

    // Determine if URLs are in both runs or just one and mark accordingly.
    $matrix = static::generateRunMatrix($run_entities);
    $left_id = $run_entities[0]->getRevisionId();
    $right_id = $run_entities[1]->getRevisionId();
    foreach ($matrix as $response_type => $capture_list) {
      foreach ($capture_list as $url => $runs) {
        $first_run = isset($runs[$left_id]) ? reset($runs[$left_id]) : reset($runs[$right_id]);
        $compare_class = isset($first_run['capture_response']) ? get_class($first_run['capture_response']) : NULL;
        $item = [
          'compare_class' => $compare_class,
          'left_id' => $left_id,
          'right_id' => $right_id,
          'run_comparison' => $run_comparison,
          'runs' => $runs,
          'url' => $url,
        ];
        $queue->createItem($item);
      }
    }
  }

  /**
   * Marks a capture as complete.
   */
  public static function markCompareComplete($data) {
    $store_data = [
      'url' => $data['url'],
      'delta1' => $data['delta1'],
      'delta2' => $data['delta2'],
      'has_left' => $data['has_left'],
      'has_right' => $data['has_right'],
      'langcode' => $data['run_comparison']->language()->getId(),
      'run1' => $data['left_id'],
      'run2' => $data['right_id'],
      'results' => serialize($data),
      'variance' => $data['variance'],
    ];

    \Drupal::entityTypeManager()->getStorage('wpa_run_comparison')
      ->addResult($data['run_comparison'], $store_data);
  }

  /**
   * Adds up to $items_to_process number of items from the queue to a batch.
   *
   * If $items_to_process < 0 attempt to add entire queue to batch.
   */
  public static function setBatch(RunComparisonInterface $run_comparison, $items_to_process = -1) {
    $queue = $run_comparison->getQueue();
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('web_page_archive_compare');

    // Create capture job batch.
    $batch = [
      'title' => \t('Process all compare queue jobs with batch'),
      'operations' => [],
      'finished' => 'Drupal\web_page_archive\Controller\RunComparisonController::batchFinished',
    ];

    // If negative, or if count is too high, set count to queue size.
    if ($items_to_process < 0 || $items_to_process > $queue->numberOfItems()) {
      $items_to_process = $queue->numberOfItems();
    }

    // Create batch operations.
    for ($i = 0; $i < $items_to_process; $i++) {
      $batch['operations'][] = ['Drupal\web_page_archive\Controller\RunComparisonController::batchProcess', [$run_comparison]];
    }

    // Adds the batch sets.
    batch_set($batch);
  }

  /**
   * Processes a batch request.
   */
  public static function batchProcess(RunComparisonInterface $run_comparison, &$context = NULL) {
    $queue = $run_comparison->getQueue();
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('web_page_archive_compare');

    if ($item = $queue->claimItem()) {
      try {
        $processed = $queue_worker->processItem($item->data);
        if (!isset($processed)) {
          throw new RequeueException(t('Still Running'));
        }
        $queue->deleteItem($item);
        return TRUE;
      }
      catch (RequeueException $e) {
        $queue->releaseItem($item);
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        watchdog_exception($e);
      }
      catch (\Exception $e) {
        // In case of any other kind of exception, log it and remove it from
        // the queue to prevent queues from getting stuck.
        $queue->deleteItem($item);
        watchdog_exception('cron', $e);
      }
    }

    return FALSE;
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \drupal_set_message(\t("The comparison has been completed."));
    }
    else {
      $error_operation = reset($operations);
      $values = [
        '@operation' => $error_operation[0],
        '@args' => print_r($error_operation[0], TRUE),
      ];
      \drupal_set_message(\t('An error occurred while processing @operation with arguments : @args', $values));
    }
  }

  /**
   * Displays the run summary for the specified run comparison object.
   */
  public static function summary($wpa_run_comparison) {
    return [
      '#markup' => 'TODO: Add something here',
    ];
  }

  /**
   * Displays the summary title for the specified run comparison object.
   */
  public static function summaryTitle($wpa_run_comparison) {
    return $wpa_run_comparison->label();
  }

}
