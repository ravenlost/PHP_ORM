# CorbeauPerdu\ORM\ORMBase Class
ORM Library class to create or load an object representing a database table row (i.e. User, Contract, etc.).

**@requires DBWrapper <a href="https://github.com/ravenlost/CorbeauPerdu/tree/master/PHP/Database">https://github.com/ravenlost/CorbeauPerdu/tree/master/PHP/Database</a>**

**See: <a href="https://github.com/ravenlost/PHP_ORM/blob/master/UsageExamples/ORMUsageExamples.php">ORMUsageExamples.php</a>**

<h2>Defining an object's definition template:</h2>

To create a new object template, use <a href="https://github.com/ravenlost/PHP_ORM/blob/master/src/User.php">User.php</a> as a starting point.
The following methods needs to be called (in order!) and modified for your needs in the constructor:
<ol>
  <li>$this->setTablename()  : sets the database tablename</li>
  <li>$this->addMember()     : sets the column's properties (column name, type, default value, etc.)</li>
  <li>$this->setPrimaryKey() : sets the table's primary key column name (use the friendly key name given in addMember!)</li>
  <li>$this->setUseUpsert()  : force an UPDATE if an INSERT fails due to DUPLICATE KEY? In other words, does an UPSERT (Optional! Defaults to FALSE).</li>
  <li>parent::__construct()  : validates object is ready; also sets DBWrapper's DEBUG mode and its APPNAME for log outputs</li>
</ol>  

That's all you need! You can now use your Object class.

<h2>Using an object:</h2>

**Object Methods:**
<ol>
  <li>Object's constructor   : either creates a new object with default values, or loads an object from the database.</li>
  <li>$object->save()        : save the loaded object into the database.</li>
  <li>$object->delete()      : delete the loaded object from the database.</li>
  <li>$object->isDirty()     : is the object new, or as been modified?</li>
  <li>$object->propertyName  : set or get propertyName's column value!</li>
</ol>

**Static Methods:**
<ol>
  <li>Object::find()        : find and load objects from the database.</li>
  <li>Object::deleteQuery() : delete objects from the database.</li>
  <li>Object::getDBWrapper(): retrieve a DBWrapper object</li>
</ol>

**Thrown ORMExceptions listing:**
<ol>
  <li>Code 1  = Property isn't defined in the entity!</li>
  <li>Code 2  = Property is readonly!</li>
  <li>Code 3  = Tablename, primary key or member properties not properly set!</li>
  <li>Code 4  = Specified primary key isn't set in the entity!</li>
  <li>Code 5  = Wrong parameter type for filter!</li>
  <li>Code 6  = More than 1 row returned! Cannot initialize the object with many rows!</li>
  <li>Code 7  = Save aborted: the object was previously deleted from DB!</li>
  <li>Code 8  = Missing value for property!</li>
  <li>Code 9  = Rows might have been saved in the DB, but DB returned warnings (only if using MySQL for now!)</li>
  <li>Code 10 = Wrong number of rows updated in the DB after save()!</li>
  <li>Code 11 = Wrong number of rows removed in the DB after delete()!</li>
  <li>Code 12 = Can't delete an object that hasn't yet been saved in the database!</li>
  <li>Code 13 = Can't delete an object that doesn't have a value set in the primary key!</li>
  <li>Code 14 = Invalid datetime value for property</li>
  <li>Code 15 = Invalid value datatype for property</li>
  <li>Code 16 = There is no property with the given DB column name!</li>
</ol>

**Trick:**
The constructor(), find() and deleteQuery() all accept a *$forceCloseDB* and *$dbwrapper* reference parameter!
This allows you to either force closing the DB connection after each query, and it'll also allow you to use the
same DBWrapper / PDO Connection throughout your page if desired.

i.e. say you manipulate a User object, then load a Contract object, why have Contract use a different DBWrapper?<br/>
Initialize a DBWRapper with `$mydb = Object::getDBWrapper()` and pass it along to all objects!
