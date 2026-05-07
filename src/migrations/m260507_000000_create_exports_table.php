<?php

namespace craft\feedme\migrations;

use craft\db\Migration;

/**
 * m260507_000000_create_exports_table migration.
 */
class m260507_000000_create_exports_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
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

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%feedme_exports}}');

        return true;
    }
}
