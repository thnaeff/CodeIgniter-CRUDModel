<?php
defined('BASEPATH') or exit('No direct script access allowed');

include_once(BASEPATH . 'core/Model.php');


/**
 * A base model which only contains basic CRUD functions (using CodeIgniters query builder) and
 * some additional features like:<br />
 * - Function chaining<br />
 * - Simple database table relations (belongs to, has many)<br />
 * - Use output (get result) as input (update/insert/delete), also with relationships<br />
 * - Event callbacks for simple interaction with queries (before get, after get, before delete, after delete, ...)<br />
 * - Protected fields/Available fields<br />
 * - Only inserts/updates fields which exist in the table<br />
 * - Return row values as object or array<br />
 * - Flattened arrays (including multi-dimensional arrays of relationships)<br />
 * <br />
 * <br />
 * <br />
 * Initially based on Jamie Rumbelows Base Model (https://github.com/jamierumbelow/codeigniter-base-model),
 * but with different functionality and separated CRUD functionality from more extended functionality
 * (see https://github.com/thnaeff/CodeIgniter-BaseModel).<br />
 * <br />
 *
 * @link http://github.com/thnaeff/CodeIgniter-CRUDModel
 * @copyright Copyright (c) 2015, Thomas Naeff
 */
class CRUDModel extends CI_Model {
	/*
	 * Code hint: variables starting wit underscore "_" are set internally and should only be
	 * changed if documented or really necessary, variables without can be set directly if needed.
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
	 * foreign table name, the value is an array of options (or an empty array if no options are needed).<br />
	 * This allows to define BELONGS_TO (1:1/n:1) or HAS_MANY (1:n/n:n) relationships between records.<br />
	 * <br />
	 * Available options are:<br />
	 * - related_keys: The key relationship between the local and the foreign table. See below for further details.<br />
	 * - model: The model name of the foreign table. Only necessary if the model name guessing does not produce the expected result.<br />
	 *
	 * Examples: <br />
	 * No options given. The primary key column of the local table related to the table name + _id column of the foreign table<br />
	 * $related_to['foreign_table'] = [];<br />
	 * <br />
	 * The local_key of the local table related to the primary key of the foreign table.
	 * (If the foreign key is given as NULL, it automatically takes the primary key of the foreign model)<br />
	 * $related_to['foreign_table'] = ['related_keys'=>['local_key1'=>null]];<br />
	 * <br />
	 * The primary key of the local table related to the foreign_key1 OR foreign_key2 of the foreign table<br />
	 * $related_to['foreign_table'] = ['related_keys'=>['foreign_key1', 'foreign_key2']];<br />
	 * <br />
	 * The key local_key1 is related to foreign_key1, and the key local_key2 is related to foreign_key2<br />
	 * $related_to['foreign_table'] = ['related_keys'=>['local_key1'=>'foreign_key1', 'local_key2'=>'foreign_key2']];<br />
	 * <br />
	 * The key local_key1 is related to foreign_key1 OR foreign_key11, and the key local_key2 is related to foreign_key2<br />
	 * $related_to['foreign_table'] = ['related_keys'=>['local_key1'=>['foreign_key1', 'foreign_key11'], 'local_key2'=>'foreign_key2']];<br />
	 * <br />
	 *
	 *
	 */
	protected $related_to = array();

	/**
	 * Temporary array of relationship tables defined through the with() function
	 */
	private $_temporary_with_tables;

	/**
	 * Flattens the retrieved data. Only works for data arrays which contain one single result array.
	 * If there is related data, those sub-arrays will get flattend as well.
	 */
	private $_temporary_flat = FALSE;
	/**
	 * @see $_temporary_flat
	 */
	private $_temporary_flat_full = FALSE;

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
	 * If set to TRUE, all queries are saved in an array. This can be used to track all querys
	 */
	private $_save_queries = false;
	private $queries = null;


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
	 * Returns the primary key used for this table
	 *
	 */
	public function primary_key() {
		return $this->primary_key;
	}

