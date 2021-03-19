<?php

namespace CorbeauPerdu\ORM;

// Include config files
$ROOT = __DIR__ . '/../../../';
require_once ( $ROOT . '/include/CorbeauPerdu/Database/DBWrapper.php' );

use CorbeauPerdu\Database\DBWrapper;
use CorbeauPerdu\Database\Exceptions\DBWrapperException;
use Exception;
use DateTime;
use PDO;

/**
 * Base ORM object: all objects (i.e. User, Contract, etc) needs to extend this class!
 *
 * MIT License
 * 
 * Copyright (c) 2020 Patrick Roy
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * Special Thanks to P.Sicard for his help on this one explaining the idea and concept ;)
 *
 * @todo: eventually add the ability to set an array of columns as $primaryKey 
 * @todo: add a isUniqueValue("key", "value") value to check if a given member + value is already defined in the database (say to enforce a uniq constraint); will just do a select...
 * @todo: add a setDBWrapper() function to force set a DBWrapper private: 
 *        its args should be the same possible values as the DBWrapper's constructor (by config file, by IP, or eventually by DSN name)
 * @todo check if we can method overload from out child classes (i.e. override the delete() method to first send an email, and then call the parent's (this) delete() method!  
 *  
 * This would involve a lot of thickering around load(), save(), delete() and deleQueryRun() to check if primary key is array, etc.
 * Don't think I'll put this in place! Just place a damn auto-increment ID in those special tables ;P
 *
 * Last Modified : 2020/06/09 by PRoy - First release
 *                 2020/06/11 by PRoy - Added setUseUpsert() to do an UPSERT instead of INSERT: inserts row into database table (if it doesn't already exist) or updates it (if it does).
 *                                      (essentially, adds 'ON DUPLICATE KEY UPDATE' to the SQL INSERT...)
 *                 2020/06/12 by PRoy - Added a check for $this->isNew in delete() and adjusted the readData() calls for new version of DBWrapper
 *                 2020/06/20 by PRoy - Added $filter TRUE boolean option to ommit the WHERE clause!
 *                                      Added $multiTransactions to deleteQueryRun() to run a single transaction FOR EVERY ROW, else runs a single transaction for ALL ROWS!
 *                 2020/07/19 by PRoy - Minor bug fix in __set(): in "set property only if value differs" check, needed to add a check to also set value and isDirty if the entity's property value was NOT set!
 *                                      Otherwise, passing a value of 0 would fail to set it when new object!
 *                                      Also added a check for MySQL warnings in save() and create custom exception 'ORMException'.
 *                 2020/08/05 by PRoy - Bug fix: couldn't UPDATE primary key values! Added a private $primaryKeyInitialValue and now setting this initial primary key value upon loading the object.
 *                                      It is then used in the save()'s UPDATE WHERE clause, instead of the old entity[$this->primaryKey]['value'], which would have the 'new' value, thus failing the where clause!
 *                 2020/08/08 by PRoy - Minor bug fix in the way we checked if property value is different and newvalue in the __set() and added a return value in save()
 *                 2020/09/16 by PRoy - Added magic __isset()
 *                 2021/03/19 by PRoy - Added isLoaded() state and other bux fix when load() would return 0 rows and but DBWrapper was set to not through an exception when no data found. 
 *                                                       
 * @author Patrick Roy (ravenlost2@gmail.com)
 * @version 1.2.5
 * @uses \CorbeauPerdu\Database\DBWrapper
 */
class ORMBase
{
  // column types: array(0) = determines the datatype check to use (checks against gettype()) to validate proper value for property!
  //               array(1) = force what datatype into database for the named parameters with $paramValues / bindParam()
  protected const COLTYPE_BOOL = [  'boolean', PDO::PARAM_BOOL ];
  protected const COLTYPE_INT = [  'integer', PDO::PARAM_INT ];
  protected const COLTYPE_TINYINT = self::COLTYPE_INT;
  protected const COLTYPE_DOUBLE = [  'double', PDO::PARAM_STR ];
  protected const COLTYPE_DECIMAL = self::COLTYPE_DOUBLE;
  protected const COLTYPE_FLOAT = self::COLTYPE_DOUBLE;
  protected const COLTYPE_STRING = [  'string', PDO::PARAM_STR ];
  protected const COLTYPE_VARCHAR = self::COLTYPE_STRING;
  protected const COLTYPE_OBJECT = [  'object', PDO::PARAM_STR ];
  protected const COLTYPE_BINARY = [  'binary', PDO::PARAM_LOB ]; // there's no 'binary' type returned by gettype()! Need to do custom check in validDatatype() ?
  protected const COLTYPE_DATETIME = [  'datetime', PDO::PARAM_STR ]; // there's no 'datetime' type returned by gettype()! Need to do custom check in validDatatype() ?

  /**
   * database tablename
   * @var string
   */
  private $tablename;

  /**
   * table's primary key
   * should be the entity keyname representing the table's primary key column (a.k.a the friendly name given for db column)
   * @var string
   */
  private $primaryKey;

  /**
   * Initial value set in the primary key upon loading the object.
   * This is required for the save()'s SQL UPDATE, in case a user changes the value of the primary key! 
   * @var string
   */
  private $primaryKeyInitialValue;

  /**
   * The entity data
   * @var array
   */
  private $entity = [ ];

  /**
   * DBWrapper object to do database queries with
   * @var object
   */
  private $dbwrapper;

  /**
   * DBWrapper is set in debug mode?
   * @var boolean
   */
  private $debug;

  /**
   * Name of project/application: needed for DBWrapper log outputs
   * @var string
   */
  private $appname;

