<?php
defined('BASEPATH') or exit('No direct script access allowed');

include_once(BASEPATH . 'core/Model.php');


/**
 * A base model which only contains basic CRUD functions (using CodeIgniters query builder) and
 * some additional features like:<br />
 * - Function chaining<br />
 * - Simple database table relations (belongs to, has many)<br />
 * - Event callbacks for simple interaction with queries (before get, after get, before delete, after delete, ...)<br />
 * - Protected fields/Available fields<br />
 * - Only inserts/updates fields which exist in the table<br />
 * - Return row values as object or array<br />
 * <br />
 * <br />
 * <br />
 * Heavily based on Jamie Rumbelows Base Model (https://github.com/jamierumbelow/codeigniter-base-model),
 * but with some simplification to separate the basic CRUD functionality from more extended functionality.<br />
 * <br />
 *
 * @link http://github.com/thnaeff/CodeIgniter-CRUDModel
 * @copyright Copyright (c) 2015, Thomas Naeff
 */
class CRUDModel extends CI_Model {
	/*
	 * Code hint: variables starting wit underscore "_" are set internally, variables without
	 * the underscore are read only and can be set directly if needed.
	 */

	/**
	 * This model's default database table.
	 * Automatically guessed by pluralising the model name.
	 */
	protected $_table;

	/**
	 * The database connection object.
	 * Will be set to the default connection. This allows individual models to use different DBs
	 * without overwriting CI's global $this->db connection.
	 */
	protected $database;

	/**
	 * This model's default primary key or unique identifier.
	 * Used by the get(), update() and delete() functions.
	 */
	protected $primary_key = 'id';

	/**
	 * All the fields which are available in this table.
	 * The fields can either be defined manually, or the database can be queried with update_table_fields() to
	 * set the available fields.
	 *
	 * If this is set to NULL, the model will try to write all data that is passed to a insert/update function
	 * (invalid fields will cause a database error).
	 * If this is set to an array with field names, the insert/update functions will filter out only the fields
	 * given here (and ignore any invalid fields).
	 */
	protected $table_fields = NULL;

	/**
	 * An array of all the fields which should not be writable.
	 * Write function calls (e.g. insert and update) will filter out any field names defined in this array.
	 */
	protected $protected_fields;

	/**
	 * By default results are returned as arrays
	 */
	protected $return_type = 'array';

	/**
	 * Database table relations. Each relation has to be given as key-value pair. The key is the
	 * table name, the value is an array of options (or an empty array if no options are needed).
	 */
	protected $belongs_to = array();
	/**
	 * @see $belongs_to
	 */
	protected $has_many = array();

	/**
	 * Temporary array of relationship tables defined through the with() function
	 */
	private $_temporary_with_tables;

	/**
	 * If a relationship is selected with the with() function, setting this flag to TRUE
	 * causes the two results to be joined in the same array/object.
	 */
	private $_temporary_join = FALSE;

	/**
	 * Available events (keys) and event methods to execute.<br />
	 * For the event methods, either a single method name can be given as string,
	 * or multiple method names as array.
	 *
	 * Events:
	 * <ul>
	 * <li>before_get</li>
	 * <li>after_get</li>
	 * <li>before_insert</li>
	 * <li>after_insert</li>
	 * <li>before_update</li>
	 * <li>after_update</li>
	 * <li>before_delete</li>
	 * <li>after_delete</li>
	 * </ul>
	 */
	protected $events = array(	'before_get'=>NULL,
								'after_get'=>NULL,
								'before_insert'=>NULL,
								'after_insert'=>NULL,
								'before_update'=>NULL,
								'after_update'=>NULL,
								'before_delete'=>NULL,
								'after_delete'=>NULL);


