<?php

namespace craft\feedme\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * Class ExportModel
 */
class ExportModel extends Model
{
    // Constants
    // =========================================================================

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    // Properties
    // =========================================================================

    public ?int $id = null;
    public ?int $feedId = null;
    public ?int $userId = null;
    public string $format = '';
    public string $status = self::STATUS_PENDING;
    public ?string $filename = null;
    public ?string $path = null;
    public ?string $token = null;
    public int $rowCount = 0;
    public ?int $fileSize = null;
    public ?string $error = null;
    public ?DateTime $dateExpires = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    // Public Methods
    // =========================================================================

    public function __toString()
    {
        return $this->filename ?? Craft::t('feed-me', 'Export #{id}', ['id' => $this->id]);
    }

    public function getIsExpired(): bool
    {
        if (!$this->dateExpires) {
            return false;
        }

        return $this->dateExpires < new DateTime();
    }

    public function rules(): array
    {
        return [
            [['feedId', 'format', 'status', 'filename', 'path', 'token'], 'required'],
            [['feedId', 'userId', 'rowCount', 'fileSize'], 'integer'],
            [['format', 'status', 'filename', 'path', 'token', 'error'], 'string'],
            [['dateExpires'], 'date'],
            [['format'], 'in', 'range' => ['csv', 'json', 'xml']],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_COMPLETE,
                self::STATUS_FAILED,
            ]],
        ];
    }
}