  /**
   * Was the entity modified? If false, then do nothing upon save()
   * @var boolean
   */
  private $isDirty = false;

  /**
   * Is the entity newly created? If true, save() will sql INSERT, else save() will sql UPDATE
   * @var boolean
   */
  private $isNew = false;

  /**
   * Is the entity loaded from DB? 
   * If an object was properly loaded, it'll become true;
   * else, assuming object's data wasn't loaded from DB
   * @var boolean
   */
  private $isLoaded = false;
  
  /**
   * Default valid datetime format if not specified within addMember()
   * @var string
   */
  private $datetimeformat = 'Y-m-d H:i:s';

  /**
   * Flag to set when object is deleted from DB to prevent users from re-saving in database!
   * @var boolean
   */
  private $deleted = false;

  /**
   * If duplicate entry in database, update instead of insert?
   * @var boolean
   */
  private $useUpsert = false;

  /**
   * getEntity()
   * For debug only - Returns the entity object to view its content!
   */
  public function getEntity(){
    return $this->entity;
  }
  
  /**
   * Magic getter method __get()
   * Called whenever you attempt to read a non-existing or private property of an object.
   * @param string $property Property name to get
   * @return mixed|null
   */
  public function __get(string $property)
  {
    if ( ! array_key_exists($property, $this->entity) ) throw new ORMException("Property '$property' isn't defined in the entity!", 1);
    return $this->entity[$property]['value'] ?? null;
  }

  /**
   * Magic setter method  __set()
   * Called whenever you attempt to write to a non-existing or private property of an object.
   * @param string $property Name of property
   * @param mixed $value Value of property
   * @return void
   */
  public function __set(string $property, $value)
  {
    // validate property exists!
    if ( ! array_key_exists($property, $this->entity) ) throw new ORMException("Property '$property' isn't defined in the entity!", 1);

    // validate allowed to modify the property
    if ( $this->entity[$property]['readonly'] ) throw new ORMException("Property '$property' is readonly!", 2);
   
    // set property only if value differs and set isDirty!
    if ( ( ! isset($this->entity[$property]['value']) and (isset($value) ) ) or ($this->entity[$property]['value'] != $value) )
    {
      try
      {
        // validate value proper datatype!
        $this->validDataType($property, $value);

        $this->entity[$property]['value'] = $value;
        $this->isDirty = true;
      }
      catch ( Exception $ex )
      {
        throw $ex;
      }
    }
  }

  /**
   * Magic isset/empty check
   * Called whenever a isset() or empty() is called on a column's property.
   * @param string $property
   * @return boolean
   */
  public function __isset(string $property)
  {
    // empty() returns FALSE if var exists and has a non-empty, non-zero value. Otherwise returns TRUE.
    // but still need to reverse returned value from empty(), and I don't understand why!
    return  ( empty($this->entity[$property]['value'] ) == false );
  }

  /**
   * constructor()
   * This is called at the end of the object's constructors (i.e. User.php)
   * It just validates the object is properly set!
   *
   * @param bool $debug set DBWrapper debug state
   * @param string $appname set DBWrapper project name for log outputs
   * @throws ORMException
   */
  public function __construct(bool $debug = false, string $appname = 'UNDEFINED_PROJECT')
  {
    if ( ( ! $this->tablename ) or ( ! $this->primaryKey ) or ( empty($this->entity) ) ) throw new ORMException('Error! Tablename, primary key or member properties not properly set!', 3);

    // set DBWrapper constructor args
    $this->debug = $debug;
    $this->appname = $appname;
  }

  /**
   * setTablename()
   * @param string $tablename
   */
  protected function setTablename(string $tablename)
  {
    $this->tablename = $tablename;
  }

  /**
   * setPrimaryKey()
   * The $key should be the entity keyname representing the table's primary key column (a.k.a the friendly name given for db column)
   * @param string $key
   * @throws ORMException
   */
  protected function setPrimaryKey(string $key)
  {
    if ( array_key_exists($key, $this->entity) === false )
    {
      throw new ORMException("Specified primary key '$key' isn't set in the entity!", 4);
    }

    $this->primaryKey = $key;
  }

  /**
   * setIsNew()
   * Is the entity a new object (otherwise, was loaded from DB)
   * @param bool $v
   */
  protected function setIsNew(bool $v)
  {
    $this->isNew = $v;
  }

  /**
   * isNew()
   * Set is the entity a new object (otherwise, was loaded from DB)
   * @return bool
   */
  public function isNew()
  {
    return $this->isNew;
  }

  /**
   * setIsLoaded()
   * Set is the entity loaded from the DB
   * @param bool $v
   */
  protected function setIsLoaded(bool $v)
  {
    $this->isLoaded = $v;
  }
  
  /**
   * isLoaded()
   * Is the entity loaded from DB? <br/>
   * Will be true if an object was properly loaded,<br/>
   * else, assuming object's data wasn't loaded from DB. 
   * @return bool
   */
  public function isLoaded()
  {
    return $this->isLoaded;
  }
  
  /**
   * setDateTimeFormat()
   * Set default valid datetime format
   * @param string $format (i.e. 'Y-m-d H:i:s')
   * @see DateTime::createFromFormat()
   */
  protected function setDateTimeFormat(string $format)
  {
    $this->datetimeformat = $format;
  }

  /**
   * When inserting, if duplicate entry in database, update instead of insert?
   * i.e. adds 'ON DUPLICATE KEY UPDATE...' to the SQL insert
   * @param bool $v
   * @return bool old set value
   */
  protected function setUseUpsert(bool $v)
  {
    $ov = $this->useUpsert;
    $this->useUpsert = $v;
    return $ov;
  }