	/**
	 * Initialise the model, tie into the CodeIgniter superobject and
	 * try our best to guess the table name.
	 *
	 * @param string $table_name The table name can be provided here. If the table name is not provided,
	 * the name is guessed by pluralizing the model name
	 */
	public function __construct($table_name = NULL) {
		parent::__construct();

		// CI helper library for pluralizing/singularizing
		$this->load->helper('inflector');

		if ($table_name == NULL) {
			// Guess the table name by pluralising the model name (without the _m or _model extension)
			$this->_table = plural(preg_replace('/(_m|_model)?$/', '', strtolower(get_class($this))));
		} else {
			$this->_table = $table_name;
		}

		/**
		 * Use the CodeIgniter standard database.
		 * The CodeIgniter Model retrieves CI variables by overwriting the __get method.
		 * If a variable does not exist, it throws an exception because get_instance()->$variable_key fails.
		 * Therefore, if the default database is not loaded in $this->db, this code fails. Using
		 * isset() does not work in this case either.
		 *
		 * See: system/core/Mode.php function __get()
		 */
		try {
			$this->database = $this->db;
		} catch ( Exception $e ) {
			// $this->db does not exist
		}

		// Well defined state
		$this->reset();
	}

	/**
	 * A helper method to return the used database object.
	 */
	public function db() {
		return $this->database;
	}

	/**
	 * Returns the name of the database table
	 *
	 */
	public function table() {
		return $this->_table;
	}

	/**
	 * Resets the model so that it is a well defined state
	 */
	public function reset() {
		$this->database->reset_query();
		$this->_temporary_with_tables = array();
	}

	/**
	 * Retrieves all the available fields from the database table and sets them
	 * as available table fields.
	 */
	public function update_table_fields() {
		$this->table_fields = $this->database->list_fields($this->_table);
	}

	/**
	 * Returns all the fields available in the table.
	 * The field names have either been set manually or by querying the database.
	 */
	public function get_table_fields() {
		return $this->table_fields;
	}

	/**
	 * Retrieves the field data for all table columns. Simply calls the field_data function on
	 * the database object, unless nameAsKey=true (default) where the array is rebuilt with the
	 * field names as key.
	 *
	 *
	 * @param string $nameAsKey If set to TRUE, the array is rebuilt with the column name as index.
	 * If set to FALSE, the column index is used as the array keys.
	 * @return array The field data
	 */
	public function get_field_data($nameAsKey=true) {

		if (!$nameAsKey) {
			return $this->database->field_data($this->_table);
		} else {
			$fields = $this->database->field_data($this->_table);
			$fieldData = array();
			$columnIndex = 0;
			foreach ($fields as $field) {
				$fieldData[$field->name] = $field;
				//$field['column_index'] = $columnIndex;
				$columnIndex++;
			}

			return $fieldData;
		}
	}

	/*----------------------------------------------------------------------------------------
	 * CRUD Functions
	 *
	 * get
	 * insert
	 * update
	 * delete
	 */

	/**
	 * Fetches one or more records with the given parameter(s) as primary key value.
	 * If no primary key value(s) are given, the where-statment has to be defined beforehand on the used database.
	 *
	 * @param var $primary_values
	 *        	The primary key value (or an array of values) of the row(s) to return. If omitted/set to
	 *        	null (default), the fetched data is not limited to the primary value and can be defined
	 *        	with a where-statement directly on the used DB.
	 * @return var/boolean Returns the query result as array/object, or FALSE if the execution got
	 *         interrupted by an event.
	 */
	public function get($primary_values = null) {
		$primary_values = $this->trigger('before_get', $primary_values);
		if ($primary_values === FALSE) {
			$this->reset();
			return FALSE;
		}

		// Limit to primary key(s) (if provided)
		$this->set_where($this->primary_key, $primay_values);

		// Multiple rows expected, or just a single row?
		$multi = ($primary_values == NULL || is_array($primary_values));

		$result = $this->database->get($this->_table)->{$this->get_return_type($multi)}();

		$result = $this->relate_get($result, $multi);

		$this->reset();

		// Only a modification on $result is re-used
		$result = $this->trigger('after_get', array($primary_values, $result))[1];

		return $result;
	}