	/**
	 * Resets the model so that it is a well defined state.
	 * Should be called after each database access like update, delete, get etc.
	 */
	public function reset() {
		$this->database->reset_query();
		$this->_temporary_with_tables = array();
		$this->_temporary_flat = false;
		$this->_temporary_flat_full = false;
	}

	/**
	 * Retrieves all the available fields from the database table and sets them
	 * as available table fields.
	 */
	public function update_table_fields() {
		$this->table_fields = $this->database->list_fields($this->_table);
		$this->save_query();
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
		$this->set_where($this->primary_key, $primary_values);

		// Multiple rows expected, or just a single row?
		$multi = ($primary_values == NULL || is_array($primary_values));

		$result = $this->database->get($this->_table)->{$this->get_return_type($multi)}();
		$this->save_query();

		$result = $this->relate_get($result);

		if ($this->_temporary_flat || $this->_temporary_flat_full) {
			$result = $this->flatten_array($result);
		}

		$this->reset();

		//Just return the result array if no callback is called
		$result = $this->trigger('after_get', array($primary_values, $result), $result);

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

		$preparedRow = $this->prepare_write_data($row);

		$this->database->insert($this->_table, $preparedRow);
		$this->save_query();
		$insert_id = $this->database->insert_id();
		$this->reset();

		$this->trigger('after_insert', array($preparedRow, $insert_id));

		return $insert_id;
	}

