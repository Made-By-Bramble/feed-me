<?php

namespace craft\feedme\queue\jobs;

use Craft;
use craft\feedme\models\ExportModel;
use craft\feedme\Plugin;
use craft\queue\BaseJob;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * Generates a Feed Me export file in the background.
 */
class FeedExport extends BaseJob implements RetryableJobInterface
{
    // Properties
    // =========================================================================

    /**
     * @var int
     */
    public $exportId;

    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getTtr()
    {
        $export = Plugin::$plugin->exports->getExportById($this->exportId);
        $feedId = $export ? $export->feedId : null;

        return Plugin::$plugin->service->getConfig('queueTtr', $feedId) ?? Plugin::getInstance()->queue->ttr;
    }

    /**
     * @inheritDoc
     */
    public function canRetry($attempt, $error): bool
    {
        $export = Plugin::$plugin->exports->getExportById($this->exportId);
        $feedId = $export ? $export->feedId : null;
        $attempts = Plugin::$plugin->service->getConfig('queueMaxRetry', $feedId) ?? Plugin::getInstance()->queue->attempts;

        return $attempt < $attempts;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $export = Plugin::$plugin->exports->getExportById($this->exportId);

        if (!$export) {
            throw new \RuntimeException("Invalid export ID: $this->exportId");
        }

        $feed = Plugin::$plugin->feeds->getFeedById($export->feedId);

        if (!$feed) {
            Plugin::$plugin->exports->markFailed($export, Craft::t('feed-me', 'The source feed no longer exists.'));
            throw new \RuntimeException("Invalid feed ID: $export->feedId");
        }

        try {
            Plugin::$plugin->exports->markRunning($export);

            $rowCount = Plugin::$plugin->exports->writeExportFile($export, $feed);
            $filePath = Plugin::$plugin->exports->getExportFilePath($export);
            $fileSize = is_file($filePath) ? filesize($filePath) : null;

            Plugin::$plugin->exports->markComplete($export, $rowCount, $fileSize ?: null);

            $completedExport = Plugin::$plugin->exports->getExportById($export->id);

            if ($completedExport instanceof ExportModel) {
                Plugin::$plugin->exports->sendExportEmail($completedExport);
            }
        } catch (Throwable $e) {
            Plugin::$plugin->exports->markFailed($export, $e->getMessage());
            Craft::$app->getErrorHandler()->logException($e);

            throw $e;
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return string
     */
    protected function defaultDescription(): string
    {
        return Craft::t('feed-me', 'Exporting feed data.');
    }
}
