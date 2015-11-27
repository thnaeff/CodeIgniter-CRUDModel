# CodeIgniter-CRUDModel

Full CodeIgniter CRUD base for database interactions with
* function chaining
* event-based observer system ("before" and "after" triggers)
* table relations (belongs to, has many)
* Use output (get result) as input (update/insert/delete), also with relationships
* protected fields
* available fields
* table name guessing
* flattened arrays (including multi-dimensional arrays of relationships)
* Simple pagination support

This CodeIgniter CRUD model has been created after being inspired by several existing CRUD models (e.g. [avenirers MY_Model](https://github.com/avenirer/CodeIgniter-MY_Model), [jamierumbelows BaseModel](https://github.com/jamierumbelow/codeigniter-base-model) and others). The main characteristic of this model is its simplicity and that it only provides the basic functionality; only one insert, one get, one update and one delete function. Some additional logic (relationships and flattened arrays) is added because of its tight integration into the basic functionality.<br />
More advanced functionality is carefully selected and kept at a minimum. This simplifies the usability of the model and keeps the model clean and clear. Any more specific and less common functions are implemented in the [BaseModel](https://github.com/thnaeff/CodeIgniter-BaseModel) (which extends the `CRUDModel` class) in order to separate basic and extended functionality.<br />


*Note:* This model is very easy to set up and use, but it also has its limitations. The table name guessing might fails sometimes (can be overwritten by setting the $_table variable directly) and resolving the relationships needs a query for each record and relationship (not as efficient as a direct SQL join for example). However, it is perfect (and a great starting point) for small to medium projects with basic table relations and it can easily be extended.

-----------


## Installation and implementation


Simply add this `CUDModel.php` file to your CodeIgniter project (preferably in the models directory application/models/), extend it in order to create your table models and configure your CodeIgniter database model. This should already be it to use the get/insert/update/delete functions. The table name guessing functionality of this `CUDModel.php` helps to link this model directly to its table.


**Example:**<br />
You have a table named "members" (plural, not singular "member") in your database. The standard CodeIgniter database model is configured (through config/database.php) to connect to your database. In order to create a working model for the table "members", you simply have to create a class called "Member_model" or "Member_m" (in your CI models directory, using the singular "Member") and have it extend the `CRUDModel`. Now load the model as you would load any other model in CI (e.g. `$this->load->model('member_model'))` and retrieve your data (e.g. `$this->member_model->get(5)`). <strong>That is all!</strong> 


-------------


## CRUD Usage


All the CRUD functions have two things in common: They only retrieve results from the table of their model and each of the four CRUD functions has a "before" and an "after" trigger which can be used to intercept the given parameters and the returned data (see [Event Callbacks/Observers](#event-callbacksobservers)). Also, each database access can be configured directly on the database object like any other CodeIgniter database by using the Query Builder. The database object can be accessed with the `db()` function.


For all CRUD functions, configuration on the database (using the CI Query Builder for example, accessible via `db()`) previous to the function call is considered as well when executing the database query.

 
*Note:* The primary keys(s) (parameter for the get, update and delete functions) can either be given as single value (e.g. `get(5)`) or as array of values (e.g. `get([1, 2, 3, 4, 5])`).


### Get
The get function offers the most possibilities and is therefore also the most complex one. It can be used as a very simple call to retrieve one single row (e.g. `$model->get(5)`), or it can be used to retrieve rows in connection with related tables and as flat array result (e.g. `$model->with('othertable')->flat()->get([2, 4, 6])`).

### Insert
Insert takes one row (a single array) or an array of rows (an array of arrays) and adds each row one by one. Before the data is written, however, each row passes through a data preparation function which makes sure that no protected fields and only available fields are in the row (see [Protected fields/Available fields](#protected-fieldsavailable-fields)). 

### Update
The update method takes a single row and the primary keys of the rows to update (optional). As for the insert function, the row data is prepared before it is used for the update.

### Delete
The most simple function is delete. It simply calls the Query Builder delete function and (if given) adds a where statement to limit the delete to the provided primary keys.


------------

## Additional Usage
In addition to the standard CRUD methods, this CRUDModel has some more functionality. Related tables can be defined, the CRUD calls can be intercepted through trigger functions and write data can be filtered by columns.


### Relations


### Event Callbacks/Observers


### Protected fields/Available fields