	/**
	 * Updates one/multiple rows in the table with the given row data.
	 * If no primary key value(s) are given, the where-statment has to be defined
	 * beforehand on the used database.
	 *
	 *
	 * @param array $row
	 * @param var $primary_values
	 *        	The primary key value (or an array of values) of the row(s) to update. If omitted/set to
	 *        	null (default), the updated data is not limited to the primary value and has to be defined
	 *        	with a where-statement directly on the used DB. Primary values in the row are considered as
	 *        	well.
	 */
	public function update($row, $primary_values = null) {
		$primary_values = $this->primary_values_from_rows($row, $primary_values);

		$ret = $this->trigger('before_update', array($row, $primary_values));
		if ($ret === FALSE) {
			$this->reset();
			return FALSE;
		}

		$row = $ret[0];
		$primary_values = $ret[1];

		//Also use the primary keys from the row
		if (array_key_exists($this->primary_key, $row)) {
			if (empty($primary_values)) {
				$primary_values = array();
			} else if (! is_array($primary_values)) {
				$primary_values = [$primary_values];
			}

			$primary_values[] = $row[$this->primary_key];
			$primary_values = array_unique($primary_values);
		}

		// Limit to primary key(s) (if provided)
		$this->set_where($this->primary_key, $primary_values);

		$preparedRow = $this->prepare_write_data($row);

		$this->database->set($preparedRow);
		$result = $this->database->update($this->_table);
		$this->save_query();

		//The primary key(s) are needed for resolving relationships
		if (! empty($primary_values)) {
			$row[$this->primary_key] = $primary_values;
		}

		//Call the update method for related tables. Pass on the result for this table.
		$result = $this->relate_action($row, [$this->_table=>$result], 'update');

		$this->reset();

		$this->trigger('after_update', array($preparedRow, $primary_values, $result));

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
	 * @param array $row
	 * 			The row with the primary key of the record which should get
	 * 			deleted. This gives the possibility to specify the row to delete in the format of
	 * 			a get call result and it allows to combine the delete with related tables. A
	 * 			combination of $primary_values and $row is allowed.
	 * @return var/boolean Returns the query result as array/object, or FALSE if the execution got
	 *         interrupted by an event.
	 */
	public function delete($primary_values = null, $rows = null) {
		$primary_values = $this->primary_values_from_rows($rows, $primary_values);

		$primary_values = $this->trigger('before_delete', $primary_values);
		if ($primary_values === FALSE) {
			$this->reset();
			return FALSE;
		}

		// Limit to primary key(s) (if provided)
		$this->set_where($this->primary_key, $primary_values);
		$result = $this->database->delete($this->_table);
		$this->save_query();

		//The primary key(s) are needed for resolving any relationships
		if (! empty($primary_values)) {
			$rows[$this->primary_key] = $primary_values;
		}

		//Call the update method for related tables. Pass on the result for this table.
		$result = $this->relate_action($rows, [$this->_table=>$result], 'delete');

		$this->reset();

		$this->trigger('after_delete', array($primary_values, $result));

		return $result;
	}

	/*----------------------------------------------------------------------------------------
	 * Flags
	 */

	/**
	 * Flattens the retrieved data. Only works for data arrays which contain one single result array.
	 * If there is related data, those sub-arrays will get flattend as well.
	 *
	 *
	 * For example:
	 * <pre>
	 * Array
	 * (
     * [0] => Array
	 *   (
	 *      [member_id] => 1
	 *      [name] => Test
	 *      [email] => test@test.com
	 *      [profile] => Array
	 *        (
	 *           [0] => Array
	 *              (
	 *                [profile_id] => 1
	 *                [member_id] => 1
	 *                [password] => pwd
	 *              )
	 *        )
	 *   )
	 * )
	 *</pre>
	 *
	 * turns into
	 *
	 * <pre>
	 * Array
	 * (
	 *    [member_id] => 1
	 *    [name] => Test
	 *    [email] => test@test.com
	 *    [profile] => Array
	 *      (
	 *         [profile_id] => 1
	 *         [member_id] => 1
	 *         [password] => pwd
	 *      )
	 * )
	 *</pre>
	 *
	 * or if the full-flat flag is set
	 *
	 * <pre>
	 * Array
	 * (
	 *    [member_id] => 1
	 *    [name] => Test
	 *    [email] => test@test.com
	 *    [profile] => Array
	 *    [profile_id] => 1
	 *    [member_id] => 1
	 *    [password] => pwd
	 * )
	 *</pre>
	 *
	 * @param string $flat TRUE (default) flattens the retrieved data for arrays/objects which only
	 * have one array element. FALSE keeps the data as retrieved (one array/object per record).
	 * @param string $full TRUE also flattens sub-arrays (relationship data) to the level of the main array.
	 * FALSE (default) keeps relationship data in its sub-array. Warning: sub-arrays are merged with their
	 * parent arrays, which results in parent key-value pairs being overwritten with matching sub-array key-value pairs.
	 */
	public function flat($flat = TRUE, $full = FALSE) {
		$this->_temporary_flat = $flat;
		$this->_temporary_flat_full = $full;

		return $this;
	}


	/*----------------------------------------------------------------------------------------
	 * Relationships
	 */

	/**
	 * Sets the given table/model to be retrieved in the same result set. The table has to be defined
	 * previously as related_to relationship
	 *
	 * @param string $related_table The table/model name in plural (e.g. "profiles" for the "profiles" table and "Profile_model" model)
	 * @param boolean $return_foreign_model Default: FALSE. If set to TRUE, this method returns the foreign model
	 * instead of the current model. This can be useful if the foreign table model needs additional
	 * configuration to the provided relationship options.
	 */
	public function with($related_table, $return_foreign_model=false) {
		//Checks it the table name exists as key in related_to
		if (! array_key_exists($related_table, $this->related_to)) {
			throw new Exception('Relationship \'' . $related_table . '\' is not defined for \'' . $this->_table . '\'');
		}

		//Add the relationship table to the temporary with-tables so that it will
		//be used the next time the relate_get() function is called.
		$this->_temporary_with_tables[] = $related_table;

		if ($return_foreign_model) {
			$options = $this->relate_options($related_table, $this->related_to[$related_table]);
			return $this->load_related_model($options['model']);
		} else {
			return $this;
		}
	}

	/**
	 * Returns the row(s) from the related data models, combined with the given row(s). This can be
	 * used to fetch related rows after a previous get call or with existing row data.
	 *
	 * @param array $rows The rows from which the relationship values should be taken
	 * and to which the data of the related tables will be added.
	 * @return array The resulting row which includes all the data of the related tables data
	 */
	public function relate_get($rows) {
		return $this->relate_action($rows, null, 'get');
	}

	/**
	 * The entry point for all relationship resolving. Calls the needed get/update/delete function.
	 *
	 *
	 * @param array $rows An array with key-value pairs of one single orw or multiple sub-arrays for
	 * multiple rows.
	 * @param array $results If the result(s) of the database actions should be returned instead
	 * of row data, provide an array here.
	 * @param String $action A string which defines the action to use (get/insert/update/delete)
	 */
	private function relate_action($rows, $results, $action) {
		if (empty($this->_temporary_with_tables) || empty($rows)) {
			if ($results != null) {
				return $results;
			} else {
				return $rows;
			}
		}

		//Get data from each related table
		foreach ($this->_temporary_with_tables as $with_table) {
			$options = $this->relate_options($with_table, $this->related_to[$with_table]);
			$model_name = $options['model'];

			//Loads the model with a special name so that "self" relationships are possible
			//(a model can have a relationship to itself)
			$this->load_related_model($model_name);

			//Array of [local_key=>foreign_key(s)] or simply [foreign_key(s)]
			$related_keys = $options['related_keys'];

			switch ($action) {
				case 'get':
					$rows = $this->relate_action_get($rows, $with_table, $model_name, $related_keys);
					break;
				case 'update':
					$results[$with_table] = $this->relate_action_update($rows, $results, $with_table, $model_name, $related_keys);
					break;
				case 'delete':
					$results[$with_table] = $this->relate_action_delete($rows, $results, $with_table, $model_name, $related_keys);
					break;
				default:
					throw new Exception('Invalid action "' . $action . '"');
					break;
			}

		}

		if ($results != null) {
			return $results;
		} else {
			return $rows;
		}
	}


	/**
	 * get
	 *
	 * @param unknown $rows
	 * @param unknown $with_table
	 * @param unknown $model_name
	 * @param unknown $related_keys
	 */
	private function relate_action_get($rows, $with_table, $model_name, $related_keys) {

		//Retrieve related data for each row
		foreach ($rows as $row_key=>$row) {
			$this->related_or_where($row, $with_table, $model_name, $related_keys);
			$result = $this->{$model_name . '_related'}->get();
			$this->save_related_query($model_name . '_related');

			$rows[$row_key] = $this->combine_related($row, $result, $with_table);
		}

		return $rows;
	}

	/**
	 * update
	 *
	 * @param unknown $rows
	 * @param unknown $with_table
	 * @param unknown $model_name
	 * @param unknown $related_keys
	 */
	private function relate_action_update($row, $results, $with_table, $model_name, $related_keys) {
		if (! array_key_exists($with_table, $row)) {
			//No data for the sub-table exists in the row
			return false;
		}

		$this->related_or_where($row, $with_table, $model_name, $related_keys);

		$results = $this->{$model_name . '_related'}->update($row[$with_table]);
		$this->save_related_query($model_name . '_related');

		//Only return the result of this update (and its related updates)
		return $results[$this->{$model_name . '_related'}->table()];
	}

	/**
	 * delete
	 *
	 * @param unknown $rows
	 * @param unknown $with_table
	 * @param unknown $model_name
	 * @param unknown $related_keys
	 */
	private function relate_action_delete($row, $results, $with_table, $model_name, $related_keys) {
		if (! array_key_exists($with_table, $row)) {
			//No data for the sub-table exists in the row
			return false;
		}

		$this->related_or_where($row, $with_table, $model_name, $related_keys);

		$results = $this->{$model_name . '_related'}->delete();
		$this->save_related_query($model_name . '_related');

		//Only return the result of this update (and its related updates)
		return $results[$this->{$model_name . '_related'}->table()];
	}

	/**
	 * Sets the where clause on the related model, using all the related keys (connected
	 * with OR). <br />
	 * <br />
	 * HAS_MANY relationship (1:n or n:n)<br />
	 * BELONGS_TO relationship (1:1 or n:1)<br />
	 *
	 *
	 * @param array $rows The rows which should be combined with the results of $with_table
	 * @param string $with_table The related table
	 * @param string $model_name
	 * @param array $related_keys
	 */
	protected function related_or_where($row, $with_table, $model_name, $related_keys) {

		//n:n relationship
		//One or multiple local keys mapped to one or multiple foreign keys
		//Creates an OR where clause between all the keys
		foreach ($related_keys as $local_key=>$foreign_keys) {
			if (!is_string($local_key)) {
				//Use the local primary key if only the foreign keys are given
				$local_key = $this->primary_key;
			}

			if ($foreign_keys == null) {
				//If no foreign key is given, use the primary key of the related table
				$foreign_keys = [$this->{$model_name . '_related'}->primary_key()];
			} else if (!is_array($foreign_keys)) {
				//Make sure the foreign keys are an array
				$foreign_keys = [$foreign_keys];
			}

			//Check if the needed keys are present to resolve the relationship
			if (! $this->row_key_exists($row, $local_key)) {
				//$this->printRelationship();
				throw new Exception('Trying to resolve relationship ' . $this->_table . '->' . $with_table . '.
										Key "' . $local_key . '" not found in local (' . $this->_table . ') row data.');
			}

			$local_value = $this->get_row_value($row, $local_key);

			foreach ($foreign_keys as $foreign_key) {
				//OR where
				if (is_array($local_value)) {
					$this->{$model_name . '_related'}->db()->or_where_in($foreign_key, $local_value);
				} else {
					$this->{$model_name . '_related'}->db()->or_where($foreign_key, $local_value);
				}
			}

		}

	}

	/**
	 *
	 *
	 * @param array/object row The main array where $data will be added to
	 * @param array $data Combine this row with row as field with the $foreign_table_name as key
	 * @param string $foreign_table_name
	 * @return array/object
	 */
	private function combine_related($row, array $data, $foreign_table_name) {

		if (is_object($row)) {
			$row->{$foreign_table_name} = $data;
		} else {
			$row[$foreign_table_name] = $data;
		}


		return $row;
	}


	/**
	 * Smart setting of the relate-options.
	 * Default values are set for missing values.<br />
	 * <br />
	 * related_to = ['some_foreign_table'=>[]];<br />
	 * related_to = ['some_foreign_table'=>['related_keys'=>['some_foreign_key'], ...]];<br />
	 *
	 * @param string $foreign_table_name
	 * @param array $defined_options
	 * @return
	 */
	protected function relate_options($foreign_table_name, array $defined_options) {
		$options = array();

		// -- Default options --

		//The default foreign key relates to the primary key of the current table (if only the array value
		//is given, the primary key of the current table is used as key).
		//This is equivalent to: [$this->primary_key=>singular($this->_table) . '_id']
		//Because there could be multiple key relations, an array is used.
		$options['related_keys'] = [singular($this->_table) . '_id'];
		//The name of the model (if different from [tablename]_model)
		$options['model'] = singular($foreign_table_name) . '_model';

		// -- End default options --


		if (count($defined_options) > 0) {
			// Merge with options. Override any existing default options
			$options = array_merge($options, $defined_options);

			//If there is only one single foreign key given, but not within an array, turn it into an array.
			if (!is_array($options['related_keys'])) {
				$options['related_keys'] = [$options['related_keys']];
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
	 *
	 * @param array $row
	 */
	protected function prepare_write_data(array $row) {
		// Remove all protected fields
		$row = array_diff_key($row, array_flip($this->protected_fields));

		if ($this->table_fields != NULL) {
			// Only keep existing fields
			$row = array_intersect_key($row, array_flip($this->table_fields));
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
	 * Checks if the key/variable exists in the row object/array
	 *
	 * @param object/array $row
	 * @param string $key
	 */
	protected function row_key_exists($row, $key) {
		if (is_object($row)) {
			return property_exists($row, $key);
		} else {
			return array_key_exists($key, $row);
		}
	}

	/**
	 * Checks the rows if the primary key is present. If yes, the value
	 * is used as primary value and combined with the $primary_values
	 * parameter. The $rows parameter can eiter be a single row (with all its
	 * key-value pairs) or a multi-row array.
	 *
	 * @param array $rows
	 * @param var $primary_values
	 * @param boolean $is_row A flag to indicate that the function has been
	 * called from within itself and that it is known already that the $rows
	 * parameter is a single row.
	 * @return array An array of all primary values
	 */
	protected function primary_values_from_rows($rows, $primary_values, $is_row = false) {
		if ($rows == null) {
			return $primary_values;
		}

		//Is it a single row or are there multiple rows?
		if (($is_row && array_key_exists($this->primary_key, $rows))
				|| array_key_exists($this->primary_key, $rows)) {
			//Must be a single row, because the primary key has been found in it

			//The primary key is in the row. Use it for the where statement
			$value = $rows[$this->primary_key];

			if (empty($primary_values)) {
				$primary_values = array();
			} else if (! is_array($primary_values)) {
				$primary_values = [$primary_values];
			}

			if (is_array($value)) {
				$primary_values = array_merge($primary_values, $value);
			} else {
				$primary_values[] = $value;
			}

			$primary_values = array_unique($primary_values);
		} else {
			//Might be an array with multiple rows.

			foreach ($rows as $row) {
				if (! is_array($row)) {
					//Stop as soon as there is an element encountered which is not an array.
					//This means that the primary key has not been found and the provided
					//array is not a multi-row array. No primary keys to take care of then.
					break;
				}

				//Combine the primary keys of all the rows. It is already known
				//now that it is a single row.
				$this->primary_values_from_rows($row, $primary_values, true);
			}
		}

		return $primary_values;
	}

	/**
	 * Trigger an event and call its observers.
	 *
	 *
	 * @param string $event
	 *        	The event name. This trigger function will call all the event methods registered for that name.
	 * @param var $data
	 *        	The data to pass on to the obervers
	 * @param var $defaultReturn The value to return if no callback function is called. If not
	 * given or if NULL, the $data is returned if no callback function is called.
	 * @return The data which might have been modified by one of the observers
	 */
	protected function trigger($event, $data = NULL, $defaultReturn = NULL) {
		if (isset($this->events[$event]) && ! empty($this->events[$event])) {

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

			return $data;
		}

		if ($defaultReturn !== NULL) {
			return $defaultReturn;
		} else {
			return $data;
		}
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
		$method = 'result';

		// result_array/row_array or result/row
		$return_type = $this->return_type == 'array' ? $method . '_array' : $method;

		return $return_type;
	}


	/**
	 *
	 *
	 * @param array $array
	 */
	private function flatten_array(array $array) {
		$single = (count($array) <= 1);

		if ($single) {
			//If there is only one array element which itself has an array value,
			//"skip" its parent array
			//and just use that one sub-array as root array. This moves the
			//sub-array one level up
			$arrayValue = reset($array);
			if (is_array($arrayValue)) {
				$array = $arrayValue;
			}
		}

		//Check if there are any sub-arrays
		foreach ($array as $array_key=>$array_value) {

			if (is_array($array_value)) {
				$single = (count($array_value) <= 1);
				$array_value = $this->flatten_array($array_value);

				//Only do full flattening with single element arrays (or empty arrays)
				if ($this->_temporary_flat_full && $single) {
					unset($array[$array_key]);
					$array = array_merge($array, $array_value);
				} else {
					$array[$array_key] = $array_value;
				}

			}

		}

		return $array;
	}

	/**
	 *
	 *
	 * @param unknown $modelName
	 */
	protected function load_related_model($modelName) {
		$related = $modelName . '_related';
		$this->load->model($modelName, $related);

		//Also save queries in related models if set for this model
		$this->{$related}->save_queries($this->_save_queries);

		return $this->{$related};
	}


	/*----------------------------------------------------------------------------------------
	 * Query and relationship info
	 */

	/**
	 * Save the last query if saving queries is enabled
	 *
	 */
	protected function save_query() {
		if ($this->_save_queries) {
			$this->queries[] = $this->database->last_query();
		}
	}

	/**
	 * Get the queries form related models as well if saving queries is enabled
	 *
	 */
	protected function save_related_query($related_model_name) {
		if ($this->_save_queries) {
			//Get queries from related model and delete them from there
			$queries = $this->{$related_model_name}->get_queries(true);
			$this->queries[$related_model_name][] = $queries;
		}
	}

	/**
	 * Turns the saving of queries on or off
	 *
	 * @param boolean $save
	 */
	public function save_queries($save=true) {
		$this->_save_queries = $save;

		if (! $save) {
			$this->queries = null;
		} else if (count($this->queries) == 0) {
			//Prepare the array if there are no queries yet
			$this->queries = array();
		}
	}

	/**
	 * Returns the saved queries.
	 *
	 * @param boolean $reset If TRUE (default), the saved queries are cleared directly. If FALSE,
	 * the queries are kept in memory.
	 * @return NULL|array If saving queries is enabled, it returns an array with the queries. If
	 * saving queries is disabled, it returns NULL.
	 */
	public function get_queries($reset=true) {
		$queries = $this->queries;

		if ($reset) {
			$this->queries = array();
		}

		return $queries;
	}

	/**
	 * Prints the relationship of this table with other tables
	 *
	 */
	public function printRelationship() {
		$text = print_ln('<b><u>Table: ' . $this->_table . ' (' . get_class($this) . ')</u></b>', true);
		$text .= print_ln('', true);

		$count = 1;
		foreach ($this->related_to as $table=>$options) {
			$modelName = (isset($options['model']) ? $options['model'] : ucfirst(singular($table)) . '_model');

			$text .= print_array('<b><i>Relationship ' . $count . ':</i></b>', $options, ['model', 'related_keys'], [], true);

			$text .= print_ln('<u>Related to:</u> ' . $table . ' (' . $modelName . ')', true);
			$text .= print_ln('Related keys (as <i>local_key</i>=><i>foreign_key</i> or <i>local_key</i>=>[<i>foreign_keys</i>]):', true);

			if (!isset($options['related_keys'])) {
				$options['related_keys'] = [null=>null];
			}

			foreach ($options['related_keys'] as $local_key=>$foreign_keys) {
				$foreignKeys = null;
				$infoString = ' (';

				if ($local_key === '' || !is_string($local_key)) {
					$local_key = $this->primary_key;
					$infoString .= ' Local key was not provided. Using local primary key defined in ' . get_class($this) . ' instead.';
				}

				if ($foreign_keys === NULL) {
					$model = $this->load_related_model($modelName);
					$foreign_keys = $model->primary_key();
					$infoString .= ' Foreign key was not provided (NULL). Using foreign primary key defined in ' . $modelName . ' instead.';
				}

				$infoString .= ')';

				if (is_array($foreign_keys)) {
					$foreignKeys = '[' . implode(' OR ', $foreign_keys) . ']';
				} else {
					$foreignKeys = $foreign_keys;
				}

				$text .= print_ln('Local key <i>' . $local_key . '</i> related to foreign key <i>' . $foreignKeys . '</i>' . $infoString, true);
			}

			$text .= print_ln('', true);

			$count++;
		}

		return $text;

	}

}

?>

