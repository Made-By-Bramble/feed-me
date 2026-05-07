<?php

namespace craft\feedme\migrations;

use craft\db\Migration;

class Install extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        $this->createTables();

        return true;
    }

    public function safeDown(): bool
    {
        $this->removeTables();

        return true;
    }

    // Protected Methods
    // =========================================================================

    protected function createTables(): void
    {
        $this->archiveTableIfExists('{{%feedme_feeds}}');
        $this->createTable('{{%feedme_feeds}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'feedUrl' => $this->text()->notNull(),
            'feedType' => $this->string(),
            'primaryElement' => $this->string(),
            'elementType' => $this->string()->notNull(),
            'elementGroup' => $this->text(),
            'siteId' => $this->string(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'singleton' => $this->boolean()->notNull()->defaultValue(false),
            'duplicateHandle' => $this->text(),
            'updateSearchIndexes' => $this->boolean()->notNull()->defaultValue(true),
            'paginationNode' => $this->text(),
            'fieldMapping' => $this->mediumText(),
            'fieldUnique' => $this->text(),
            'passkey' => $this->string()->notNull(),
            'backup' => $this->boolean()->notNull()->defaultValue(false),
            'setEmptyValues' => $this->boolean()->notNull()->defaultValue(false),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // @see \craft\feedme\migrations\m240611_134740_create_logs_table
        $this->archiveTableIfExists('{{%feedme_logs}}');
        $this->createTable('{{%feedme_logs}}', [
            'id' => $this->bigPrimaryKey(),
            'level' => $this->integer(),
            'category' => $this->string(),
            'log_time' => $this->double(),
            'prefix' => $this->text(),
            'message' => $this->text(),
        ]);

        $this->createIndex('idx_log_level', '{{%feedme_logs}}', 'level');
        $this->createIndex('idx_log_category', '{{%feedme_logs}}', 'category');

        $this->archiveTableIfExists('{{%feedme_exports}}');
        $this->createTable('{{%feedme_exports}}', [
            'id' => $this->primaryKey(),
            'feedId' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'format' => $this->string(10)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'filename' => $this->string()->notNull(),
            'path' => $this->text()->notNull(),
            'token' => $this->string(64)->notNull(),
            'rowCount' => $this->integer()->notNull()->defaultValue(0),
            'fileSize' => $this->bigInteger()->unsigned(),
            'error' => $this->text(),
            'dateExpires' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex('idx_feedme_exports_feedId', '{{%feedme_exports}}', 'feedId');
        $this->createIndex('idx_feedme_exports_userId', '{{%feedme_exports}}', 'userId');
        $this->createIndex('idx_feedme_exports_status', '{{%feedme_exports}}', 'status');
        $this->createIndex('idx_feedme_exports_token', '{{%feedme_exports}}', 'token', true);
        $this->createIndex('idx_feedme_exports_dateExpires', '{{%feedme_exports}}', 'dateExpires');

        $this->archiveTableIfExists('{{%feedme_sequences}}');
        $this->createTable('{{%feedme_sequences}}', [
            'id' => $this->primaryKey(),
            'key' => $this->string()->notNull(),
            'feedId' => $this->integer()->notNull(),
            'options' => $this->text(),
            'timestamp' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex('idx_sequence_key', '{{%feedme_sequences}}', 'key');
        $this->createIndex('idx_sequence_feedId', '{{%feedme_sequences}}', 'feedId');
    }

    protected function removeTables(): void
    {
        $this->dropTableIfExists('{{%feedme_feeds}}');
        $this->dropTableIfExists('{{%feedme_logs}}');
        $this->dropTableIfExists('{{%feedme_exports}}');
    }
}
