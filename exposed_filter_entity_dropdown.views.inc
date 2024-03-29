<?php

/**
 * @file
 * Provide views data for exposed_filter_entity_dropdown.module.
 */

use Drupal\field\FieldStorageConfigInterface;

/**
 * Implements hook_field_views_data_alter().
 *
 * Views integration for entity reference fields which reference nodes.
 * Adds a new type of filter (autocomplete) to the list of filter criteria,
 * allowing to select the type of filter (core's numeric or this module's
 * autocomplete).
 *
 * @see views_field_default_views_data()
 * @todo Check for side effects with views team
 */
function exposed_filter_entity_dropdown_field_views_data_alter(array &$data, FieldStorageConfigInterface $field_storage) {
  if ($field_storage->getType() == 'entity_reference' && $field_storage->getSetting('target_type') == 'node') {
    $entity_type_id = $field_storage->getTargetEntityTypeId();
    $entity_manager = \Drupal::entityTypeManager();
    $table_mapping = $entity_manager->getStorage($entity_type_id)->getTableMapping();
    $column_real_name = $table_mapping->getFieldColumnName($field_storage, 'target_id');

    foreach ($data as $table_name => $table_data) {
      if (isset($data[$table_name][$column_real_name])) {
        $title_autocomplete = $data[$table_name][$column_real_name]['title'] . ' [Autocomplete Filter]';
        $title_short_autocomplete = $data[$table_name][$column_real_name]['title short'] . '[Autocomplete Filter]';
        $data[$table_name]['autocomplete_' . $column_real_name] = [
          'group' => $data[$table_name][$column_real_name]['group'],
          'title' => $title_autocomplete,
          'title short' => $title_short_autocomplete,
          'help' => $data[$table_name][$column_real_name]['help'],
        ];
        $data[$table_name]['autocomplete_' . $column_real_name]['filter'] = $data[$table_name][$column_real_name]['filter'];
        $data[$table_name]['autocomplete_' . $column_real_name]['filter']['id'] = 'node_index_nid';
      }
    }
  }
}

/**
 * Implements hook_views_data_alter().
 */
function exposed_filter_entity_dropdown_views_data_alter(array &$data) {
  $data['node_field_data']['autocomplete_uid'] = [
    'title' => t('Authored By [Autocomplete Filter]'),
    'group' => t('Content'),
    'title short' => t('Authored By [Autocomplete Filter]'),
    'help' => '',
    'filter' => [
      'title' => t('Authored By [Autocomplete Filter]'),
      'help' => t('Authored By [Autocomplete Filter]'),
      'field' => 'uid',
      'id' => 'user_index_uid',
    ],
  ];
}
