<?php

namespace craft\feedme\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface as CraftElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\feedme\models\ExportModel;
use craft\feedme\models\FeedModel;
use craft\feedme\Plugin;
use craft\feedme\queue\jobs\FeedExport;
use craft\feedme\records\ExportRecord;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Exception;
use JsonSerializable;
use League\Csv\Writer;
use Throwable;
use Traversable;

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
            'userId' => $user ? $user->id : null,
            'format' => $format,
            'status' => ExportModel::STATUS_PENDING,
            'filename' => $filename,
            'path' => $token . DIRECTORY_SEPARATOR . $filename,
            'token' => $token,
            'dateExpires' => $this->createExpiryDate($feed),
        ]);

        if (!$this->saveExport($export)) {
            throw new Exception(Craft::t('feed-me', 'Unable to save export.'));
        }

        return $export;
    }

    /**
     * @throws Exception
     */
    public function queueExport(FeedModel $feed, User $user, ?string $format = null): ExportModel
    {
        $export = $this->createExport($feed, $user, $format);

        Queue::push(new FeedExport([
            'exportId' => (int)$export->id,
        ]));

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
                throw new Exception(Craft::t('feed-me', 'No export exists with the ID "{id}".', ['id' => $model->id]));
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

    public function getDownloadUrl(ExportModel $export): string
    {
        return UrlHelper::cpUrl('feed-me/exports/download/' . $export->token);
    }

    public function canDownloadExport(ExportModel $export, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($export->userId && (int)$export->userId === (int)$user->id) {
            return true;
        }

        return (bool)$user->admin || Craft::$app->getUser()->checkPermission('accessPlugin-feed-me');
    }

    public function sendExportEmail(ExportModel $export): bool
    {
        if (!$export->userId) {
            return false;
        }

        $user = Craft::$app->getUsers()->getUserById($export->userId);

        if (!$user instanceof User || !$user->email) {
            return false;
        }

        $downloadUrl = $this->getDownloadUrl($export);
        $expires = $export->dateExpires ? Craft::$app->getFormatter()->asDatetime($export->dateExpires) : Craft::t('feed-me', 'never');

        return Craft::$app->getMailer()
            ->compose()
            ->setTo($user)
            ->setSubject(Craft::t('feed-me', 'Your Feed Me export is ready'))
            ->setTextBody(Craft::t('feed-me',
                "Your export \"{filename}\" is ready.\n\nDownload: {url}\n\nYou must be logged in to Craft to access this private file. This link expires {expires}.",
                [
                    'filename' => $export->filename,
                    'url' => $downloadUrl,
                    'expires' => $expires,
                ]
            ))
            ->send();
    }

    /**
     * @throws Exception
     * @throws Throwable
     * @throws \yii\base\Exception
     */
    public function writeExportFile(ExportModel $export, FeedModel $feed): int
    {
        if (!$this->isFeedExportable($feed)) {
            throw new Exception(Craft::t('feed-me', 'This feed type cannot be exported.'));
        }

        $rows = $this->buildRows($feed, $export->format);
        $path = $this->getExportFilePath($export);

        $this->prepareExportDirectory($export);

        switch ($export->format) {
            case 'csv':
                $this->writeCsv($path, $rows, $feed);
                break;
            case 'json':
                $this->writeJson($path, $rows, $feed);
                break;
            case 'xml':
                $this->writeXml($path, $rows, $feed);
                break;
            default:
                throw new Exception(Craft::t('feed-me', 'Unsupported export format "{format}".', ['format' => $export->format]));
        }

        return count($rows);
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function buildRows(FeedModel $feed, string $format): array
    {
        $elementService = $feed->getElement();

        if (!$elementService) {
            throw new Exception(Craft::t('feed-me', 'Unknown Element Type Service called.'));
        }

        $query = $elementService->getQuery($feed);

        if (method_exists($query, 'orderBy')) {
            $query->orderBy(['elements.id' => SORT_ASC]);
        }

        $rows = [];

        if (method_exists($query, 'each')) {
            foreach ($query->each() as $element) {
                if ($element instanceof CraftElementInterface) {
                    $rows[] = $this->mapElement($feed, $element, $format);
                }
            }
        } else {
            foreach ($query->all() as $element) {
                if ($element instanceof CraftElementInterface) {
                    $rows[] = $this->mapElement($feed, $element, $format);
                }
            }
        }

        return $rows;
    }

    /**
     * @throws Throwable
     */
    public function mapElement(FeedModel $feed, CraftElementInterface $element, string $format): array
    {
        $row = [];
        $mapping = $this->getFilteredFieldMapping($feed->fieldMapping);

        foreach ($mapping as $fieldHandle => $fieldInfo) {
            $this->mapField($feed, $element, $row, $fieldHandle, $fieldInfo, $format);
        }

        return $row;
    }

    public function getFilteredFieldMapping($fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        for ($i = 0; $i < 5; $i++) {
            foreach (Hash::flatten($fields) as $key => $value) {
                $parts = explode('.', $key);
                $lastIndex = array_pop($parts);
                $infoPath = implode('.', $parts);

                $node = Hash::get($fields, $infoPath . '.node');

                if ($lastIndex === 'node' && $value === 'noimport') {
                    $fields = Hash::remove($fields, $infoPath);
                }

                if (in_array($lastIndex, ['fields', 'nativeFields', 'attributes'], true) && empty($value)) {
                    if ($node) {
                        $fields = Hash::remove($fields, $infoPath . '.' . $lastIndex);
                    } elseif (
                        Hash::get($fields, $infoPath . '.fields') ||
                        Hash::get($fields, $infoPath . '.nativeFields') ||
                        Hash::get($fields, $infoPath . '.attributes') ||
                        Hash::get($fields, $infoPath . '.blocks')
                    ) {
                        $fields = Hash::remove($fields, $key);
                    } else {
                        $fields = Hash::remove($fields, $infoPath);
                    }
                }

                if ($lastIndex === 'blocks' && empty($value)) {
                    $fields = Hash::remove($fields, $infoPath);
                }
            }
        }

        return $fields;
    }

    // Private Methods
    // =========================================================================

    /**
     * @throws Throwable
     */
    private function mapField(FeedModel $feed, $source, array &$row, string $fieldHandle, array $fieldInfo, string $format): void
    {
        $rawValue = $this->readMappedValue($source, $fieldHandle, $fieldInfo);
        $node = Hash::get($fieldInfo, 'node');

        if ($this->isMappableNode($node) && $this->isReadableMapping($source, $fieldInfo)) {
            $value = $this->normalizeMappedValue($rawValue, $fieldInfo, $feed, $format);
            $this->setMappedValue($row, $node, $value, $format);
        }

        foreach (['attributes', 'nativeFields', 'fields'] as $group) {
            $this->mapNestedFields($feed, $rawValue, $row, Hash::get($fieldInfo, $group, []), $format);
        }

        $this->mapBlocks($feed, $rawValue, $row, Hash::get($fieldInfo, 'blocks', []), $format);
    }

    /**
     * @throws Throwable
     */
    private function mapNestedFields(FeedModel $feed, $source, array &$row, $fields, string $format): void
    {
        if (!is_array($fields) || empty($fields)) {
            return;
        }

        $items = $this->valueToArray($source);

        foreach ($fields as $fieldHandle => $fieldInfo) {
            if (!is_array($fieldInfo)) {
                continue;
            }

            $values = [];
            $rawValues = [];

            foreach ($items as $item) {
                $rawValue = $this->readMappedValue($item, (string)$fieldHandle, $fieldInfo);
                $rawValues[] = $rawValue;

                if ($this->isMappableNode(Hash::get($fieldInfo, 'node'))) {
                    $values[] = $this->normalizeMappedValue($rawValue, $fieldInfo, $feed, $format);
                }
            }

            if (!empty($values)) {
                $value = count($values) === 1 ? reset($values) : $values;
                $this->setMappedValue($row, Hash::get($fieldInfo, 'node'), $value, $format);
            }

            $nestedSource = count($rawValues) === 1 ? reset($rawValues) : $rawValues;

            foreach (['attributes', 'nativeFields', 'fields'] as $group) {
                $this->mapNestedFields($feed, $nestedSource, $row, Hash::get($fieldInfo, $group, []), $format);
            }

            $this->mapBlocks($feed, $nestedSource, $row, Hash::get($fieldInfo, 'blocks', []), $format);
        }
    }

    /**
     * @throws Throwable
     */
    private function mapBlocks(FeedModel $feed, $source, array &$row, $blocks, string $format): void
    {
        if (!is_array($blocks) || empty($blocks)) {
            return;
        }

        $items = array_filter($this->valueToArray($source), function($item) {
            return $item instanceof CraftElementInterface;
        });

        if (!$items) {
            return;
        }

        foreach ($blocks as $blockHandle => $blockInfo) {
            if (!is_array($blockInfo)) {
                continue;
            }

            $blockTypes = array_filter(array_map(function(CraftElementInterface $item) {
                return $this->getBlockTypeHandle($item);
            }, $items));

            $blockItems = array_values(array_filter($items, function(CraftElementInterface $item) use ($blockHandle) {
                return $this->getBlockTypeHandle($item) === (string)$blockHandle;
            }));

            if (!$blockItems && !$blockTypes) {
                $blockItems = $items;
            }

            if (!$blockItems) {
                continue;
            }

            foreach (['attributes', 'nativeFields', 'fields'] as $group) {
                $this->mapNestedFields($feed, $blockItems, $row, Hash::get($blockInfo, $group, []), $format);
            }
        }
    }

    private function readMappedValue($source, string $fieldHandle, array $fieldInfo)
    {
        if (is_array($source)) {
            $handle = Hash::get($fieldInfo, 'handle', $fieldHandle);

            return $source[$handle] ?? $source[$fieldHandle] ?? Hash::get($source, $handle);
        }

        if (!$source instanceof CraftElementInterface) {
            return $source;
        }

        if (Hash::get($fieldInfo, 'field')) {
            return $this->readFieldValue($source, $fieldHandle);
        }

        return $this->readAttributeValue($source, $fieldHandle);
    }

    private function readFieldValue(CraftElementInterface $element, string $fieldHandle)
    {
        try {
            if (method_exists($element, 'getFieldValue')) {
                return $element->getFieldValue($fieldHandle);
            }
        } catch (Throwable $e) {
            // Fall through to attribute/property access for stale mappings.
        }

        return $this->readAttributeValue($element, $fieldHandle);
    }

    private function readAttributeValue(CraftElementInterface $element, string $fieldHandle)
    {
        if (property_exists($element, $fieldHandle)) {
            return $element->{$fieldHandle};
        }

        try {
            if (method_exists($element, 'getAttribute')) {
                $value = $element->getAttribute($fieldHandle);

                if ($value !== null) {
                    return $value;
                }
            }
        } catch (Throwable $e) {
            // Fall through to property access.
        }

        try {
            if (method_exists($element, 'canGetProperty') && $element->canGetProperty($fieldHandle)) {
                return $element->{$fieldHandle};
            }
        } catch (Throwable $e) {
            // Fall through to ArrayHelper.
        }

        return ArrayHelper::getValue($element, $fieldHandle);
    }

    private function normalizeMappedValue($value, array $fieldInfo, FeedModel $feed, string $format)
    {
        $elements = $this->extractElements($value);

        if ($elements !== null) {
            $match = Hash::get($fieldInfo, 'options.match', 'id');

            $value = array_map(function(CraftElementInterface $element) use ($match) {
                return $this->getElementMatchValue($element, $match);
            }, $elements);

            if (count($value) === 1) {
                $value = reset($value);
            }
        }

        return $this->normalizeValue($value, $feed, $format === 'csv');
    }

    private function normalizeValue($value, FeedModel $feed, bool $forCsv = false)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof CraftElementInterface) {
            return $value->id;
        }

        if ($value instanceof ElementQueryInterface) {
            $value = $value->all();
        }

        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_object($value) && method_exists($value, 'all')) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item, $feed, false);
            }

            if ($forCsv) {
                if ($this->isScalarArray($normalized)) {
                    return implode(Plugin::$plugin->service->getConfig('dataDelimiter', $feed->id), array_map(function($item) {
                        return $this->stringifyScalar($item);
                    }, $normalized));
                }

                return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $normalized;
        }

        if (is_bool($value)) {
            return $forCsv ? (int)$value : $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return $value;
    }

    private function getElementMatchValue(CraftElementInterface $element, string $match)
    {
        if ($match === 'id') {
            return $element->id;
        }

        if ($match === 'sku' && method_exists($element, 'getDefaultVariant')) {
            $variant = $element->getDefaultVariant();

            return $variant ? $variant->sku : null;
        }

        $value = $this->readAttributeValue($element, $match);

        if ($value !== null) {
            return $value;
        }

        return $this->readFieldValue($element, $match);
    }

    private function extractElements($value): ?array
    {
        if ($value instanceof CraftElementInterface) {
            return [$value];
        }

        if ($value instanceof ElementQueryInterface) {
            return $value->all();
        }

        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_object($value) && method_exists($value, 'all')) {
            $value = $value->all();
        }

        if (!is_array($value)) {
            return null;
        }

        $elements = array_values(array_filter($value, function($item) {
            return $item instanceof CraftElementInterface;
        }));

        return $elements ? $elements : null;
    }

    private function valueToArray($value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return $value->all();
        }

        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value) && method_exists($value, 'all')) {
            return $value->all();
        }

        if (is_array($value)) {
            return $this->isListArray($value) ? $value : [$value];
        }

        if ($value === null) {
            return [];
        }

        return [$value];
    }

    private function setMappedValue(array &$row, string $node, $value, string $format): void
    {
        if ($format === 'csv') {
            $row[$node] = $value;
            return;
        }

        $segments = array_values(array_filter(explode('/', $node), function(string $segment) {
            return $segment !== '';
        }));

        if (!$segments) {
            return;
        }

        $current = &$row;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    private function writeCsv(string $path, array $rows, FeedModel $feed): void
    {
        $headers = $this->getMappedNodes($this->getFilteredFieldMapping($feed->fieldMapping));

        foreach ($rows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        $writer = Writer::createFromPath($path, 'w+');
        $writer->setDelimiter(Plugin::$plugin->service->getConfig('csvColumnDelimiter', $feed->id) ?: ',');
        $writer->insertOne($headers);

        foreach ($rows as $row) {
            $writer->insertOne(array_map(function(string $header) use ($row) {
                return $row[$header] ?? null;
            }, $headers));
        }
    }

    private function writeJson(string $path, array $rows, FeedModel $feed): void
    {
        file_put_contents($path, json_encode(
            $this->createDocumentPayload($rows, $feed),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function writeXml(string $path, array $rows, FeedModel $feed): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        [$rootName, $containerSegments, $itemName] = $this->getXmlDocumentShape($feed);

        $root = $document->createElement($rootName);
        $document->appendChild($root);

        $container = $root;

        foreach ($containerSegments as $segment) {
            $child = $document->createElement($segment);
            $container->appendChild($child);
            $container = $child;
        }

        foreach ($rows as $row) {
            $item = $document->createElement($itemName);
            $container->appendChild($item);

            foreach ($row as $key => $value) {
                $this->appendXmlValue($document, $item, (string)$key, $value);
            }
        }

        $document->save($path);
    }

    private function appendXmlValue(DOMDocument $document, DOMElement $parent, string $name, $value): void
    {
        $name = $this->sanitizeXmlName($name);

        if (is_array($value) && $this->isListArray($value)) {
            foreach ($value as $item) {
                $this->appendXmlValue($document, $parent, $name, $item);
            }

            return;
        }

        $element = $document->createElement($name);
        $parent->appendChild($element);

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->appendXmlValue($document, $element, (string)$key, $item);
            }

            return;
        }

        if ($value !== null) {
            $element->appendChild($document->createTextNode($this->stringifyScalar($value)));
        }
    }

    private function createDocumentPayload(array $rows, FeedModel $feed): array
    {
        $segments = $this->getPrimaryElementSegments($feed);

        if (!$segments) {
            return array_values($rows);
        }

        $payload = [
            array_pop($segments) => array_values($rows),
        ];

        while ($segments) {
            $payload = [
                array_pop($segments) => $payload,
            ];
        }

        return $payload;
    }

    private function getMappedNodes(array $mapping): array
    {
        $nodes = [];

        foreach ($mapping as $fieldInfo) {
            if (!is_array($fieldInfo)) {
                continue;
            }

            $node = Hash::get($fieldInfo, 'node');

            if ($this->isMappableNode($node)) {
                $nodes[] = $node;
            }

            foreach (['attributes', 'nativeFields', 'fields'] as $group) {
                $nodes = array_merge($nodes, $this->getMappedNodes(Hash::get($fieldInfo, $group, [])));
            }

            $blocks = Hash::get($fieldInfo, 'blocks', []);

            if (is_array($blocks)) {
                foreach ($blocks as $blockInfo) {
                    if (!is_array($blockInfo)) {
                        continue;
                    }

                    foreach (['attributes', 'nativeFields', 'fields'] as $group) {
                        $nodes = array_merge($nodes, $this->getMappedNodes(Hash::get($blockInfo, $group, [])));
                    }
                }
            }
        }

        return array_values(array_unique($nodes));
    }

    private function getItemName(FeedModel $feed): string
    {
        $segments = $this->getPrimaryElementSegments($feed, true);
        $name = end($segments);

        if ($name) {
            return $name;
        }

        return 'item';
    }

    private function getXmlDocumentShape(FeedModel $feed): array
    {
        $segments = $this->getPrimaryElementSegments($feed, true);

        if (count($segments) < 2) {
            return ['feed', [], $this->getItemName($feed)];
        }

        $itemName = array_pop($segments);
        $rootName = array_shift($segments);

        return [$rootName, $segments, $itemName];
    }

    private function getPrimaryElementSegments(FeedModel $feed, bool $forXml = false): array
    {
        $primaryElement = $feed->primaryElement;

        if (!$primaryElement) {
            return [];
        }

        $segments = array_values(array_filter(explode('/', $primaryElement), function(string $segment) {
            return $segment !== '';
        }));

        if ($forXml) {
            return array_map(function(string $segment) {
                return $this->sanitizeXmlName($segment);
            }, $segments);
        }

        return $segments;
    }

    private function getBlockTypeHandle(CraftElementInterface $element): ?string
    {
        try {
            if (method_exists($element, 'getType')) {
                $type = $element->getType();

                return $type ? $type->handle : null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private function isMappableNode($node): bool
    {
        return is_string($node) && $node !== '' && !in_array($node, ['noimport', 'usedefault'], true);
    }

    private function isReadableMapping($source, array $fieldInfo): bool
    {
        return is_array($source) || Hash::get($fieldInfo, 'attribute') || Hash::get($fieldInfo, 'field') || Hash::get($fieldInfo, 'nativeField');
    }

    private function isScalarArray(array $value): bool
    {
        foreach ($value as $item) {
            if (!$this->isScalarValue($item)) {
                return false;
            }
        }

        return true;
    }

    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function isScalarValue($value): bool
    {
        return $value === null || is_scalar($value);
    }

    private function stringifyScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return (string)$value;
    }

    private function sanitizeXmlName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?: 'item';
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'item';
        }

        if (!preg_match('/^[A-Za-z_]/', $name) || preg_match('/^xml/i', $name)) {
            $name = 'item-' . $name;
        }

        return $name;
    }

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
