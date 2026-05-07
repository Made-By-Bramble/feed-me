<?php

namespace craft\feedme\migrations;

use craft\db\Migration;

class Install extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp()
    {
        $this->createTables();

        return true;
    }

    public function safeDown()
    {
        $this->removeTables();

        return true;
    }

    // Protected Methods
    // =========================================================================

    protected function createTables()
    {
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
            'fieldMapping' => $this->text(),
            'fieldUnique' => $this->text(),
            'passkey' => $this->string()->notNull(),
            'backup' => $this->boolean()->notNull()->defaultValue(false),
            'setEmptyValues' => $this->boolean()->notNull()->defaultValue(false),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        if (!$this->db->tableExists('{{%feedme_exports}}')) {
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
        }
    }

    protected function removeTables()
    {
        $this->dropTableIfExists('{{%feedme_exports}}');
        $this->dropTableIfExists('{{%feedme_feeds}}');
    }
}
