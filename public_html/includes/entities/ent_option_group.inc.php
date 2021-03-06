<?php

  class ent_option_group {
    public $data;
    public $previous;

    public function __construct($group_id=null) {

      if ($group_id !== null) {
        $this->load($group_id);
      } else {
        $this->reset();
      }
    }

    public function reset() {

      $this->data = array();

      $fields_query = database::query(
        "show fields from ". DB_TABLE_OPTION_GROUPS .";"
      );

      while ($field = database::fetch($fields_query)) {
        $this->data[$field['Field']] = null;
      }

      $info_fields_query = database::query(
        "show fields from ". DB_TABLE_OPTION_GROUPS_INFO .";"
      );

      while ($field = database::fetch($info_fields_query)) {
        if (in_array($field['Field'], array('id', 'option_group_id', 'language_code'))) continue;

        $this->data[$field['Field']] = array();
        foreach (array_keys(language::$languages) as $language_code) {
          $this->data[$field['Field']][$language_code] = null;
        }
      }

      $this->data['sort'] = 'alphabetical';
      $this->data['values'] = array();

      $this->previous = $this->data;
    }

    public function load($group_id) {

      if (!preg_match('#^[0-9]+$#', $group_id)) throw new Exception('Invalid option group (ID: '. $group_id .')');

      $this->reset();

      $option_group_query = database::query(
        "select * from ". DB_TABLE_OPTION_GROUPS ."
        where id = ". (int)$group_id ."
        limit 1;"
      );

      if ($option_group = database::fetch($option_group_query)) {
        $this->data = array_replace($this->data, array_intersect_key($option_group, $this->data));
      } else {
        throw new Exception('Could not find option group (ID: '. (int)$group_id .') in database.');
      }

      $option_groups_info_query = database::query(
        "select * from ". DB_TABLE_OPTION_GROUPS_INFO ."
        where group_id = ". (int)$group_id .";"
      );

      while ($option_group_info = database::fetch($option_groups_info_query)) {
        foreach (array_keys($option_group_info) as $key) {
          if (in_array($key, array('id', 'group_id', 'language_code'))) continue;
          $this->data[$key][$option_group_info['language_code']] = $option_group_info[$key];
        }
      }

      $option_values_query = database::query(
        "select * from ". DB_TABLE_OPTION_VALUES ."
        where group_id = ". (int)$group_id ."
        order by priority;"
      );

      while ($option_value = database::fetch($option_values_query)) {
        $this->data['values'][$option_value['id']] = $option_value;

        $option_values_info_query = database::query(
          "select * from ". DB_TABLE_OPTION_VALUES_INFO ."
          where value_id = ". (int)$option_value['id'] .";"
        );

        while ($option_value_info = database::fetch($option_values_info_query)) {
          foreach (array_keys($option_value_info) as $key) {
            if (in_array($key, array('id', 'group_id', 'language_code'))) continue;
            $this->data['values'][$option_value['id']][$key][$option_value_info['language_code']] = $option_value_info[$key];
          }
        }
      }

      $this->previous = $this->data;
    }

    public function save() {

    // Configuration group
      if (empty($this->data['id'])) {
        database::query(
          "insert into ". DB_TABLE_OPTION_GROUPS ."
          (date_created)
          values ('". ($this->data['date_created'] = date('Y-m-d H:i:s')) ."');"
        );
        $this->data['id'] = database::insert_id();
      }

      database::query(
        "update ". DB_TABLE_OPTION_GROUPS ."
        set function = '". database::input($this->data['function']) ."',
        required = '". (!empty($this->data['required']) ? '1' : '0') ."',
        sort = '". database::input($this->data['sort']) ."'
        where id = ". (int)$this->data['id'] ."
        limit 1;"
      );

    // Configuration group info
      foreach (array_keys(language::$languages) as $language_code) {

        $option_groups_info_query = database::query(
          "select id from ". DB_TABLE_OPTION_GROUPS_INFO ."
          where group_id = ". (int)$this->data['id'] ."
          and language_code = '". database::input($language_code) ."'
          limit 1;"
        );

        if (!$option_group_info = database::fetch($option_groups_info_query)) {
          database::query(
            "insert into ". DB_TABLE_OPTION_GROUPS_INFO ."
            (group_id, language_code)
            values (". (int)$this->data['id'] .", '". database::input($language_code) ."');"
          );
          $option_group_info['id'] = database::insert_id();
        }

        database::query(
          "update ". DB_TABLE_OPTION_GROUPS_INFO ."
          set name = '". @database::input($this->data['name'][$language_code]) ."',
            description = '". @database::input($this->data['description'][$language_code]) ."'
          where id = ". (int)$option_group_info['id'] ."
          and group_id = ". (int)$this->data['id'] ."
          and language_code = '". database::input($language_code) ."'
          limit 1;"
        );
      }

    // Delete option values
      $option_values_query = database::query(
        "select id from ". DB_TABLE_OPTION_VALUES ."
        where group_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['values'], 'id')) ."');"
      );

      while ($option_value = database::fetch($option_values_query)) {

        $products_options_stock_query = database::query(
          "select id from ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
          where combination like '%". (int)$this->data['id'] ."-". (int)$option_value['id'] ."%';"
        );
        if (database::num_rows($products_options_stock_query) > 0) throw new Exception('Cannot delete option value linked to products.');

        database::query(
          "delete from ". DB_TABLE_OPTION_VALUES ."
          where group_id = ". (int)$this->data['id'] ."
          and id = ". (int)$option_value['id'] ."
          limit 1;"
        );
        database::query(
          "delete from ". DB_TABLE_OPTION_VALUES_INFO ."
          where value_id = ". (int)$option_value['id'] .";"
        );
      }

    // Update/Insert option values
      $i=0;
      foreach ($this->data['values'] as $option_value) {
        $i++;

        if (empty($option_value['id'])) {
          database::query(
            "insert into ". DB_TABLE_OPTION_VALUES ."
            (group_id)
            values (". (int)$this->data['id'] .");"
          );
          $option_value['id'] = database::insert_id();
        }

        database::query(
          "update ". DB_TABLE_OPTION_VALUES ."
          set value = '". database::input($option_value['value']) ."',
            priority = ". (int)$i ."
          where id = ". (int)$option_value['id'] ."
          limit 1;"
        );

        foreach (array_keys(language::$languages) as $language_code) {
          if (!isset($option_value['name'])) continue;

          $option_value_info_query = database::query(
            "select id from ". DB_TABLE_OPTION_VALUES_INFO ."
            where value_id = ". (int)$option_value['id'] ."
            and language_code = '". database::input($language_code) ."'
            limit 1;"
          );

          if (!$option_value_info = database::fetch($option_value_info_query)) {
            database::query(
              "insert into ". DB_TABLE_OPTION_VALUES_INFO ."
              (value_id, language_code)
              values (". (int)$option_value['id'] .", '". database::input($language_code) ."');"
            );
            $option_value_info['id'] = database::insert_id();
          }

          database::query(
            "update ". DB_TABLE_OPTION_VALUES_INFO ."
            set name = '". @database::input($option_value['name'][$language_code]) ."'
            where id = ". (int)$option_value_info['id'] ."
            and value_id = ". (int)$option_value['id'] ."
            and language_code = '". database::input($language_code) ."'
            limit 1;"
          );
        }
      }

      $this->previous = $this->data;

      cache::clear_cache('option_groups');
    }

    public function delete() {

      if (empty($this->data['id'])) return;

    // Check products for option group
      $products_options_stock_query = database::query(
        "select id from ". DB_TABLE_PRODUCTS_OPTIONS_STOCK ."
        where combination like '%". (int)$this->data['id'] ."-%';"
      );
      if (database::num_rows($products_options_stock_query) > 0) throw new Exception('Cannot delete option group linked to products.');

    // Check products for option values
      $option_values_query = database::query(
        "select id from ". DB_TABLE_OPTION_VALUES ."
        where group_id = ". (int)$this->data['id'] ."
        and id not in ('". @implode("', '", array_column($this->data['values'], 'id')) ."');"
      );

      while ($option_value = database::fetch($option_values_query)) {

        $products_options_query = database::query(
          "select id from ". DB_TABLE_PRODUCTS_OPTIONS ."
          where combination like '%". (int)$this->data['id'] ."-". (int)$option_value['id'] ."%';"
        );
        if (database::num_rows($products_options_query) > 0) throw new Exception('Cannot delete option value linked to products.');

      // Delete option values
        database::query(
          "delete from ". DB_TABLE_OPTION_VALUES ."
          where group_id = ". (int)$this->data['id'] ."
          and id = ". (int)$option_value['id'] ."
          limit 1;"
        );
        database::query(
          "delete from ". DB_TABLE_OPTION_VALUES_INFO ."
          where value_id = ". (int)$option_value['id'] .";"
        );
      }

    // Delete option group
      database::query(
        "delete from ". DB_TABLE_OPTION_GROUPS ."
        where id = ". (int)$this->data['id'] ."
        limit 1;"
      );

      database::query(
        "delete from ". DB_TABLE_OPTION_GROUPS_INFO ."
        where group_id = ". (int)$this->data['id'] .";"
      );

      $this->reset();

      cache::clear_cache('option_groups');
    }
  }
