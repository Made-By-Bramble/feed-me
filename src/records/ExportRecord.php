<?php

namespace craft\feedme\records;

use craft\db\ActiveRecord;

class ExportRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function tableName(): string
    {
        return '{{%feedme_exports}}';
    }
}
