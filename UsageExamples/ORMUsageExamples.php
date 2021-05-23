<?php

/******************************************************************************
 * ORM Usage Demo
 * 
 * Create or load an object representing a database table (i.e. User, Contract, etc.)
 * 
 * Object Methods:
 * 
 * 1. Object's constructor  : either creates a new object with default values, or loads an object from the database.
 *                          
 * 2. object->save()        : save the loaded object into the database.
 * 3. object->delete()      : delete the loaded object from the database.
 * 4. object->isDirty()     : is the object new, or as been modified?
 * 5. object->getEntity()   : retrieve the entity object that holds the data (for debug purposes!)
 * 6. object->isNew()       : is the object in NEW state?
 * 7. object->isLoaded()    : is the object loaded from or saved into the DB?
 * 8. object->isDeleted()   : is the object in a DELETED (from DB) state?
 * 9. object->propertyName  : set or get propertyName's column value!
 * 
 * Static Methods:
 * 
 * 1. Object::find()        : find and load objects from the database.
 * 2. Object::deleteQuery() : delete objects from the database.
 * 3. Object::getDBWrapper(): retrieve a DBWrapper object
 * 
 * Trick: the constructor(), save(), find() and deleteQuery() all accept a $forceCloseDB and $dbwrapper reference parameter!
 * This allows you to either force closing the DB connection after each query, and it'll also allow you to use the 
 * same DBWrapper / PDO Connection throughout your page if desired.
 * 
 * i.e. say you manipulate a User object, then load a Contract object, why have Contract use a different DBWrapper?
 * Initialize a DBWRapper with $mydb = Object::getDBWrapper() and pass it along to all objects!
 * 
 ******************************************************************************/
use CorbeauPerdu\Database\Exceptions\DBWrapperException;
use CorbeauPerdu\ORM\ORMException;

require_once ( 'User.php' ); // load ORM Object class

/***********************************
 * Creating and saving a User
 ***********************************/
try
{
  $user = new User();
  $user->username = 'jdoe';
  $user->password = 'some encrypted password';
  $user->email = 'jdoe@mail.com';
  $user->age = 35;

  $user->save();

  // if primary key is an auto-increment integer, only after saving will you be able to retrieve the newly inserted user's ID
  print( "New user created with id: $user->id" );
}

// could simply catch all errors and print message:
catch ( Exception $ex )
{
  print( "Fatal error!" . PHP_EOL . $ex->getMessage() );
}

// OR, filter out errors:
catch ( Exception $ex )
{
  // as an example, check if MySQL returned an integrity constraint / duplicate entry error code while inserting!
  if ( ( $ex instanceof DBWrapperException ) and ( $ex->getDbCode() == 1062 ) )
  {
    print( "Username '$user->username' is already taken!" );
  }

  // will get here if any other ORM or Database error occured!
  else
  {
    // as another example, see if MySQL returned warnings (error code 9 from ORMException!)
    if ( ( $ex instanceof ORMException ) and ( $ex->getCode() == 9 ) )
    {
      foreach ( $ex->dbwarnings as $warning )
      {
        // if value out of range warning example (i.e. insert number higher than 127 in a tinyint field)
        if ( $warning->Code == 1264 )
        {
          print( 'Failed input: ' . $warning->Message . PHP_EOL );
        }
        // any other warnings
        else
        {
          // do as you will with the other warnings!
        }
      }
    }

    // any other type of errors, besides warnings
    else
    {
      print( "Fatal error! " . PHP_EOL . $ex->getMessage() );
    }
  }
}

/***********************************
 * Loading and modifying a User
 ***********************************/
try
{
  $userid = 37;
  $user = new User($userid); // lookup value from primary key column

  // OR match column => value pairs
  //$user = new User(['username' => 'jdoe']);

  // OR special cases where a specific SQL is required in the WHERE clause! MUCH safer to provide a $paramValuesArg along!
  //$user = new User("is_admin=1 AND country=(SELECT id FROM countries WHERE name=:param)", 'ca');

  $user->email = 'my@email.com';
  $user->password = 'changing password...';
  $user->save();

  // and if at some point you wish to delete the loaded object from the database:
  $user->delete();

  print( 'User was loaded, modified, and then deleted!' );
}
catch ( Exception $ex )
{
  // as another example, check if we have a No Data Found exception!
  if ( ( $ex instanceof DBWrapperException ) and ( $ex->getCode() == 7 ) )
  {
    print( 'Unable to load object: user not found in the database!' );
  }
  else
  {
    print( "Fatal error! " . PHP_EOL . $ex->getMessage() );
  }
}

/***********************************
 * Find 1 or many User objects
 ***********************************/
try
{
  // the $filter arg passed in find() is the same as in the constructor!
  $users = User::find([  'isActive' => true, 'country' => 'ca' ]); // returns either a single object entity, or an array of objects

  // OR, load all Users from database
  $users = User::find(true);

  if ( is_object($users) )
  {
    // do what ever with $users as you would in the above above examples
  }
  else
  {
    // loop the array and do what ever with the objects as in the above examples
  }

  print( 'Found, loaded and used ' . ( is_object($users) ? '1' : sizeof($users) ) . ' User objects!' );
}
catch ( Exception $ex )
{
  if ( ( $ex instanceof DBWrapperException ) and ( $ex->getCode() == 7 ) ) // DBWrapperNoDataFoundException !
  {
    print( 'No data found in the database!' );
  }
  else
  {
    print( "Fatal error! " . PHP_EOL . $ex->getMessage() );
  }
}

/***********************************
 * Delete User objects from database
 ***********************************/
try
{
  // the $filter arg passed in deleteQuery() is the same as in the constructor!
  $success = User::deleteQuery([  'country' => 'us' ]); // heck! let's delete all Americans!

  echo "Sucessfully deleted $success users!";
}
catch ( Exception $ex )
{
  print( "Fatal error! " . $ex->getMessage() );
}

?>