	/**
	 * Inserts the given data into the table.
	 * Can either be a single row (one array element for each field) or multiple rows
	 * (a nested array where each array element is a row).
	 *
	 *
	 * Single row: ['key1'=>'value1', 'key2'=>'value2']
	 * Multiple rows: [['key11'=>'value11', 'key12'=>'value12'], ['key21'=>'value21', 'key22'=>'value22'], ['key31'=>'value31', 'key32'=>'value32']]
	 *
	 * @param array $data
	 *        	The array with the data to insert
	 * @return var/array If single row data has ben inserted, the returned value is the ID of the new row. If a multi-row
	 *         array has been provided with data, the returned value is an array with all new row ID's.
	 */
	public function insert($data) {
		if ($data == null || count($data) == 0) {
			return null;
		}

		// Checking the first array element only to decide if multi-row data is given
		$multiple = is_array(reset($data));

		if ($multiple) {
			$ids = array();

			foreach ( $data as $row ) {
				$ids[] = $this->insert_single_row($row);
			}

			return $ids;
		} else {
			return $this->insert_single_row($data);
		}
	}

	/**
	 * Inserts the given row into the table
	 *
	 * @param array $row
	 * @return The ID of the new row
	 */
	private function insert_single_row($row) {
		$row = $this->trigger('before_insert', $row);
		if ($row === FALSE) {
			$this->reset();
			return FALSE;
		}

		$row = $this->prepare_write_data($row);

		$this->database->insert($this->_table, $row);
		$insert_id = $this->database->insert_id();
		$this->reset();

		$this->trigger('after_insert', array($row, $insert_id));

		return $insert_id;
	}

	/**
	 * Updates one/multiple rows in the table with the given row data.
	 * If no primary key
	 * value(s) are given, the where-statment has to be defined beforehand on the used database.
	 *
	 *
	 * @param array $row
	 * @param var $primary_values
	 *        	The primary key value (or an array of values) of the row(s) to update. If omitted/set to
	 *        	null (default), the updated data is not limited to the primary value and has to be defined
	 *        	with a where-statement directly on the used DB.
	 */
	public function update($row, $primary_values = null) {
		$ret = $this->trigger('before_update', array($row, $primary_values));
		if ($ret === FALSE) {
			$this->reset();
			return FALSE;
		}

		$row = $ret[0];
		$primary_values = $ret[1];

		// Limit to primary key(s) (if provided)
		$this->set_where($this->primary_key, $primary_values);

		$row = $this->prepare_write_data($row);

		$this->database->set($row);
		$result = $this->database->update($this->_table);
		$this->reset();

		$this->trigger('after_update', array($row, $primary_values, $result));

		return $result;
	}

	/**
	 * Deletes one or more records with the given parameter(s) as primary key value.
	 * If no primary key value(s) are given, the where-statment has to be defined beforehand on the used database.
	 *
	 * @param var $primary_values
	 *        	The primary key value (or an array of values) of the row(s) to delete. If omitted/set to
	 *        	null (default), the deleted data is not limited to the primary value and has to be defined
	 *        	with a where-statement directly on the used DB.
	 * @return var/boolean Returns the query result as array/object, or FALSE if the execution got
	 *         interrupted by an event.
	 */
	public function delete($primary_values = null) {
		$primary_values = $this->trigger('before_delete', $primary_values);
		if ($primary_values === FALSE) {
			$this->reset();
			return FALSE;
		}

		// Limit to primary key(s) (if provided)
		$this->set_where($this->primary_key, $primary_values);

		$this->database->delete($this->_table);
		$this->reset();

		$this->trigger('after_delete', array($primary_values, $result));

		return $result;
	}

	/*----------------------------------------------------------------------------------------
	 * Relationships
	 */

	/**
	 * Sets the given table to be retrieved in the same result set. The table has to be defined
	 * previously as belongs_to or has_many relationship
	 *
	 * @param string $related_table
	 * @param boolean $return_foreign_model Default: FALSE. If set to TRUE, this method returns the foreign model
	 * instead of the current model. This can be useful if the foreign table model needs additional
	 * configuration to the provided relationship options.
	 */
	public function with($related_table, $return_foreign_model=false) {
		//Checks it the table name exists as key in the belongs_to/has_many array
		if (! array_key_exists($related_table, $this->belongs_to)
				&& ! array_key_exists($related_table, $this->has_many)) {
			throw new Exception('Relationship \'' . $related_table . '\' is not defined for \'' . $this->_table . '\'');
		}

		//Add the relationship table to the temporary with-tables so that it will
		//be used the next time the relate_get() function is called.
		$this->_temporary_with_tables[] = $related_table;

		return $this;
	}

