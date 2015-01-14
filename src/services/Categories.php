<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\enums\ElementType;
use craft\app\errors\Exception;
use craft\app\events\CategoryEvent;
use craft\app\models\Category as CategoryModel;
use craft\app\models\CategoryGroup as CategoryGroupModel;
use craft\app\models\CategoryGroupLocale as CategoryGroupLocaleModel;
use craft\app\models\Structure as StructureModel;
use craft\app\records\Category as CategoryRecord;
use craft\app\records\CategoryGroup as CategoryGroupRecord;
use craft\app\records\CategoryGroupLocale as CategoryGroupLocaleRecord;
use yii\base\Component;

/**
 * Class Categories service.
 *
 * An instance of the Categories service is globally accessible in Craft via [[Application::categories `Craft::$app->categories`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Categories extends Component
{
	// Constants
	// =========================================================================

	/**
     * @event CategoryEvent The event that is triggered before a category is saved.
     *
     * You may set [[CategoryEvent::performAction]] to `false` to prevent the category from getting saved.
     */
    const EVENT_BEFORE_SAVE_CATEGORY = 'beforeSaveCategory';

	/**
     * @event CategoryEvent The event that is triggered after a category is saved.
     */
    const EVENT_AFTER_SAVE_CATEGORY = 'afterSaveCategory';

	/**
     * @event CategoryEvent The event that is triggered before a category is deleted.
     */
    const EVENT_BEFORE_DELETE_CATEGORY = 'beforeDeleteCategory';

	/**
     * @event CategoryEvent The event that is triggered after a category is deleted.
     */
    const EVENT_AFTER_DELETE_CATEGORY = 'afterDeleteCategory';

	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_allGroupIds;

	/**
	 * @var
	 */
	private $_editableGroupIds;

	/**
	 * @var
	 */
	private $_categoryGroupsById;

	/**
	 * @var bool
	 */
	private $_fetchedAllCategoryGroups = false;

	// Public Methods
	// =========================================================================

	// Category groups
	// -------------------------------------------------------------------------

	/**
	 * Returns all of the group IDs.
	 *
	 * @return array
	 */
	public function getAllGroupIds()
	{
		if (!isset($this->_allGroupIds))
		{
			if ($this->_fetchedAllCategoryGroups)
			{
				$this->_allGroupIds = array_keys($this->_categoryGroupsById);
			}
			else
			{
				$this->_allGroupIds = Craft::$app->getDb()->createCommand()
					->select('id')
					->from('categorygroups')
					->queryColumn();
			}
		}

		return $this->_allGroupIds;
	}

	/**
	 * Returns all of the category group IDs that are editable by the current user.
	 *
	 * @return array
	 */
	public function getEditableGroupIds()
	{
		if (!isset($this->_editableGroupIds))
		{
			$this->_editableGroupIds = [];

			foreach ($this->getAllGroupIds() as $groupId)
			{
				if (Craft::$app->getUser()->checkPermission('editCategories:'.$groupId))
				{
					$this->_editableGroupIds[] = $groupId;
				}
			}
		}

		return $this->_editableGroupIds;
	}

	/**
	 * Returns all category groups.
	 *
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getAllGroups($indexBy = null)
	{
		if (!$this->_fetchedAllCategoryGroups)
		{
			$groupRecords = CategoryGroupRecord::model()->with('structure')->ordered()->findAll();

			if (!isset($this->_categoryGroupsById))
			{
				$this->_categoryGroupsById = [];
			}

			foreach ($groupRecords as $groupRecord)
			{
				$this->_categoryGroupsById[$groupRecord->id] = $this->_populateCategoryGroupFromRecord($groupRecord);
			}

			$this->_fetchedAllCategoryGroups = true;
		}

		if ($indexBy == 'id')
		{
			return $this->_categoryGroupsById;
		}
		else if (!$indexBy)
		{
			return array_values($this->_categoryGroupsById);
		}
		else
		{
			$groups = [];

			foreach ($this->_categoryGroupsById as $group)
			{
				$groups[$group->$indexBy] = $group;
			}

			return $groups;
		}
	}

	/**
	 * Returns all editable groups.
	 *
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getEditableGroups($indexBy = null)
	{
		$editableGroupIds = $this->getEditableGroupIds();
		$editableGroups = [];

		foreach ($this->getAllGroups() as $group)
		{
			if (in_array($group->id, $editableGroupIds))
			{
				if ($indexBy)
				{
					$editableGroups[$group->$indexBy] = $group;
				}
				else
				{
					$editableGroups[] = $group;
				}
			}
		}

		return $editableGroups;
	}

	/**
	 * Gets the total number of category groups.
	 *
	 * @return int
	 */
	public function getTotalGroups()
	{
		return count($this->getAllGroupIds());
	}

	/**
	 * Returns a group by its ID.
	 *
	 * @param int $groupId
	 *
	 * @return CategoryGroupModel|null
	 */
	public function getGroupById($groupId)
	{
		if (!isset($this->_categoryGroupsById) || !array_key_exists($groupId, $this->_categoryGroupsById))
		{
			$groupRecord = CategoryGroupRecord::model()->with('structure')->findById($groupId);

			if ($groupRecord)
			{
				$this->_categoryGroupsById[$groupId] = $this->_populateCategoryGroupFromRecord($groupRecord);
			}
			else
			{
				$this->_categoryGroupsById[$groupId] = null;
			}
		}

		return $this->_categoryGroupsById[$groupId];
	}

	/**
	 * Returns a group by its handle.
	 *
	 * @param string $groupHandle
	 *
	 * @return CategoryGroupModel|null
	 */
	public function getGroupByHandle($groupHandle)
	{
		$groupRecord = CategoryGroupRecord::model()->findByAttributes([
			'handle' => $groupHandle
		]);

		if ($groupRecord)
		{
			$group = $this->_populateCategoryGroupFromRecord($groupRecord);
			$this->_categoryGroupsById[$group->id] = $group;

			return $group;
		}
	}

	/**
	 * Returns a group's locales.
	 *
	 * @param int         $groupId
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getGroupLocales($groupId, $indexBy = null)
	{
		$records = CategoryGroupLocaleRecord::model()->findAllByAttributes([
			'groupId' => $groupId
		]);

		return CategoryGroupLocaleModel::populateModels($records, $indexBy);
	}

	/**
	 * Saves a category group.
	 *
	 * @param CategoryGroupModel $group
	 *
	 * @return bool
	 * @throws Exception
	 * @throws \Exception
	 */
	public function saveGroup(CategoryGroupModel $group)
	{
		if ($group->id)
		{
			$groupRecord = CategoryGroupRecord::model()->findById($group->id);

			if (!$groupRecord)
			{
				throw new Exception(Craft::t('No category group exists with the ID “{id}”.', ['id' => $group->id]));
			}

			$oldCategoryGroup = CategoryGroupModel::populateModel($groupRecord);
			$isNewCategoryGroup = false;
		}
		else
		{
			$groupRecord = new CategoryGroupRecord();
			$isNewCategoryGroup = true;
		}

		$groupRecord->name    = $group->name;
		$groupRecord->handle  = $group->handle;
		$groupRecord->hasUrls = $group->hasUrls;

		if ($group->hasUrls)
		{
			$groupRecord->template = $group->template;
		}
		else
		{
			$groupRecord->template = $group->template = null;
		}

		// Make sure that all of the URL formats are set properly
		$groupLocales = $group->getLocales();

		foreach ($groupLocales as $localeId => $groupLocale)
		{
			if ($group->hasUrls)
			{
				$urlFormatAttributes = ['urlFormat'];
				$groupLocale->urlFormatIsRequired = true;

				if ($group->maxLevels == 1)
				{
					$groupLocale->nestedUrlFormat = null;
				}
				else
				{
					$urlFormatAttributes[] = 'nestedUrlFormat';
					$groupLocale->nestedUrlFormatIsRequired = true;
				}

				foreach ($urlFormatAttributes as $attribute)
				{
					if (!$groupLocale->validate([$attribute]))
					{
						$group->addError($attribute.'-'.$localeId, $groupLocale->getError($attribute));
					}
				}
			}
			else
			{
				$groupLocale->urlFormat = null;
				$groupLocale->nestedUrlFormat = null;
			}
		}

		// Validate!
		$groupRecord->validate();
		$group->addErrors($groupRecord->getErrors());

		if (!$group->hasErrors())
		{
			$transaction = Craft::$app->getDb()->getCurrentTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;
			try
			{
				// Create/update the structure

				if ($isNewCategoryGroup)
				{
					$structure = new StructureModel();
				}
				else
				{
					$structure = Craft::$app->structures->getStructureById($oldCategoryGroup->structureId);
				}

				$structure->maxLevels = $group->maxLevels;
				Craft::$app->structures->saveStructure($structure);
				$groupRecord->structureId = $structure->id;
				$group->structureId = $structure->id;

				// Create and set the field layout

				if (!$isNewCategoryGroup && $oldCategoryGroup->fieldLayoutId)
				{
					Craft::$app->fields->deleteLayoutById($oldCategoryGroup->fieldLayoutId);
				}

				$fieldLayout = $group->getFieldLayout();
				Craft::$app->fields->saveLayout($fieldLayout);
				$groupRecord->fieldLayoutId = $fieldLayout->id;
				$group->fieldLayoutId = $fieldLayout->id;

				// Save the category group
				$groupRecord->save(false);

				// Now that we have a category group ID, save it on the model
				if (!$group->id)
				{
					$group->id = $groupRecord->id;
				}

				// Might as well update our cache of the category group while we have it.
				$this->_categoryGroupsById[$group->id] = $group;

				// Update the categorygroups_i18n table
				$newLocaleData = [];

				if (!$isNewCategoryGroup)
				{
					// Get the old category group locales
					$oldLocaleRecords = CategoryGroupLocaleRecord::model()->findAllByAttributes([
						'groupId' => $group->id
					]);
					$oldLocales = CategoryGroupLocaleModel::populateModels($oldLocaleRecords, 'locale');

					$changedLocaleIds = [];
				}

				foreach ($groupLocales as $localeId => $locale)
				{
					// Was this already selected?
					if (!$isNewCategoryGroup && isset($oldLocales[$localeId]))
					{
						$oldLocale = $oldLocales[$localeId];

						// Has the URL format changed?
						if ($locale->urlFormat != $oldLocale->urlFormat || $locale->nestedUrlFormat != $oldLocale->nestedUrlFormat)
						{
							Craft::$app->getDb()->createCommand()->update('categorygroups_i18n', [
								'urlFormat'       => $locale->urlFormat,
								'nestedUrlFormat' => $locale->nestedUrlFormat
							], [
								'id' => $oldLocale->id
							]);

							$changedLocaleIds[] = $localeId;
						}
					}
					else
					{
						$newLocaleData[] = [$group->id, $localeId, $locale->urlFormat, $locale->nestedUrlFormat];
					}
				}

				// Insert the new locales
				Craft::$app->getDb()->createCommand()->insertAll('categorygroups_i18n',
					['groupId', 'locale', 'urlFormat', 'nestedUrlFormat'],
					$newLocaleData
				);

				if (!$isNewCategoryGroup)
				{
					// Drop any locales that are no longer being used, as well as the associated category/element
					// locale rows

					$droppedLocaleIds = array_diff(array_keys($oldLocales), array_keys($groupLocales));

					if ($droppedLocaleIds)
					{
						Craft::$app->getDb()->createCommand()->delete('categorygroups_i18n', ['in', 'locale', $droppedLocaleIds]);
					}
				}

				// Finally, deal with the existing categories...

				if (!$isNewCategoryGroup)
				{
					// Get all of the category IDs in this group
					$criteria = Craft::$app->elements->getCriteria(ElementType::Category);
					$criteria->groupId = $group->id;
					$criteria->status = null;
					$criteria->limit = null;
					$categoryIds = $criteria->ids();

					// Should we be deleting
					if ($categoryIds && $droppedLocaleIds)
					{
						Craft::$app->getDb()->createCommand()->delete('elements_i18n', ['and', ['in', 'elementId', $categoryIds], ['in', 'locale', $droppedLocaleIds]]);
						Craft::$app->getDb()->createCommand()->delete('content', ['and', ['in', 'elementId', $categoryIds], ['in', 'locale', $droppedLocaleIds]]);
					}

					// Are there any locales left?
					if ($groupLocales)
					{
						// Drop the old category URIs if the group no longer has URLs
						if (!$group->hasUrls && $oldCategoryGroup->hasUrls)
						{
							Craft::$app->getDb()->createCommand()->update('elements_i18n',
								['uri' => null],
								['in', 'elementId', $categoryIds]
							);
						}
						else if ($changedLocaleIds)
						{
							foreach ($categoryIds as $categoryId)
							{
								Craft::$app->config->maxPowerCaptain();

								// Loop through each of the changed locales and update all of the categories’ slugs and
								// URIs
								foreach ($changedLocaleIds as $localeId)
								{
									$criteria = Craft::$app->elements->getCriteria(ElementType::Category);
									$criteria->id = $categoryId;
									$criteria->locale = $localeId;
									$criteria->status = null;
									$category = $criteria->first();

									if ($category)
									{
										Craft::$app->elements->updateElementSlugAndUri($category, false, false);
									}
								}
							}
						}
					}
				}

				if ($transaction !== null)
				{
					$transaction->commit();
				}
			}
			catch (\Exception $e)
			{
				if ($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes a category group by its ID.
	 *
	 * @param int $groupId
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public function deleteGroupById($groupId)
	{
		if (!$groupId)
		{
			return false;
		}

		$transaction = Craft::$app->getDb()->getCurrentTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;
		try
		{
			// Delete the field layout
			$fieldLayoutId = Craft::$app->getDb()->createCommand()
				->select('fieldLayoutId')
				->from('categorygroups')
				->where(['id' => $groupId])
				->queryScalar();

			if ($fieldLayoutId)
			{
				Craft::$app->fields->deleteLayoutById($fieldLayoutId);
			}

			// Grab the category ids so we can clean the elements table.
			$categoryIds = Craft::$app->getDb()->createCommand()
				->select('id')
				->from('categories')
				->where(['groupId' => $groupId])
				->queryColumn();

			Craft::$app->elements->deleteElementById($categoryIds);

			$affectedRows = Craft::$app->getDb()->createCommand()->delete('categorygroups', ['id' => $groupId]);

			if ($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Returns whether a group’s categories have URLs, and if the group’s template path is valid.
	 *
	 * @param CategoryGroupModel $group
	 *
	 * @return bool
	 */
	public function isGroupTemplateValid(CategoryGroupModel $group)
	{
		if ($group->hasUrls)
		{
			// Set Craft to the site template path
			$oldTemplatesPath = Craft::$app->path->getTemplatesPath();
			Craft::$app->path->setTemplatesPath(Craft::$app->path->getSiteTemplatesPath());

			// Does the template exist?
			$templateExists = Craft::$app->templates->doesTemplateExist($group->template);

			// Restore the original template path
			Craft::$app->path->setTemplatesPath($oldTemplatesPath);

			if ($templateExists)
			{
				return true;
			}
		}

		return false;
	}

	// Categories
	// -------------------------------------------------------------------------

	/**
	 * Returns a category by its ID.
	 *
	 * @param int      $categoryId
	 * @param int|null $localeId
	 *
	 * @return CategoryModel|null
	 */
	public function getCategoryById($categoryId, $localeId = null)
	{
		return Craft::$app->elements->getElementById($categoryId, ElementType::Category, $localeId);
	}

	/**
	 * Saves a category.
	 *
	 * @param CategoryModel $category
	 *
	 * @throws Exception|\Exception
	 * @return bool
	 */
	public function saveCategory(CategoryModel $category)
	{
		$isNewCategory = !$category->id;

		$hasNewParent = $this->_checkForNewParent($category);

		if ($hasNewParent)
		{
			if ($category->newParentId)
			{
				$parentCategory = $this->getCategoryById($category->newParentId, $category->locale);

				if (!$parentCategory)
				{
					throw new Exception(Craft::t('No category exists with the ID “{id}”.', ['id' => $category->newParentId]));
				}
			}
			else
			{
				$parentCategory = null;
			}

			$category->setParent($parentCategory);
		}

		// Category data
		if (!$isNewCategory)
		{
			$categoryRecord = CategoryRecord::model()->findById($category->id);

			if (!$categoryRecord)
			{
				throw new Exception(Craft::t('No category exists with the ID “{id}”.', ['id' => $category->id]));
			}
		}
		else
		{
			$categoryRecord = new CategoryRecord();
		}

		$categoryRecord->groupId = $category->groupId;

		$categoryRecord->validate();
		$category->addErrors($categoryRecord->getErrors());

		if ($category->hasErrors())
		{
			return false;
		}

		$transaction = Craft::$app->getDb()->getCurrentTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;

		try
		{
			// Fire a 'beforeSaveCategory' event
			$event = new CategoryEvent([
				'category' => $category
			]);

			$this->trigger(static::EVENT_BEFORE_SAVE_CATEGORY, $event);

			// Is the event giving us the go-ahead?
			if ($event->performAction)
			{
				$success = Craft::$app->elements->saveElement($category);

				// If it didn't work, rollback the transaction in case something changed in onBeforeSaveCategory
				if (!$success)
				{
					if ($transaction !== null)
					{
						$transaction->rollback();
					}

					return false;
				}

				// Now that we have an element ID, save it on the other stuff
				if ($isNewCategory)
				{
					$categoryRecord->id = $category->id;
				}

				$categoryRecord->save(false);

				// Has the parent changed?
				if ($hasNewParent)
				{
					if (!$category->newParentId)
					{
						Craft::$app->structures->appendToRoot($category->getGroup()->structureId, $category);
					}
					else
					{
						Craft::$app->structures->append($category->getGroup()->structureId, $category, $parentCategory);
					}
				}

				// Update the category's descendants, who may be using this category's URI in their own URIs
				Craft::$app->elements->updateDescendantSlugsAndUris($category);
			}
			else
			{
				$success = false;
			}

			// Commit the transaction regardless of whether we saved the category, in case something changed
			// in onBeforeSaveCategory
			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		if ($success)
		{
			// Fire an 'afterSaveCategory' event
			$this->trigger(static::EVENT_AFTER_SAVE_CATEGORY, new CategoryEvent([
				'category' => $category
			]));
		}

		return $success;
	}

	/**
	 * Deletes a category(s).
	 *
	 * @param CategoryModel|array $categories
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public function deleteCategory($categories)
	{
		if (!$categories)
		{
			return false;
		}

		$transaction = Craft::$app->getDb()->getCurrentTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;
		try
		{
			if (!is_array($categories))
			{
				$categories = [$categories];
			}

			$success = $this->_deleteCategories($categories, true);

			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		if ($success)
		{
			foreach ($categories as $category)
			{
				// Fire an 'afterDeleteCategory' event
				$this->trigger(static::EVENT_AFTER_DELETE_CATEGORY, new CategoryEvent([
					'category' => $category
				]));
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes an category(s) by its ID.
	 *
	 * @param int|array $categoryId
	 *
	 * @return bool
	 */
	public function deleteCategoryById($categoryId)
	{
		if (!$categoryId)
		{
			return false;
		}

		$criteria = Craft::$app->elements->getCriteria(ElementType::Category);
		$criteria->id = $categoryId;
		$criteria->limit = null;
		$criteria->status = null;
		$criteria->localeEnabled = false;
		$categories = $criteria->find();

		if ($categories)
		{
			return $this->deleteCategory($categories);
		}
		else
		{
			return false;
		}
	}

	/**
	 * Updates a list of category IDs, filling in any gaps in the family tree.
	 *
	 * @param array $ids The original list of category IDs
	 *
	 * @return array The list of category IDs with all the gaps filled in.
	 */
	public function fillGapsInCategoryIds($ids)
	{
		$completeIds = [];

		if ($ids)
		{
			// Make sure that for each selected category, all of its parents are also selected.
			$criteria = Craft::$app->elements->getCriteria(ElementType::Category);
			$criteria->id = $ids;
			$criteria->status = null;
			$criteria->localeEnabled = false;
			$criteria->limit = null;
			$categories = $criteria->find();

			$prevCategory = null;

			foreach ($categories as $i => $category)
			{
				// Did we just skip any categories?
				if ($category->level != 1 && (
					($i == 0) ||
					(!$category->isSiblingOf($prevCategory) && !$category->isChildOf($prevCategory))
				))
				{
					// Merge in all of the entry's ancestors
					$ancestorIds = $category->getAncestors()->ids();
					$completeIds = array_merge($completeIds, $ancestorIds);
				}

				$completeIds[] = $category->id;
				$prevCategory = $category;
			}
		}

		return $completeIds;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Populates a CategoryGroupModel with attributes from a CategoryGroup.
	 *
	 * @param CategoryGroup|null
	 *
	 * @return CategoryGroupModel|null
	 */
	private function _populateCategoryGroupFromRecord($groupRecord)
	{
		if (!$groupRecord)
		{
			return null;
		}

		$group = CategoryGroupModel::populateModel($groupRecord);

		if ($groupRecord->structure)
		{
			$group->maxLevels = $groupRecord->structure->maxLevels;
		}

		return $group;
	}

	/**
	 * Checks if an category was submitted with a new parent category selected.
	 *
	 * @param CategoryModel $category
	 *
	 * @return bool
	 */
	private function _checkForNewParent(CategoryModel $category)
	{
		// Is it a brand new category?
		if (!$category->id)
		{
			return true;
		}

		// Was a new parent ID actually submitted?
		if ($category->newParentId === null)
		{
			return false;
		}

		// Is it set to the top level now, but it hadn't been before?
		if ($category->newParentId === '' && $category->level != 1)
		{
			return true;
		}

		// Is it set to be under a parent now, but didn't have one before?
		if ($category->newParentId !== '' && $category->level == 1)
		{
			return true;
		}

		// Is the newParentId set to a different category ID than its previous parent?
		$criteria = Craft::$app->elements->getCriteria(ElementType::Category);
		$criteria->ancestorOf = $category;
		$criteria->ancestorDist = 1;
		$criteria->status = null;
		$criteria->localeEnabled = null;

		$oldParent = $criteria->first();
		$oldParentId = ($oldParent ? $oldParent->id : '');

		if ($category->newParentId != $oldParentId)
		{
			return true;
		}

		// Must be set to the same one then
		return false;
	}

	/**
	 * Deletes categories, and their descendants.
	 *
	 * @param array $categories
	 * @param bool  $deleteDescendants
	 *
	 * @return bool
	 */
	private function _deleteCategories($categories, $deleteDescendants = true)
	{
		$categoryIds = [];

		foreach ($categories as $category)
		{
			if ($deleteDescendants)
			{
				// Delete the descendants in reverse order, so structures don't get wonky
				$descendants = $category->getDescendants()->status(null)->localeEnabled(false)->order('lft desc')->find();
				$this->_deleteCategories($descendants, false);
			}

			// Fire a 'beforeDeleteCategory' event
			$this->trigger(static::EVENT_BEFORE_DELETE_CATEGORY, new CategoryEvent([
				'category' => $category
			]));

			$categoryIds[] = $category->id;
		}

		// Delete 'em
		return Craft::$app->elements->deleteElementById($categoryIds);
	}
}
