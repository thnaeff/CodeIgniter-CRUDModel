# CodeIgniter-CRUDModel

Full CodeIgniter CRUD base for database interactions with
* function chaining
* event-based observer system ("before" and "after" triggers)
* table relations (belongs to, has many)
* protected fields
* available fields
* table name guessing

This CodeIgniter CRUD model has been created after being inspired by several existing CRUD models (e.g. [avenirers MY_Model](https://github.com/avenirer/CodeIgniter-MY_Model), [jamierumbelows BaseModel](https://github.com/jamierumbelow/codeigniter-base-model) and others). The main characteristic of this model is its simplicity and that it only provides the basic functionality; only one insert, one get, one update and one delete function. Additional functionality is carefully selected and kept at a minimum. This simplifies the usability of the model keeps the model clean and clear. Any more specific and less common functions are implemented in the `BaseModel` (as extended class of the `CRUDModel`) in order to separate basic and extended functionality.

-----------


## Installation



-------------


## CRUD Usage



### Get


### Insert


### Update


### Delete



------------

## Additional Usage


### Relations


### Event Callbacks/Observers


### Protected fields/Available fields