  /**
   * isDirty()
   * Is the object new or as been modified?
   * @return boolean
   */
  public function isDirty()
  {
    return $this->isDirty ?: $this->isNew;
  }

  /**
   * addMember()
   * Populate the entity object with its properties (column name, datatype, value, etc.)
   * @param string $key friendly name of column used as entitity keys (i.e. 'username' instead of 'fk_tbl_users_username' for instance) : the magic __getters and __setters will use these friendly names
   * @param string $column table column name
   * @param string $type column's datatype: MUST use the 'COLTYPE_*' constants!
   * @param bool $readonly (optional!) is the column's value readonly? Default is false.
   * @param bool $required (optional!) force user to define column's value in the entity? Default is false.
   * @param mixed $value (optional!) column's value (default if new, otherwise loaded value from DB). Default is NULL.
   * @param mixed $valueIfNull (optional!) value to push to DB if $value is null. Default is NULL.
   * @param bool $useDBDefaultWhenNull (optional!) if both $value and $valueIfNull are null, use DB's DEFAULT value? Default is false.
   * @param string $datetimeformat (optional!) if $type is COLTYPE_DATETIME, what should the valid datetime format be? Defaults to 'Y-m-d H:i:s' if not specied.
   * @see DateTime::createFromFormat()
   */
  protected function addMember(string $key, string $column, array $type, bool $readonly = false, bool $required = false, $value = null, $valueIfNull = null, bool $useDBDefaultWhenNull = false, string $datetimeformat = null)
  {
    //@formatter:off
    $this->entity += [
        $key => [
        'column' => $column,
        'type' => $type,
        'readonly' => $readonly,
        'required' => $required,
        'value' => $value,
        'valueIfNull' => $valueIfNull,
        'useDBDefaultWhenNull' => $useDBDefaultWhenNull,
        'datetimeformat' => $datetimeformat
      ]
    ];
    //@formatter:on
  }

