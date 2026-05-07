<?php

namespace craft\feedme\models;

use Craft;
use craft\base\Model;
use craft\validators\DateTimeValidator;
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

    /**
     * @var int|null
     */
    public $id;

    /**
     * @var int|null
     */
    public $feedId;

    /**
     * @var int|null
     */
    public $userId;

    /**
     * @var string
     */
    public $format = '';

    /**
     * @var string
     */
    public $status = self::STATUS_PENDING;

    /**
     * @var string|null
     */
    public $filename;

    /**
     * @var string|null
     */
    public $path;

    /**
     * @var string|null
     */
    public $token;

    /**
     * @var int
     */
    public $rowCount = 0;

    /**
     * @var int|null
     */
    public $fileSize;

    /**
     * @var string|null
     */
    public $error;

    /**
     * @var DateTime|null
     */
    public $dateExpires;

    /**
     * @var DateTime|null
     */
    public $dateCreated;

    /**
     * @var DateTime|null
     */
    public $dateUpdated;

    /**
     * @var string|null
     */
    public $uid;

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
            [['dateExpires'], DateTimeValidator::class],
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
