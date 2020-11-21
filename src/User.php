<?php

require_once ( 'ORMBase.php' );
require_once ( 'ORMExtras.php' );

use CorbeauPerdu\ORM\ORMBase;
use CorbeauPerdu\ORM\ORMException;
use CorbeauPerdu\ORM\ORMExtras;
use CorbeauPerdu\Database\Exceptions\DBWrapperException;
use CorbeauPerdu\Database\Exceptions\DBWrapperNoDataFoundException;
use Exception;

/**
 * ORM Object
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
 * Create or load 'User' objects from database.
 *
 * In order to create a new Object entity (i.e. User, Contract, Producer, etc),
 * the following methods MUST be called (in order!) from this constructor!
 *
 * 1. $this->setTablename()  // sets the database tablename
 * 2. $this->addMember()     // sets the column's properties (column name, type, default value, etc.)
 * 3. $this->setPrimaryKey() // sets the table's primary key column name (use the friendly name given in addMember!)
 * 4. $this->setUseUpsert()  // force an UPDATE if an INSERT fails due to DUPLICATE KEY? In other words, does an UPSERT (Optional! Defaults to FALSE).
 * 5. parent::__construct()  // validates object is ready; also sets DBWrapper's DEBUG mode and its APPNAME for log outputs
 *
 * Last Modified : 2020/06/09 by PRoy - First release
 *                 2020/06/11 by PRoy - Added setUseUpsert() to do an UPSERT instead of INSERT: inserts row into database table (if it doesn't already exist) or updates it (if it does).
 *                                      (essentially, adds 'ON DUPLICATE KEY UPDATE' to the SQL INSERT...)
 *                 2020/06/16 by PRoy - Removed find() and deleteQuery() to put them in a commonly used ORMExtras trait instead.
 *
 * @author Patrick Roy (ravenlost2@gmail.com)
 * @version 1.1.1
 * @uses \CorbeauPerdu\ORM\ORMBase
 */
class User extends ORMBase
{
  // load extra common functions
  use ORMExtras;

  /**
   * Construct or load a new 'User' object
   *
   * Note that $forceCloseDB and $db are only used if you provide a $filter!
   *
   * @param mixed $filter (optional!) if null, new object is created, else provide any of the following filter options to load object from database:
   * <table>
   * <tr>
   *   <td width="35%">1. integer | single-word string</td><td>5, 'jdoe@email.com', 'username'</td>
   * </tr>
   * <tr>
   *   <td width="35%">2. array</td><td>[ 'username' => 'jdoe' ]</td>
   * </tr>
   * <tr>
   *   <td width="35%">3. string</td><td>"email LIKE '%gmail.com'"</td>
   * </tr>
   * <tr>
   *   <td width="35%">4. boolean</td><td>TRUE</td>
   * </tr>
   * <tr>
   *   <td colspan="2">
   *   <p><i>Option 1: will load object using the table's primary key column.</i></p>
   *   <p><i>Option 2: will load object from table based on defined column values. The 'column' should be the friendly name of column (i.e. entity keyname!).</i></p>
   *   <p><i>Option 3: you provide a string with content of WHERE clause to load object from the table: use the actual DB column names!</i></p>
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
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   *
   * @throws DBWrapperException
   * @throws ORMException
   */
   public function __construct($filter = null, $paramValuesArg = null, $forceCloseDB = false, &$db = null)
   {
     $forceCloseDB = ( $forceCloseDB ) ?: false;

     try
     {
       // set tablename
       $this->setTablename('tbl_users');

       // set table's members (columns) with properties:
       //    - $key                  : friendly key name to represent the column name (i.e. 'username' instead of 'fk_tbl_users_username'...): the magic __getters and __setters will refer to these names!
       //    - $column               : table column name
       //    - $type                 : column's datatype: MUST use the 'COLTYPE_*' constants!
       //    - $readonly             : is the column's value readonly? [default = false]
       //    - $required             : force user to define column's value in the entity? [default = false]
       //    - $value                : column's value (default if new, otherwise loaded value from DB) [default = null]
       //    - $valueIfNull          : value to push to DB if $value is null [default = null]
       //    - $useDBDefaultWhenNull : if both $value and $valueIfNull are null, use DB DEFAULT value? [default = false]
       //    - $datetimeformat       : if datatype is COLTYPE_DATETIME, what should the valid datetime format be? [default = 'Y-m-d H:i:s']

       $this->addMember('id', 'id', self::COLTYPE_INT, true);
       $this->addMember('isAdmin', 'is_admin', self::COLTYPE_TINYINT, false, false, (int) 0, null, true);
       $this->addMember('isActive', 'is_active', self::COLTYPE_TINYINT, false, false, (int) 1, null, true);
       $this->addMember('username', 'username', self::COLTYPE_VARCHAR, false, true);
       $this->addMember('firstName', 'first_name', self::COLTYPE_VARCHAR);
       $this->addMember('lastName', 'last_name', self::COLTYPE_VARCHAR);
       $this->addMember('email', 'email', self::COLTYPE_VARCHAR, false, true);
       $this->addMember('dob', 'date_of_birth', self::COLTYPE_DATETIME, false, false, null, null, false, 'Y-m-d');
       $this->addMember('password', 'password', self::COLTYPE_VARCHAR, false, true);
       $this->addMember('dateCreated', 'date_created', self::COLTYPE_DATETIME, true, false, null, null, true);   // just use DB's DEFAULT which is CURRENT_TIMESTAMP
       $this->addMember('dateModified', 'date_modified', self::COLTYPE_DATETIME, false, false, null, null, true);

       // set primary key only after adding members!
       $this->setPrimaryKey('id');

       // when object is new, if insert fails due to duplicate key, force an update instead?
       $this->setUseUpsert(false);

       // call parent's constructor who'll validate all is ready to go before loading anything!
       parent::__construct(true, 'APP_NAME');

       // finally, load the entity if we have arguments, else will just create the entity with default values passed in the addMember()
       if ( isset($filter) )
       {
         $this->load($filter, $paramValuesArg, null, null, false, $forceCloseDB, $db);
       }
       else
       {
         $this->setIsNew(true);
       }
     }
     catch ( DBWrapperNoDataFoundException $ex )
     {
       throw $ex;
     }
     catch ( Exception $ex )
     {
       throw $ex;
     }
   }
}