  /**
   * load()
   * Load entity values from database.
   *
   * WARNING about $order and $limit parameters: these are straight forward SQL !
   * NOT using named parameters here to protect against SQL Injections! You need to sanitize them!
   * @todo Could eventually use $paramValuesArg to sanitize $order and $limit ... ?
   *
   * @param mixed $filter builts the WHERE clause based on filter options:
   * <table>
   * <tr>
   *   <td width="35%">1. integer | single-word string</td><td>5, 'jdoe@email.com', 'username'</td>
   * </tr>
   * <tr>
   *   <td width="35%">2. array</td><td>[ 'isActive' => true, 'country' => 'ca' ]</td>
   * </tr>
   * <tr>
   *   <td width="35%">3. string</td><td>"email LIKE '%gmail.com'"</td>
   * </tr>
   * <tr>
   *   <td width="35%">4. boolean</td><td>TRUE</td>
   * </tr>
   * <tr>
   *   <td colspan="2">
   *   <p><i>Option 1: will load found object using the table's primary key column.</i></p>
   *   <p><i>Option 2: will load found objects from table based on defined column values. The 'column' should be the friendly name of column (i.e. entity keyname!).</i></p>
   *   <p><i>Option 3: you provide a string with content of WHERE clause to load objects from the table: use the actual DB column names!</i></p>
   *   <p><i>Option 4: you provide boolean value TRUE: all rows will be returned (a.k.a. OMMITS the WHERE clause!)</i></p>
   *   <p>Warning! This 3rd filter option is NOT the safest, UNLESS you tag along the $paramValuesArg!</p>
   *   <code>
   *   $filter = "email LIKE :param";<br>
   *   $paramValuesArg = "%gmail.com";
   *   </code>
   *   </td>
   * </tr>
   * </table>
   *
   * @param mixed $paramValuesArg (optional!) named parameter values to pass to readData(). Applies only if $filter is a straight SQL WHERE clause string
   * @see \CorbeauPerdu\Database\DBWrapper::readData()
   *
   * @param string $order (optional!) ORDER BY sql (i.e. 'id DESC')
   * @param string $limit (optional!) LIMIT sql (i.e. '5')
   * @param bool $returnEntities (optional!) return a single or an array of entitites (used by the Object::find() static function!)
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   *
   * @return mixed loaded entities from database (only if called from Object::find()
   * @throws DBWrapperException
   * @throws ORMException
   */
  protected function load($filter, $paramValuesArg = null, string $order = null, string $limit = null, bool $returnEntities = false, $forceCloseDB = false, &$db = null)
  {
    try
    {
      $whereClause = null;
      $whereConditions = [ ];
      $paramValues = null;

      // -------------------------------
      // BUILD THE SQL
      // -------------------------------

      // fetch conditions in array args
      if ( is_array($filter) and ! empty($filter) )
      {
        $paramValues = [ ];

        foreach ( $filter as $key => $value )
        {
          // first, check the key (column) is defined in the entity
          if ( ! array_key_exists($key, $this->entity) ) throw new ORMException("Property '$key' isn't defined in the entity!", 1);

          // now, check if value is of right datatype!
          try
          {
            $this->validDataType($key, $value);
          }
          catch ( Exception $ex )
          {
            throw $ex;
          }

          $whereConditions[] = $this->entity[$key]['column'] . "=:$key";
          $paramValues += [  ":$key" => [  $value, $this->entity[$key]['type'][1] ?? PDO::PARAM_STR] ];
        }

        $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
      }

      elseif ( ( is_string($filter) or is_int($filter) ) and ( ! empty($filter) ) )
      {
        // one-word string or integer argument passed, assume lookup from primary key
        if ( ! preg_match('/ |=|>|</', $filter) )
        // if ( preg_match('/^[a-zA-Z0-9@\.-_]+$/', $filter) ) // might miss special cases where one-word string that would need a special chars in it (i.e. an email address)
        {
          try
          {
            $this->validDataType($this->primaryKey, $filter);
          }
          catch ( Exception $ex )
          {
            throw $ex;
          }

          $whereClause = ' WHERE ' . $this->entity[$this->primaryKey]['column'] . '=:' . $this->primaryKey;
          $paramValues = [  ":$this->primaryKey" => [  $filter, $this->entity[$this->primaryKey]['type'][1] ?? PDO::PARAM_STR] ];
        }

        // user builds the WHERE clause (NOT THE SAFEST! Unless $paramValuesArg is defined!)
        else
        {
          $whereClause = ' WHERE ' . $filter;

          /**
           * use param value argument if user provided it to tag along his filter clause
           * Example: $filter = 'email LIKE :pattern';
           *          $paramValuesArg = "%gmail.com";
           */
          $paramValues = $paramValuesArg;
        }
      }

      // if filter is boolean and TRUE, then select * from table!
      elseif ( is_bool($filter) and $filter === true )
      {
        $whereClause = '';
      }

      else
      {
        throw new ORMException('Wrong parameter type for filter!', 5);
      }

      // create DBWrapper instance if not passed in args
      if ( ! isset($db) )
      {
        $this->dbwrapper = self::getDBWrapper($this->debug, $this->appname);
        $db = &$this->dbwrapper;
      }

      $sql = 'SELECT * FROM ' . $this->tablename . $whereClause;

      // append order and limit sql (NO SQL Injection protecttion here! Users need to sanitize values, for now at least!!)
      if ( isset($order) and ! empty(trim($order)) ) $sql .= " ORDER BY $order";
      if ( isset($limit) and ! empty(trim($limit)) ) $sql .= " LIMIT $limit";

      //       var_dump($sql);
      //       var_dump($paramValues);

      // -------------------------------
      // FETCH DATA FROM DB
      // -------------------------------
      // @todo see about using PDO::FETCH_OBJ, PDO::FETCH_CLASS or array(PDO::FETCH_INTO, $this) in readData() to prevent having to loop data to assign. See M.Viau's FB message...
      $data = $db->readData($sql, $paramValues, null, null, true, $forceCloseDB);
      $rowsfound = $db->getAffectedRows();

      //----------------------
      // single row returned
      if ( $rowsfound == 1 )
      {
        // get the row's columns values and fill in single entity properties
        foreach ( $data[0] as $col => $val )
        {
          $entity_key = $this->getEntityKey($col);
          $this->entity[$entity_key]['value'] = $val;

          // set object's initial primary key value (this is needed in case user can MODIFIES the primary key value!)
          // it'll be used on SQL UPDATES !
          if ( $entity_key === $this->primaryKey )
          {
            $this->primaryKeyInitialValue = $val;
          }
        }
        
        // set object to loaded
        $this->setIsLoaded(true);

        // return instance of this object (this will only be true if coming from Object::find() method, defined in ORMExtras trait which the object classes (User) uses.
        if ( $returnEntities )
        {
          // the returned enitity is NOT new! Set it so, otherwise find() will think each objects are new!
          $this->setIsNew(false);
          return $this;
        }
      }

      //----------------------
      //  multiple rows returned (zero rows caught in DBWrapperException below)
      else
      {
        if ( $returnEntities === false )
        {
          if($rowsfound > 1) {
            throw new ORMException("More than 1 row returned (total $rowsfound)! Cannot initialize the object with many rows! Use " . get_class($this) . '::find() to get an array of objects.', 6);
          }
          else {
            // 0 rows found : this case scenario would normally but caught by the DBWrapperException below,
            // UNLESS the DBWrapper's setThrowExOnNoData() is set to FALSE. In such a case, 
            // one has to use $object->isLoaded() method to know if object was properly loaded or not. 
          }
        }
        else
        {
          // we'll get here only from the Object::find() function!
          $entities = array (); // build an array of entities to return

          foreach ( $data as $row )
          {
            $new_entity = new $this();

            foreach ( $row as $col => $val )
            {
              $entity_key = $new_entity->getEntityKey($col);
              $new_entity->entity[$entity_key]['value'] = $val;

              // set object's initial primary key value (this is needed in case user can MODIFIES the primary key value!)
              if ( $entity_key === $new_entity->primaryKey )
              {
                $new_entity->primaryKeyInitialValue = $val;
              }
            }

            // the returned entities are NOT new! Set them so, otherwise find() will think each objects are new!
            $new_entity->setIsNew(false);
            
            // set entities to loaded
            $new_entity->setIsLoaded(true);
            
            array_push($entities, $new_entity);
          }

          return $entities;
        }
      }

      // unset DBWrapper if $forceCloseDB:
      // only unsetting DBWrapper objects created within this class and NOT the one passed in args by reference!
      if ( $forceCloseDB )
      {
        $this->dbwrapper = null; // don't use unset() !
      }
    }
    catch ( DBWrapperException $ex )
    {
      throw $ex;
    }
    catch ( Exception $ex )
    {
      throw $ex;
    }
  }

