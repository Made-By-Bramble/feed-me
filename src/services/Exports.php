<?php

namespace craft\feedme\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\feedme\models\ExportModel;
use craft\feedme\models\FeedModel;
use craft\feedme\Plugin;
use craft\feedme\records\ExportRecord;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DateTime;
use Exception;

/**
 * Export management service.
 */
class Exports extends Component
{
    // Constants
    // =========================================================================

    public const TABLE = '{{%feedme_exports}}';
    public const STORAGE_FOLDER = 'feed-me/exports';

    private const SUPPORTED_FORMATS = ['csv', 'json', 'xml'];
    private const DEFAULT_EXPIRY_SECONDS = 604800;

    // Public Methods
    // =========================================================================

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    public function isFeedExportable(?FeedModel $feed): bool
    {
        if (!$feed) {
            return false;
        }

        if (!in_array($feed->feedType, self::SUPPORTED_FORMATS, true)) {
            return false;
        }

        return (bool)$feed->getElement() && !empty($feed->fieldMapping);
    }

    public function normalizeFormat(?string $format, FeedModel $feed): ?string
    {
        $format = $format ?: $feed->feedType;
        $format = strtolower((string)$format);

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            return null;
        }

        return $format;
    }

    /**
     * @throws Exception
     */
    public function createExport(FeedModel $feed, ?User $user, ?string $format = null): ExportModel
    {
        $format = $this->normalizeFormat($format, $feed);

        if (!$format || !$this->isFeedExportable($feed)) {
            throw new Exception(Craft::t('feed-me', 'This feed type cannot be exported.'));
        }

        $token = StringHelper::randomString(40);
        $filename = $this->createFilename($feed, $format);

        $export = new ExportModel([
            'feedId' => $feed->id,
            'userId' => $user?->id,
            'format' => $format,
            'status' => ExportModel::STATUS_PENDING,
            'filename' => $filename,
            'path' => $token . DIRECTORY_SEPARATOR . $filename,
            'token' => $token,
            'dateExpires' => $this->createExpiryDate($feed),
        ]);

        $this->saveExport($export);

        return $export;
    }

    /**
     * @throws Exception
     */
    public function saveExport(ExportModel $model, bool $runValidation = true): bool
    {
        if ($runValidation && !$model->validate()) {
            Craft::info('Export not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($model->id) {
            $record = ExportRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('feed-me', 'No export exists with the ID “{id}”.', ['id' => $model->id]));
            }
        } else {
            $record = new ExportRecord();
        }

        $record->feedId = $model->feedId;
        $record->userId = $model->userId;
        $record->format = $model->format;
        $record->status = $model->status;
        $record->filename = $model->filename;
        $record->path = $model->path;
        $record->token = $model->token;
        $record->rowCount = $model->rowCount;
        $record->fileSize = $model->fileSize;
        $record->error = $model->error;
        $record->dateExpires = $model->dateExpires ? Db::prepareDateForDb($model->dateExpires) : null;

        $record->save(false);

        if (!$model->id) {
            $model->id = $record->id;
        }

        return true;
    }

    public function getExportById(int $id): ?ExportModel
    {
        return $this->createModelFromRecord(ExportRecord::findOne($id));
    }

    public function getExportByToken(string $token): ?ExportModel
    {
        return $this->createModelFromRecord(ExportRecord::findOne(['token' => $token]));
    }

    /**
     * @throws Exception
     */
    public function markRunning(ExportModel $export): bool
    {
        $export->status = ExportModel::STATUS_RUNNING;
        $export->error = null;

        return $this->saveExport($export, false);
    }

    /**
     * @throws Exception
     */
    public function markComplete(ExportModel $export, int $rowCount, ?int $fileSize = null): bool
    {
        $export->status = ExportModel::STATUS_COMPLETE;
        $export->rowCount = $rowCount;
        $export->fileSize = $fileSize;
        $export->error = null;

        return $this->saveExport($export, false);
    }

    /**
     * @throws Exception
     */
    public function markFailed(ExportModel $export, string $error): bool
    {
        $export->status = ExportModel::STATUS_FAILED;
        $export->error = $error;

        return $this->saveExport($export, false);
    }

    /**
     * @throws \yii\base\Exception
     */
    public function getExportFilePath(ExportModel $export): string
    {
        return $this->getStoragePath() . DIRECTORY_SEPARATOR . $export->path;
    }

    /**
     * @throws \yii\base\Exception
     */
    public function prepareExportDirectory(ExportModel $export): string
    {
        $directory = dirname($this->getExportFilePath($export));
        FileHelper::createDirectory($directory);

        return $directory;
    }

    /**
     * @throws \yii\base\Exception
     */
    public function getStoragePath(): string
    {
        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . self::STORAGE_FOLDER;
    }

    public function createFilename(FeedModel $feed, string $format): string
    {
        $name = StringHelper::toKebabCase($feed->name ?: 'feed-export', '-', true);
        $date = gmdate('Ymd-His');

        return sprintf('%s-%s.%s', $name, $date, $format);
    }

    // Private Methods
    // =========================================================================

    private function createExpiryDate(FeedModel $feed): DateTime
    {
        $expirySeconds = Plugin::$plugin->service->getConfig('exportExpirySeconds', $feed->id) ?: self::DEFAULT_EXPIRY_SECONDS;

        return new DateTime('+' . (int)$expirySeconds . ' seconds');
    }

    private function createModelFromRecord(?ExportRecord $record): ?ExportModel
    {
        if (!$record) {
            return null;
        }

        $attributes = $record->toArray();
        $attributes['dateExpires'] = DateTimeHelper::toDateTime($attributes['dateExpires']);
        $attributes['dateCreated'] = DateTimeHelper::toDateTime($attributes['dateCreated']);
        $attributes['dateUpdated'] = DateTimeHelper::toDateTime($attributes['dateUpdated']);
        $attributes['fileSize'] = $attributes['fileSize'] !== null ? (int)$attributes['fileSize'] : null;
        $attributes['rowCount'] = (int)$attributes['rowCount'];

        return new ExportModel($attributes);
    }
}