	/**
	 * Returns the row(s) from the related data models, combined with the given row
	 *
	 * @param array $rows
	 * @param boolean $multi
	 * @return array The resulting row which includes all the relating data
	 */
	private function relate_get($rows, $multi) {
		if (empty($this->_temporary_with_tables) || empty($rows)) {
			return $rows;
		}

		if ($multi) {
			foreach ($rows as $key=>$row) {
				$rows[$key] = $this->belongs_to($row);
				$rows[$key] = $this->has_many($row);
			}
		} else {
			$rows = $this->belongs_to($rows);
			$rows = $this->has_many($rows);
		}
		return $rows;
	}

	/**
	 *
	 *
	 * @param array $row
	 */
	private function belongs_to($row) {
		// Belongs to
		foreach ( $this->belongs_to as $key => $value ) {
			$options = $this->relate_options($key, $value);
			$foreign_table_name = $options['foreign_table_name'];

			if (in_array($foreign_table_name, $this->_temporary_with_tables)) {
				$model_name = $foreign_table_name . '_model';

				$this->load->model($options['model'], $model_name);

				$primary_key_names = $options['primary_key'];

				if (!is_array($primary_key_names)) {
					$primary_key_names = array($primary_key_names);
				}

				print_ln('belongs_to: ' . $this->_table . '->' . $foreign_table_name);
				print_array($row);

				foreach ($primary_key_names as $primary_key_name) {
					$primary_value  = $this->get_row_value($row, $primary_key_name);


					print_ln($primary_key_name . '=' . $primary_value);

					// Retrieve one result which has the given primary value
					$result = $this->{$model_name}->get($primary_value);

					print_array($result);

					// Add related result set to existing result set
					$row = $this->combine_related($row, $result, $foreign_table_name);
				}
			}
		}

		return $row;
	}

	/**
	 *
	 *
	 * @param array $row
	 */
	private function has_many($row) {
		// Has many
		foreach ( $this->has_many as $key => $value ) {
			$options = $this->relate_options($key, $value);
			$foreign_table_namerelationship = $options['foreign_table_name'];

			if (in_array($foreign_table_namerelationship, $this->_temporary_with_tables)) {
				$model_name = $foreign_table_namerelationship . '_model';

				$this->load->model($options['model'], $model_name);

				$primary_key_names = $options['primary_key'];

				if (!is_array($primary_key_names)) {
					$primary_key_names = array($primary_key_names);
				}

				print_ln('belongs_to: ' . $this->_table . '->' . $foreign_table_namerelationship);

				foreach ($primary_key_names as $primary_key_name) {
					$relate_value = $this->get_row_value($row, $primary_key_name);

					// Retrieve all the records which have the relate value
					$this->{$model_name}->db()->where($primary_key_name, $relate_value);
					$result = $this->{$model_name}->get();

					// Add related result set to existing result set
					$row = $this->combine_related($row, $result, $foreign_table_namerelationship);
				}
			}
		}

		return $row;
	}

	/**
	 *
	 *
	 * @param unknown $row
	 * @param unknown $relationship
	 * @return unknown
	 */
	private function combine_related($row1, $row2, $relationship) {

		if (is_object($row1)) {
			$row1->{$relationship} = $row2;
		} else {
			$row1[$relationship] = $row2;
		}


		return $row1;
	}

	/**
	 * Helper function to retrieve the row value from either a row-object or a row-array
	 *
	 * @param array/object $row
	 * @param string $key
	 */
	protected function get_row_value($row, $key) {
		if (is_object($row)) {
			return $row->{$key};
		} else {
			return $row[$key];
		}
	}