  /**
   * save()
   * Save object in database.
   * Notes: not doing any datatype validation here, since we're doing in the magic __set() function on each set values!
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   * @return boolean|int false if object isn't being saved because it's NOT dirty, else the number of affected rows (should be just 1)
   * @throws DBWrapperException
   * @throws ORMException
   */
  public function save($forceCloseDB = false, &$db = null)
  {
    $forceCloseDB = ( $forceCloseDB ) ?: false;

    // proceed only if object was previously deleted!
    if ( $this->deleted ) throw new ORMException("The '" . get_class($this) . "' object (key " . $this->entity[$this->primaryKey]['column'] . ' = ' . $this->entity[$this->primaryKey]['value'] . ") was previously deleted from the database! Save aborted...", 7);

    // do we actually need to save ?
    if ( ( $this->isNew === false ) and ( $this->isDirty === false ) ) return false;

    // loop entity data and check all required fields are set (also check for a valueIfNull)
    foreach ( $this->entity as $key => $prop )
    {
      $propValue = $prop['value'] ?? $prop['valueIfNull'];
      if ( $prop['required'] and ( ! isset($propValue) or ( is_string($propValue) and trim($propValue) === '' ) ) ) throw new ORMException("Missing value for property '$key'!", 8);
    }

    // get DBWrapper object
    if ( ! isset($db) )
    {
      if ( isset($this->dbwrapper) )
      {
        $db = &$this->dbwrapper;
      }
      else
      {
        $this->dbwrapper = self::getDBWrapper($this->debug, $this->appname);
        $db = &$this->dbwrapper;
      }
    }

    // loop entity data, build SQL and run!
    $paramValues = [ ];

    // ---------------------
    // build sql insert
    if ( $this->isNew === true )
    {
      $sqlColumnNames = '';
      $sqlColumnValues = '';

      if ( $this->useUpsert ) $sqlColValPairs = '';

      // build the column names, named parameters and parameter values
      foreach ( $this->entity as $key => $prop )
      {
        // use prop's 'valueIfNull' if no value is set
        $value = $prop['value'] ?? $prop['valueIfNull'];

        $sqlColumnNames .= $prop['column'] . ',';

        if ( $this->useUpsert ) $sqlColValPairs .= $prop['column'] . '=';

        // if we don't have any value and 'useDBDefaultWhenNull' is true, change just add DEFAULT to the SQL
        if ( ! isset($value) and ( $prop['useDBDefaultWhenNull'] === true ) )
        {
          $sqlColumnValues .= "DEFAULT" . ',';
          if ( $this->useUpsert ) $sqlColValPairs .= "DEFAULT" . ',';
        }
        // else if we have a value or 'useDBDefaultWhenNull' is false, append named sql parameter and set its value in $paramValues
        else
        {
          $sqlColumnValues .= ":$key" . ',';
          $paramValues += [  ":$key" => [  $value, $prop['type'][1] ?? PDO::PARAM_STR] ];

          if ( $this->useUpsert )
          {
            $sqlColValPairs .= ":u$key" . ',';
            $paramValues += [  ":u$key" => [  $value, $prop['type'][1] ?? PDO::PARAM_STR] ];
          }
        }
      }

      // remove trailing ','
      $sqlColumnNames = rtrim($sqlColumnNames, ",");
      $sqlColumnValues = rtrim($sqlColumnValues, ",");

      if ( $this->useUpsert ) $sqlColValPairs = rtrim($sqlColValPairs, ",");

      $sql = "INSERT INTO $this->tablename ($sqlColumnNames) VALUES ($sqlColumnValues)";

      // if we want to update if DUPLICATE KEY found ?
      if ( $this->useUpsert ) $sql .= " ON DUPLICATE KEY UPDATE $sqlColValPairs";
    }

    // ---------------------
    // built sql update
    else
    {
      $sqlColValPairs = '';

      // build the column names, named parameters and parameter values
      foreach ( $this->entity as $key => $prop )
      {
        // use prop's 'valueIfNull' if no value is set
        $value = $prop['value'] ?? $prop['valueIfNull'];

        $sqlColValPairs .= $prop['column'] . '=';

        // if we don't have any value and 'useDBDefaultWhenNull' is true, change just add DEFAULT to the SQL
        if ( ! isset($value) and ( $prop['useDBDefaultWhenNull'] === true ) )
        {
          $sqlColValPairs .= "DEFAULT" . ',';
        }
        // else if we have a value or 'useDBDefaultWhenNull' is false, append named sql parameter and set its value in $paramValues
        else
        {
          $sqlColValPairs .= ":$key" . ',';
          $paramValues += [  ":$key" => [  $value, $prop['type'][1] ?? PDO::PARAM_STR] ];
        }
      }

      $sqlColValPairs = rtrim($sqlColValPairs, ",");

      $sql = "UPDATE $this->tablename SET $sqlColValPairs WHERE " . $this->entity[$this->primaryKey]['column'] . "=:prikeyval";
      $paramValues += [  ':prikeyval' => [  $this->primaryKeyInitialValue, $this->entity[$this->primaryKey]['type'][1] ?? PDO::PARAM_STR] ];
    }

    // ---------------------
    // run the sql
    try
    {
      $success = $db->storeData($sql, $paramValues, null, null, false);

      // fetch warnings if any: applies if using MySQL only for now...
      $dbwarnings = null;
      $dbwarningsErrStr = null;

      if ( $db->getDbType() == $db::DBTYPE_MYSQL )
      {
        $dbwarnings = $db->getMySQLWarnings();

        if ( ! empty($dbwarnings) )
        {
          $dbwarningsErrStr = 'MySQL Returned warnings:' . PHP_EOL . PHP_EOL;

          foreach ( $dbwarnings as $w )
          {
            $dbwarningsErrStr .= 'Level: ' . $w->Level . PHP_EOL;
            $dbwarningsErrStr .= 'Code: ' . $w->Code . PHP_EOL;
            $dbwarningsErrStr .= 'Message: ' . $w->Message . PHP_EOL . PHP_EOL;
            //           $dbwarningsErrStr .= '- ' . $w->Message . ' [code ' . $w->Code . '].' . PHP_EOL;
          }

          $dbwarningsErrStr .= "SQL = $sql" . PHP_EOL;
          $dbwarningsErrStr .= 'ParamValues = ' . json_encode($paramValues);
        }
      }

      // check affected rows results
      if ( ( $this->isNew === true ) and ( $this->useUpsert === true ) )
      {
        // with ON DUPLICATE KEY UPDATE, the affected-rows value ($success) is 1 if the row is inserted as a new row, 2 if an existing row is updated...
        // assuming it deleted the existing (1 affected row) + it inserted the new data (1 affected row)
        if ( ( $success != 1 ) and ( $success != 2 ) )
        {
          if ( isset($dbwarningsErrStr) )
          {
            throw new ORMException("Something's wrong! A total of $success rows were updated in the database! Some fields might have failed to update..." . PHP_EOL . PHP_EOL . $dbwarningsErrStr, 9, null, $dbwarnings);
          }
          else
          {
            throw new ORMException("Something's wrong! A total of $success rows were updated in the database!" . PHP_EOL . PHP_EOL . "SQL = $sql" . PHP_EOL . 'ParamValues = ' . json_encode($paramValues), 10);
          }
        }
      }
      else
      {
        // should have updated / inserted only 1 row!
        if ( $success != 1 )
        {
          if ( isset($dbwarningsErrStr) )
          {
            throw new ORMException("Something's wrong! A total of $success rows were updated in the database! Some fields might have failed to update..." . PHP_EOL . PHP_EOL . $dbwarningsErrStr, 9, null, $dbwarnings);
          }
          else
          {
            throw new ORMException("Something's wrong! A total of $success rows were updated in the database!" . PHP_EOL . PHP_EOL . "SQL = $sql" . PHP_EOL . 'ParamValues = ' . json_encode($paramValues), 10);
          }
        }
      }

      // if we did an INSERT, update entity's primary key value with $db->getLastInsertId()
      // if table doesn't have an auto-increment primary key, then getLastInsertId() / PDO::lastInsertId should return '0'
      if ( $this->isNew === true )
      {
        $lastInsertID = $db->getLastInsertId();
        if ( isset($lastInsertID) and ( $lastInsertID != 0 ) ) $this->entity[$this->primaryKey]['value'] = $lastInsertID;
      }

      // update object's initial primary key value in case we've updated it from an UPDATE
      $this->primaryKeyInitialValue = $this->entity[$this->primaryKey]['value'];

      // reset state of object
      $this->isDirty = false;
      $this->isNew = false;
      $this->isLoaded = true;

      // Close DB? don't close in storeData: the closing needs to be done AFTER the getMySQLWarnings() check!
      // only unsetting DBWrapper objects created within this class and NOT the one passed in args by reference!
      if ( $forceCloseDB )
      {
        $db->closeDB();
        $this->dbwrapper = null; // don't use unset() !
      }

      // lastly, if we have any warnings, throw exception with it! This will only possibly be set if using MySQL for now...   
      if ( isset($dbwarningsErrStr) )
      {
        throw new ORMException("Something's wrong! A total of $success rows were updated in the database! Some fields might have failed to update..." . PHP_EOL . PHP_EOL . $dbwarningsErrStr, 9, null, $dbwarnings);
      }

      // return success
      return $success;
    }
    catch ( DBWrapperException $ex )
    {
      throw $ex;
    }
  }

