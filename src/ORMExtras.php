<?php

namespace CorbeauPerdu\ORM;

use CorbeauPerdu\Database\Exceptions\DBWrapperException;
use CorbeauPerdu\Database\Exceptions\DBWrapperNoDataFoundException;
use Exception;

/**
 * ORM Extras.
 * Common functions used between objects required to be defined in the Object class (i.e. User) and not in the ORMBase class:
 * These methods require to do a new self(), which doesn't work if called from ORMBase, thus the requirement to put theses calls with the Object instead!
 * 
 * Last Modified : 2020/06/16 by PRoy - First release
*                  2020/06/21 by PRoy - Added $multiTransactions to deleteQuery() to run a single transaction FOR EVERY ROW, or to run a single transaction for ALL ROWS!
 * 
 * @author Patrick Roy (ravenlost2@gmail.com)
 * @version 1.1
 *
 */
trait ORMExtras
{

  /**
   * find()
   * Retrieve objects from database!
   *
   * WARNING about $order and $limit parameters: these are straight forward SQL !
   * NOT using named parameters here to protect against SQL Injections! You need to sanitize them!
   * @todo Could eventually use $paramValuesArg to sanitize $order and $limit ... ?
   *
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
   * @param bool $forceCloseDB (optional!) force closing database after query (results un unsetting DBWrapper / PDO connection). Default is false.
   * @param object $db (optional!) DBWrapper instance: will create one if necessary
   *
   * @return object|array either a single entity object or an array of objects
   * @throws DBWrapperException
   * @throws ORMException
   */
  public static function find($filter, $paramValuesArg = null, string $order = null, string $limit = null, $forceCloseDB = false, &$db = null)
  {
    $forceCloseDB = ( $forceCloseDB ) ?: false;

    try
    {
      // temporarily create an empty object to get object's attributes and load the objects with the filters
      $entity = new self();
      $entities = $entity->load($filter, $paramValuesArg, $order, $limit, true, $forceCloseDB, $db);
      unset($entity);

      return $entities;
    }
    catch ( DBWrapperNoDataFoundException $ex )
    {
      throw $ex; // or return null ??
    }
    catch ( Exception $ex )
    {
      throw $ex;
    }
  }

  /**
   * deleteQuery()
   * Run a delete request on table, based on given $filter.
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
   * @return integer successful deletes
   * @throws DBWrapperException
   * @throws ORMException
   */
  public static function deleteQuery($filter, $paramValuesArg = null, $multiTransactions = false, $forceCloseDB = false, &$db = null)
  {
    $forceCloseDB = ( $forceCloseDB ) ?: false;

    try
    {
      // temporarily create an empty object to get object's attributes and run the query with the filters...
      $entity = new self();
      $success = $entity->deleteQueryRun($filter, $paramValuesArg, $multiTransactions, $forceCloseDB, $db);
      unset($entity);

      return $success;
    }
    catch ( Exception $ex )
    {
      throw $ex;
    }
  }
}
?>