	/**
	 * Smart setting of the relate-options.
	 * Default values are set for missing values.<br />
	 * <br />
	 * $belongs_to = ['some_foreign_table'=>[]];<br />
	 * $belongs_to = ['some_foreign_table'=>['foreign_key'=>'some_foreign_key', ...]];<br />
	 *
	 * @param string $foreign_table_name
	 * @param array $defined_options
	 * @return
	 */
	protected function relate_options($foreign_table_name, array $defined_options) {
		$options = array();

		// -- Default options --

		//The foreign table name
		$options['foreign_table_name'] = $foreign_table_name;
		//The default foreign key is the primary key of the current table. Since there could be multiple
		//foreign keys, an array is used.
		$options['foreign_keys'] = [singular($this->_table) . '_id'];
		//The key of the current table is the primary key
		$options['local_key'] = $this->primary_key;
		//Additional where statement to execute on the foreign table
		$options['foreign_table_where'] = '';
		//The name of the model (if different from [tablename]_model)
		$options['model'] = singular($foreign_table_name) . '_model';

		// -- End default options --


		if (count($defined_options) > 0) {
			// Merge with options. Override any existing default options
			$options = array_merge($options, $defined_options);

			//If there is only one single foreign key given, but not within an array, turn it into an array.
			if (!is_array($options['foreign_keys'])) {
				$options['foreign_keys'] = [$options['foreign_keys']];
			}
		}

		return $options;
	}

	/*----------------------------------------------------------------------------------------
	 * Internal functions
	 */

	/**
	 * Prepares the data for insert/update functions
	 * - Removes any protected fields
	 * - Only keeps fields which exist in the table
	 */
	private function prepare_write_data($row) {
		// Unset all protected fields
		foreach ( $this->protected_fields as $field ) {
			if (is_object($row)) {
				// For data objects
				unset($row->$field);
			} else {
				// For data arrays
				unset($row[$field]);
			}
		}

		if ($this->table_fields != NULL) {
			// Only keep existing fields
			foreach ( $row as $fieldName => $fieldValue ) {
				if (! in_array($fieldName, $this->table_fields)) {
					if (is_object($row)) {
						// For data objects
						unset($row->$fieldName);
					} else {
						// For data arrays
						unset($row[$fieldName]);
					}
				}
			}
		}

		return $row;
	}

	/**
	 * Creates the where statement.
	 * The value can either be a single value or an array of values.
	 *
	 * @param string $key
	 * @param var/array $value
	 * @return
	 *
	 */
	protected function set_where($key, $value) {
		if ($value != NULL) {
			if (! is_array($value)) {
				$this->database->where($key, $value);
			} else {
				$this->database->where_in($key, $value);
			}
		}
	}

	/**
	 * Trigger an event and call its observers.
	 *
	 *
	 * @param string $event
	 *        	The event name. This trigger function will call all the event methods registered for that name.
	 * @param var $data
	 *        	The data to pass on to the obervers
	 * @return The data which might have been modified by one of the observers
	 */
	protected function trigger($event, $data = NULL) {
		if (isset($this->events[$event])) {

			$event_array = $this->events[$event];

			if (is_array($event_array)) {
				foreach ( $event_array as $method ) {
					$data = call_user_func_array(array($this, $method), array($data));

					//The first event function which returns FALSE stops the process
					if ($data === FALSE) {
						return FALSE;
					}
				}
			} else {
				$data = call_user_func_array(array($this, $method), array($data));
			}
		}

		return $data;
	}

	/**
	 * Return the function name for the current return type
	 *
	 * @param boolean $multi
	 *        	If TRUE, the database function will return a result set. If set to
	 *        	FALSE, the database function will return a single row
	 * @return The database function name which either retrieves the result as array or object
	 */
	protected function get_return_type($multi = false) {
		// The whole result (one or more rows) or just the first row
		$method = ($multi ? 'result' : 'row');
		// result_array/row_array or result/row
		return $this->return_type == 'array' ? $method . '_array' : $method;
	}
}

?>