  /**
   * delete()
   * Delete the loaded object from the database.
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   * @throws DBWrapperException
   * @throws ORMException
   */
  public function delete($forceCloseDB = false, &$db = null)
  {
    if ( $this->isNew === true ) throw new ORMException("Can't delete an object that hasn't yet been saved in the database!", 12);

    if ( ! isset($this->entity[$this->primaryKey]['value']) ) throw new ORMException("Can't delete an object that doesn't have a value set in the primary key ('$this->primaryKey') !", 13);

    $forceCloseDB = ( $forceCloseDB ) ?: false;

    // get DBWrapper object
    if ( ! isset($db) )
    {
      if ( isset($this->dbwrapper) )
      {
        $db = &$this->dbwrapper;
      }
      else
      {
        $this->dbwrapper = self::getDBWrapper($this->debug, $this->appname);
        $db = &$this->dbwrapper;
      }
    }

    try
    {
      $sql = "DELETE FROM $this->tablename WHERE " . $this->entity[$this->primaryKey]['column'] . "=:prikeyval";
      $paramValues = [  ':prikeyval' => [  $this->entity[$this->primaryKey]['value'], $this->entity[$this->primaryKey]['type'][1] ?? PDO::PARAM_STR] ];

      $success = $db->storeData($sql, $paramValues, null, null, $forceCloseDB);

      // should have removed only 1 row!
      if ( $success != 1 ) throw new ORMException("Something's wrong! A total of $success rows were removed from the database!" . PHP_EOL . "SQL = $sql" . PHP_EOL . 'ParamValues = ' . json_encode($paramValues), 11);

      // set object as deleted to prevent user from trying to save again!
      $this->deleted = true;

      // unset DBWrapper if $forceCloseDB:
      // only unsetting DBWrapper objects created within this class and NOT the one passed in args by reference!
      if ( $forceCloseDB )
      {
        $this->dbwrapper = null; // don't use unset() !
      }
    }
    catch ( DBWrapperException $ex )
    {
      throw $ex;
    }
  }

