<?php

use craft\feedme\models\ExportModel;
use craft\feedme\models\FeedModel;
use craft\feedme\services\Exports;
use craft\helpers\FileHelper;
use craft\elements\Entry;

class ExportsTest extends \Codeception\Test\Unit
{
    /**
     * @var Exports
     */
    private $service;

    protected function _before()
    {
        $this->service = new Exports();
    }

    public function testSupportedFormats()
    {
        $this->assertSame(['csv', 'json', 'xml'], $this->service->getSupportedFormats());
    }

    public function testFilteringRemovesUnmappedFields()
    {
        $mapping = [
            'title' => [
                'attribute' => true,
                'node' => 'title',
            ],
            'summary' => [
                'field' => 'craft\fields\PlainText',
                'node' => 'noimport',
            ],
            'matrixField' => [
                'field' => 'craft\fields\Matrix',
                'blocks' => [
                    'body' => [
                        'fields' => [
                            'bodyText' => [
                                'field' => 'craft\fields\PlainText',
                                'node' => 'blocks/bodyText',
                            ],
                            'ignoredText' => [
                                'field' => 'craft\fields\PlainText',
                                'node' => 'noimport',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $filtered = $this->service->getFilteredFieldMapping($mapping);

        $this->assertArrayHasKey('title', $filtered);
        $this->assertArrayNotHasKey('summary', $filtered);
        $this->assertArrayHasKey('bodyText', $filtered['matrixField']['blocks']['body']['fields']);
        $this->assertArrayNotHasKey('ignoredText', $filtered['matrixField']['blocks']['body']['fields']);
    }

    public function testMappedValuesUseCsvHeadersAndJsonPaths()
    {
        $csvRow = [];
        $jsonRow = [];

        $this->setMappedValueForTest($csvRow, 'meta/slug', 'exported-entry', 'csv');
        $this->setMappedValueForTest($jsonRow, 'meta/slug', 'exported-entry', 'json');

        $this->assertSame([
            'meta/slug' => 'exported-entry',
        ], $csvRow);

        $this->assertSame([
            'meta' => [
                'slug' => 'exported-entry',
            ],
        ], $jsonRow);
    }

    public function testWritersCreateCsvJsonAndXmlFiles()
    {
        $feed = $this->createFeed([
            'title' => [
                'attribute' => true,
                'node' => 'title',
            ],
            'slug' => [
                'attribute' => true,
                'node' => 'meta/slug',
            ],
        ]);
        $feed->primaryElement = 'items/item';

        $directory = CRAFT_STORAGE_PATH . '/runtime/feed-me-export-tests';
        FileHelper::createDirectory($directory);

        $csvPath = $directory . '/export.csv';
        $jsonPath = $directory . '/export.json';
        $xmlPath = $directory . '/export.xml';

        $this->invokePrivate('writeCsv', [$csvPath, [
            [
                'title' => 'Entry One',
                'meta/slug' => 'entry-one',
            ],
        ], $feed]);

        $this->assertStringContainsString('title,meta/slug', file_get_contents($csvPath));
        $this->assertStringContainsString('"Entry One",entry-one', file_get_contents($csvPath));

        $this->invokePrivate('writeJson', [$jsonPath, [
            [
                'title' => 'Entry One',
                'meta' => [
                    'slug' => 'entry-one',
                ],
            ],
        ], $feed]);

        $json = json_decode(file_get_contents($jsonPath), true);

        $this->assertSame('Entry One', $json['items']['item'][0]['title']);
        $this->assertSame('entry-one', $json['items']['item'][0]['meta']['slug']);

        $this->invokePrivate('writeXml', [$xmlPath, [
            [
                'title' => 'Entry One',
                'meta' => [
                    'slug' => 'entry-one',
                ],
            ],
        ], $feed]);

        $xml = simplexml_load_file($xmlPath);

        $this->assertSame('items', $xml->getName());
        $this->assertSame('Entry One', (string)$xml->item[0]->title);
        $this->assertSame('entry-one', (string)$xml->item[0]->meta->slug);
    }

    public function testJsonWriterPreservesRootArrays()
    {
        $feed = $this->createFeed([
            'title' => [
                'attribute' => true,
                'node' => 'title',
            ],
        ]);

        $directory = CRAFT_STORAGE_PATH . '/runtime/feed-me-export-tests';
        FileHelper::createDirectory($directory);

        $jsonPath = $directory . '/root-array.json';

        $this->invokePrivate('writeJson', [$jsonPath, [
            [
                'title' => 'Root Entry',
            ],
        ], $feed]);

        $json = json_decode(file_get_contents($jsonPath), true);

        $this->assertSame('Root Entry', $json[0]['title']);
    }

    public function testExportModelAcceptsDateTimeExpiry()
    {
        $model = new ExportModel([
            'feedId' => 1,
            'format' => 'csv',
            'status' => ExportModel::STATUS_PENDING,
            'filename' => 'export.csv',
            'path' => 'token/export.csv',
            'token' => 'token',
            'dateExpires' => new DateTime('+1 day'),
        ]);

        $this->assertTrue($model->validate(), json_encode($model->getErrors(), JSON_UNESCAPED_SLASHES));
    }

    private function createFeed(array $fieldMapping): FeedModel
    {
        return new FeedModel([
            'id' => 1000,
            'name' => 'Export Test',
            'feedUrl' => 'https://example.test/feed.json',
            'feedType' => 'json',
            'elementType' => Entry::class,
            'fieldMapping' => $fieldMapping,
            'duplicateHandle' => ['update'],
            'passkey' => 'test',
        ]);
    }

    private function invokePrivate(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, $arguments);
    }

    private function setMappedValueForTest(array &$row, string $node, $value, string $format)
    {
        $reflection = new ReflectionMethod($this->service, 'setMappedValue');
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, [
            &$row,
            $node,
            $value,
            $format,
        ]);
    }
}
