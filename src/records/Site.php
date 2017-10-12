<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Class Site record.
 *
 * @property int       $id        ID
 * @property int       $groupId   Group ID
 * @property string    $name      Name
 * @property string    $handle    Handle
 * @property string    $language  Language
 * @property bool      $primary   Primary
 * @property bool      $hasUrls   Has URLs
 * @property bool      $baseUrl   Base URL
 * @property int       $sortOrder Sort order
 * @property SiteGroup $group     Group
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Site extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%sites}}';
    }

    /**
     * Returns the site’s group.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getGroup(): ActiveQueryInterface
    {
        return $this->hasOne(SiteGroup::class, ['id' => 'siteId']);
    }
}