  /**
   * deleteQueryRun()
   * Run a delete request on the object's table, based on given $filter.
   * Used by the Object::deleteQuery() static function!
   *
   * @param mixed $filter builts the WHERE clause based on filter options:
   * <table>
   * <tr>
   *   <td width="35%">1. integer | single-word string</td><td>5, 'jdoe@email.com', 'username'</td>
   * </tr>
   * <tr>
   *   <td width="35%">2. array</td><td>[ 'isActive' => true, 'country' => 'ca' ]</td>
   * </tr>
   * <tr>
   *   <td width="35%">3. string</td><td>"email LIKE '%gmail.com'"</td>
   * </tr>
   * <tr>
   *   <td width="35%">4. boolean</td><td>TRUE</td>
   * </tr>
   * <tr>
   *   <td colspan="2">
   *   <p><i>Option 1: will delete object using the table's primary key column.</i></p>
   *   <p><i>Option 2: will delete objects from table based on defined column values. The 'column' should be the friendly name of column (i.e. entity keyname!).</i></p>
   *   <p><i>Option 3: you provide a string with content of WHERE clause to delete objects from the table: use the actual DB column names!</i></p>
   *   <p><i>Option 4: you provide boolean value TRUE: ALL ROWS WILL BE DELETED (a.k.a. OMMITS the WHERE clause!)!!!</i></p>
   *   <p>Warning! This 3rd filter option is NOT the safest, UNLESS you tag along the $paramValuesArg!</p>
   *   <code>
   *   $filter = "email LIKE :param";<br>
   *   $paramValuesArg = "%gmail.com";
   *   </code>
   *   </td>
   * </tr>
   * </table>
   *
   * @param mixed $paramValuesArg (optional!) named parameter values to pass to readData(). Applies only if $filter is a straight SQL WHERE clause string
   * @see \CorbeauPerdu\Database\DBWrapper::readData()
   *
   * @param boolean $multiTransactions run a single transaction FOR EVERY ROW, else runs a single transaction for ALL ROWS!
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   *
   * @throws DBWrapperException
   * @throws ORMException
   *
   * @todo: allow sending of a list (or simple array) to use in a WHERE colxyz IN () clause: 
   * if filter = (array or string) and arg list = true
   * $filter = ['colxyz' => "'v1','v2','v3'" ] // value could a string or an array
   * 
   * dynamically build WHERE with: colxyz IN (:p1,:p2,:p3 )
   * and split "'v1','v2','v3'" into seperate elements array in $paramValues to protect agains SQL Injections
   *
   * @return integer number of successful deletes
   */
  protected function deleteQueryRun($filter, $paramValuesArg = null, $multiTransactions = false, $forceCloseDB = false, &$db = null)
  {
    try
    {
      $whereClause = null;
      $whereConditions = [ ];
      $paramValues = null;

      // -------------------------------
      // BUILD THE SQL
      // -------------------------------

      // fetch conditions in array args
      if ( is_array($filter) and ! empty($filter) )
      {
        $paramValues = [ ];

        foreach ( $filter as $key => $value )
        {
          // first, check the key (column) is defined in the entity
          if ( ! array_key_exists($key, $this->entity) ) throw new ORMException("Property '$key' isn't defined in the entity!", 1);

          // now, check if value is of right datatype!
          try
          {
            $this->validDataType($key, $value);
          }
          catch ( Exception $ex )
          {
            throw $ex;
          }

          $whereConditions[] = $this->entity[$key]['column'] . "=:$key";
          $paramValues += [  ":$key" => [  $value, $this->entity[$key]['type'][1] ?? PDO::PARAM_STR] ];
        }

        $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
      }

      elseif ( ( is_string($filter) or is_int($filter) ) and ( ! empty($filter) ) )
      {
        // one-word string or integer argument passed, assume lookup from primary key
        if ( ! preg_match('/ |=|>|</', $filter) )
        // if ( preg_match('/^[a-zA-Z0-9@\.-_]+$/', $filter) ) // might miss special cases where one-word string that would need a special chars in it (i.e. an email address)
        {
          try
          {
            $this->validDataType($this->primaryKey, $filter);
          }
          catch ( Exception $ex )
          {
            throw $ex;
          }

          $whereClause = ' WHERE ' . $this->entity[$this->primaryKey]['column'] . '=:' . $this->primaryKey;
          $paramValues = [  ":$this->primaryKey" => [  $filter, $this->entity[$this->primaryKey]['type'][1] ?? PDO::PARAM_STR] ];
        }

        // user builds the WHERE clause (NOT THE SAFEST! Unless $paramValuesArg is defined!)
        else
        {
          $whereClause = ' WHERE ' . $filter;

          /**
           * use param value argument if user provided it to tag along his filter clause
           * Example: $filter = 'email LIKE :pattern';
           *          $paramValuesArg = "%gmail.com";
           */
          $paramValues = $paramValuesArg;
        }
      }

      // if filter is boolean and TRUE, then select * from table!
      elseif ( is_bool($filter) and $filter === true )
      {
        $whereClause = '';
      }

      else
      {
        throw new ORMException('Wrong parameter type for filter!', 5);
      }

      // get DBWrapper object
      if ( ! isset($db) )
      {
        $db = self::getDBWrapper($this->debug, $this->appname);
      }

      $sql = 'DELETE FROM ' . $this->tablename . $whereClause;

      //       var_dump($sql);
      //       var_dump($paramValues);

      $success = $db->storeData($sql, $paramValues, null, $multiTransactions, $forceCloseDB);

      return $success;
    }
    catch ( DBWrapperException $ex )
    {
      throw $ex;
    }
  }

