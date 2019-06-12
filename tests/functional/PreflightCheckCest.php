<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace crafttests\functional;

use Codeception\Example;
use Craft;
use craft\elements\User;
use FunctionalTester;

/**
 * Class PreflightCheckCest
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.2
 */
class PreflightCheckCest
{
    // Public Methods
    // =========================================================================

    // Tests
    // =========================================================================

    /**
     * @param FunctionalTester $I
     */
    public function _before(FunctionalTester $I)
    {
        $userEl = User::find()
            ->admin()
            ->one();

        Craft::$app->getUser()->setIdentity($userEl);
    }

    /**
     * @param FunctionalTester $I
     * @param Example $example
     * @dataProvider pagesDataProvider
     */
    public function test200Page(FunctionalTester $I, Example $example)
    {
        $adminTrigger = Craft::$app->getConfig()->getGeneral()->cpTrigger;

        $I->amOnPage('/'.$adminTrigger.''.$example['url']);
        $I->seeInTitle($example['title']);
        $I->seeResponseCodeIs(200);

        if (isset($example['extraContent'])) {
            foreach ($example['extraContent'] as $extraContent) {
                $I->see($extraContent);
            }
        }
    }

    // Protected Methods
    // =========================================================================

    // Data providers
    // =========================================================================

    protected function pagesDataProvider() : array
    {
        return [
            ['url' => '/dashboard', 'title' => 'Dashboard'],
            ['url' => '/entries', 'title' => 'Entries'],
            ['url' => '/users', 'title' => 'Users'],
            // TODO: fix globals fixture     ['url' => '/globals', 'title' => 'Globals'],
            // TODO: Requires fixtures data. ['url' => '/categories', 'title' => 'Categories'],
            ['url' => '/settings/plugins', 'title' => 'Plugins'],
            ['url' => '/settings/sections', 'title' => 'Sections', 'extraContent' => [
                'Craft CMS Test section'
            ]],
            ['url' => '/settings/sites', 'title' => 'Sites', 'extraContent' => [
                'Craft CMS testing'
            ]],
            ['url' => '/utilities', 'title' => 'System Report', 'extraContent' => [
                'Application Info',
                'Yii version',
                'Plugins',
                'Requirements'
            ]],
            ['url' => '/utilities/updates', 'title' => 'Updates', 'extraContent' => [
                'Craft CMS',
                'Update'
            ]],
            // TODO: This fails
            /**['url' => '/utilities/php-info', 'title' => 'PHP Info', 'extraContent' => [
                'allow_url_fopen'
            ]],**/
            ['url' => '/utilities/system-messages', 'title' => 'System Messages', 'extraContent' => [
                'When someone creates an account'
            ]],
            ['url' => '/utilities/search-indexes', 'title' => 'Search Indexes'],
            ['url' => '/utilities/asset-indexes', 'title' => 'Asset Indexes', 'extraContent' => [
                'Test volume 1'
            ]],
            ['url' => '/utilities/deprecation-errors', 'title' => 'Deprecation Warnings', 'extraContent' => [
                'No deprecation errors to report!'
            ]],
            ['url' => '/utilities/find-replace', 'title' => 'Find and Replace', 'extraContent' => [
                'Find Text',
                'Replace Text'
            ]],
            ['url' => '/utilities/migrations', 'title' => 'Migrations', 'extraContent' => [
                'No content migrations.'
            ]],
        ];
    }
}