  /**
   * getDBWrapper()
   * Retrieve a DBWrapper Object
   * @param bool $debug set DBWrapper debug state
   * @param string $appname set DBWrapper project name for log outputs
   * @return object DBWrapper instance
   * @throws DBWrapperException
   */
  public static function getDBWrapper(bool $debug = false, string $appname = 'UNDEFINED_PROJECT')
  {
    try
    {
      return new DBWrapper($debug, $appname);
    }
    catch ( DBWrapperException $ex )
    {
      throw $ex;
    }
  }

  /**
   * validDateTime()
   * Check if valid datetime string
   * @param string $property name of property (a.k.a column)
   * @param string $date
   * @param string $format (optional!) defaults to $this->datetimeformat ('Y-m-d H:i:s' upon creation of object)
   * @throws ORMException
   */
  private function validDateTime(string $property, $date, string $format = null)
  {
    if ( ! isset($format) ) $format = $this->entity[$property]['datetimeformat'] ?? $this->datetimeformat;

    $d = DateTime::createFromFormat($format, $date);

    if ( ( $d === false ) or ( $d->format($format) != $date ) ) throw new ORMException("Invalid datetime value for property '$property'. It requires a '$format' format!", 14);
  }

  /**
   * validDataType()
   * Check if value is of right datatype.
   * @param string $property name of property (a.k.a column)
   * @param mixed $value
   * @throws ORMException
   */
  private function validDataType(string $property, $value)
  {
    // what type SHOULD the property be?
    $prop_datatype = $this->entity[$property]['type'][0];

    // validate if datetime
    if ( $prop_datatype === 'datetime' )
    {
      // if user passed value of null, but value ISN'T required, or there's a default value set, assume valid datatype and return!
      if ( ( $value === null ) and ( ( $this->entity[$property]['required'] === false ) or ( isset($this->entity[$property]['valueIfNull']) ) ) ) return;

      // else, let it try validate through...
      try
      {
        $this->validDateTime($property, $value);
      }
      catch ( Exception $ex )
      {
        throw $ex;
      }
    }
    // validate if binary format
    elseif ( $prop_datatype === 'binary' )
    {
      // @todo validDataType for binary 
    }
    // any other datatype, use PHP gettype() to check
    else
    {
      // if user passed value of null, but value ISN'T required, or there's a default value set, assume valid datatype and return!
      if ( ( $value === null ) and ( ( $this->entity[$property]['required'] === false ) or ( isset($this->entity[$property]['valueIfNull']) ) ) ) return;

      // else, let it try validate through...
      //if ( gettype($value) != $prop_datatype )
      if ( ! preg_match("/$prop_datatype/", gettype($value)) ) // using preg_match to allow valid $prop_datatype to be many choices i.e. "integer|string"
      {
        // @todo: if $prop_datatype is 'integer', but $value is 'string', still allow it to go through, but still confirming the string value is indeed an integer possible value ? Not sure...
        throw new ORMException("Invalid value datatype for property '$property'. It should be of type '$prop_datatype'.", 15);
      }
    }
  }

  /**
   * getEntityKey()
   * Retrieve entity keyname from a given DB column name
   * @param string $col database column name
   * @return string entity keyname holding the given column (friendly column name ;))
   * @throws ORMException
   */
  private function getEntityKey(string $col)
  {
    foreach ( $this->entity as $key => $value )
    {
      $key_colname = $value['column'];
      if ( $key_colname === $col ) return $key;
    }

    throw new ORMException("Something's wrong! There is no property who's column value is '$col'.", 16);
  }
}

/**
 * ORMException
 * Custom exception class
 * 
 * Error Codes:
 * 
 * Code 1  = Property isn't defined in the entity!
 * Code 2  = Property is readonly!
 * Code 3  = Tablename, primary key or member properties not properly set!
 * Code 4  = Specified primary key isn't set in the entity!
 * Code 5  = Wrong parameter type for filter!
 * Code 6  = More than 1 row returned! Cannot initialize the object with many rows!
 * Code 7  = Save aborted: the object was previously deleted from DB!
 * Code 8  = Missing value for property!
 * Code 9  = Rows might have been saved in the DB, but DB returned warnings! (only if using MySQL for now!)
 * Code 10 = Wrong number of rows updated in the DB after save()!
 * Code 11 = Wrong number of rows removed in the DB after delete()!
 * Code 12 = Can't delete an object that hasn't yet been saved in the database!
 * Code 13 = Can't delete an object that doesn't have a value set in the primary key!
 * Code 14 = Invalid datetime value for property
 * Code 15 = Invalid value datatype for property
 * Code 16 = There is no property with the given DB column name!
 */
class ORMException extends Exception
{
  public $dbcode;
  public $dbwarnings;

  /**
   * Construct a custom exception
   * @param string $message Error message
   * @param integer $code ORMException error code
   * @param integer $dbcode DB Code if applicable
   * @param array $dbwarnings array holding DB warning objects (applicable only if using MySQL for now). i.e.:
   *        <pre>
   *        stdClass Object
   *        {
   *          [Level] => Warning
   *          [Code] => 1264
   *          [Message] => Out of range value for column 'qty' at row 1 
   *        }
   *        </pre>
   */
  public function __construct($message, $code = 0, $dbcode = null, $dbwarnings = null)
  {
    parent::__construct($message, $code);
    $this->dbcode = $dbcode;
    $this->dbwarnings = $dbwarnings;
  }
}
?>
