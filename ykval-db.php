<?php


/**
 * Class for managing database connection
 *
 * LICENSE:
 *
 * Copyright (c) 2009  Yubico.  All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * o Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 * o Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in the
 *   documentation and/or other materials provided with the distribution.
 * o The names of the authors may not be used to endorse or promote
 *   products derived from this software without specific prior written
 *   permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author      Olov Danielson <olov.danielson@gmail.com>
 * @copyright   2009 Yubico
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 * @link        http://www.yubico.com/
 * @link        http://code.google.com/p/yubikey-timedelta-server-php/
 */

require_once('ykval-log.php');

class Db
{


  /**
   * Constructor
   *
   * @param string $host Database host
   * @param string $user Database user
   * @param string $pwd  Database password
   * @param string $name Database table name
   * @return void 
   *
   */
  public function __construct($db_dsn, $db_username, $db_password, $dp_options)
  {
    $this->db_dsn=$db_dsn;
    $this->db_username=$db_username;
    $this->db_password=$db_password;
    $this->db_options=$db_options;

    $this->myLog=new Log('ykval-db');
  }
  /**
   * function to convert Db timestamps to unixtime(s)
   *
   * @param string $updated Database timestamp 
   * @return int Timestamp in unixtime format
   *
   */
  public function timestampToTime($updated)
  {
    $stamp=strptime($updated, '%F %H:%M:%S');
    return mktime($stamp[tm_hour], $stamp[tm_min], $stamp[tm_sec], $stamp[tm_mon]+1, $stamp[tm_mday], $stamp[tm_year]);

  }

  /**
   * function to compute delta (s) between 2 Db timestamps
   *
   * @param string $first Database timestamp 1 
   * @param string $second Database timestamp 2
   * @return int Deltatime (s)
   *
   */
  public function timestampDeltaTime($first, $second)
  {
    return Db::timestampToTime($second) - Db::timestampToTime($first);
  }

  /**
   * function to disconnect from database
   *
   * @return boolean True on success, otherwise false.
   *
   */
  public function disconnect()
  {
    $this->dbh=NULL;
  }
  
  /**
   * function to check if database is connected
   *
   * @return boolean True if connected, otherwise false.
   *
   */
  public function isConnected()
  {
    if ($this->dbh!=NULL) return True;
    else return False;
  }
  /**
   * function to connect to database defined in config.php
   *
   * @return boolean True on success, otherwise false.
   *
   */
  public function connect(){

    try {
      $this->dbh = new PDO($this->db_dsn, $this->db_username, $this->db_password, $this->db_options);
    } catch (PDOException $e) {
      $this->myLog->log(LOG_CRIT, "Database error: " . $e->getMessage());
      $this->dbh=Null;
      return false;
    }
    return true;
  }

  private function query($query, $returnresult=false) {
    if($this->dbh) {
      $this->result = $this->dbh->query($query);
      if (! $this->result){
	$this->myLog->log(LOG_INFO, 'Database error: ' . print_r($this->dbh->errorInfo(), true));
	$this->myLog->log(LOG_INFO, 'Query was: ' . $query);
	return false;
      }
      if ($returnresult) return $this->result;
      else return true;
    } else {
      $this->myLog->log(LOG_CRIT, 'No database connection');
      return false;
    }
  }

  public function truncateTable($name)
  {
    $this->query("TRUNCATE TABLE " . $name);
  }

  /**
   * function to update row in database by a where condition
   *
   * @param string $table Database table to update row in
   * @param int $id Id on row to update
   * @param array $values Array with key=>values to update
   * @return boolean True on success, otherwise false.
   *
   */
  public function updateBy($table, $k, $v, $values)
  {
    
    foreach ($values as $key=>$value){
      if ($value != null) $query .= ' ' . $key . "='" . $value . "',";
      else $query .= ' ' . $key . '=NULL,';
    }
    if (! $query) {
      log("no values to set in query. Not updating DB");
      return true;
    }

    $query = rtrim($query, ",") . " WHERE " . $k . " = '" . $v . "'";
    // Insert UPDATE statement at beginning
    $query = "UPDATE " . $table . " SET " . $query; 
    
    return $this->query($query, false);
  }


  /**
   * function to update row in database
   *
   * @param string $table Database table to update row in
   * @param int $id Id on row to update
   * @param array $values Array with key=>values to update
   * @return boolean True on success, otherwise false.
   *
   */
  public function update($table, $id, $values)
  {
    return $this->updateBy($table, 'id', $id, $values);
  }

  /**
   * function to update row in database based on a condition
   *
   * @param string $table Database table to update row in
   * @param string $k Column to select row on
   * @param string $v Value to select row on
   * @param array $values Array with key=>values to update
   * @param string $condition conditional statement
   * @return boolean True on success, otherwise false.
   *
   */
  public function conditionalUpdateBy($table, $k, $v, $values, $condition)
  {
    
    foreach ($values as $key=>$value){
      $query = $query . " " . $key . "='" . $value . "',";
    }
    if (! $query) {
      log("no values to set in query. Not updating DB");
      return true;
    }

    $query = rtrim($query, ",") . " WHERE " . $k . " = '" . $v . "' and " . $condition;
    // Insert UPDATE statement at beginning
    $query = "UPDATE " . $table . " SET " . $query; 

    $this->myLog->log(LOG_INFO, "query is " . $query);
    return $this->query($query, false);
  }
    

  /**
   * Function to update row in database based on a condition.
   * An ID value is passed to select the appropriate column
   *
   * @param string $table Database table to update row in
   * @param int $id Id on row to update
   * @param array $values Array with key=>values to update
   * @param string $condition conditional statement
   * @return boolean True on success, otherwise false.
   *
   */
  public function conditionalUpdate($table, $id, $values, $condition)
  {
    return $this->conditionalUpdateBy($table, 'id', $id, $values, $condition);
  }

  /**
   * function to insert new row in database
   *
   * @param string $table Database table to update row in
   * @param array $values Array with key=>values to update
   * @return boolean True on success, otherwise false.
   *
   */
  public function save($table, $values)
  {
    $query= 'INSERT INTO ' . $table . " (";
    foreach ($values as $key=>$value){
      if ($value != null) $query = $query . $key . ",";
    }
    $query = rtrim($query, ",") . ') VALUES (';
    foreach ($values as $key=>$value){
      if ($value != null) $query = $query . "'" . $value . "',";
    }
    $query = rtrim($query, ",");
    $query = $query . ")";
    return $this->query($query, false);
  }
  /**
   * helper function to collect last row[s] in database
   *
   * @param string $table Database table to update row in
   * @param int $nr Number of rows to collect. NULL=>inifinity. DEFAULT=1.
   * @return mixed Array with values from Db row or 2d-array with multiple rows
or false on failure.
   *
   */
  public function last($table, $nr=1)
  {
    return Db::findBy($table, null, null, $nr, 1);
  }

  /**
   * main function used to get rows from Db table. 
   *
   * @param string $table Database table to update row in
   * @param string $key Column to select rows by
   * @param string $value Value to select rows by
   * @param int $nr Number of rows to collect. NULL=>inifinity. Default=NULL.
   * @param int $rev rev=1 indicates order should be reversed. Default=NULL.
   * @return mixed Array with values from Db row or 2d-array with multiple rows
   *
   */
  public function findBy($table, $key, $value, $nr=null, $rev=null)
  {
    return $this->findByMultiple($table, array($key=>$value), $nr, $rev);
  }

  /**
   * main function used to get rows by multiple key=>value pairs from Db table. 
   *
   * @param string $table Database table to update row in
   * @param array $where Array with column=>values to select rows by
   * @param int $nr Number of rows to collect. NULL=>inifinity. Default=NULL.
   * @param int $rev rev=1 indicates order should be reversed. Default=NULL.
   * @param string distinct Select rows with distinct columns, Default=NULL
   * @return mixed Array with values from Db row or 2d-array with multiple rows
   *
   */
  public function findByMultiple($table, $where, $nr=null, $rev=null, $distinct=null)
  {
    $query="SELECT";
    if ($distinct!=null) {
      $query.= " DISTINCT " . $distinct;
    } else {
      $query.= " *";
    }
    $query.= " FROM " . $table;
    if ($where!=null){ 
      foreach ($where as $key=>$value) {
	if ($key!= Null) {
	  if ($value != Null) $match.= " ". $key . " = '" . $value . "' and";
	  else $match.= " ". $key . " is NULL and";
	}
      }
      if ($match!=null) $query .= " WHERE" . $match;
      $query=rtrim($query, "and");
      $query=rtrim($query);
    }
    if ($rev==1) $query.= " ORDER BY id DESC";
    if ($nr!=null) $query.= " LIMIT " . $nr;

    $this->myLog->log(LOG_NOTICE, 'query is ' . $query);
    $result = $this->query($query, true);
    if (!$result) return false;
   
    if ($nr==1) {
      $row = $result->fetch(PDO::FETCH_ASSOC);
      return $row;
    } 
    else {
      $collection=array();
      while($row = $result->fetch(PDO::FETCH_ASSOC)){
	$collection[]=$row;
      }
      return $collection;
    }

  }

  /**
   * main function used to delete rows by multiple key=>value pairs from Db table. 
   *
   * @param string $table Database table to delete row in
   * @param array $where Array with column=>values to select rows by
   * @param int $nr Number of rows to collect. NULL=>inifinity. Default=NULL.
   * @param int $rev rev=1 indicates order should be reversed. Default=NULL.
   * @param string distinct Select rows with distinct columns, Default=NULL
   * @return boolean True on success, otherwise false.
   *
   */
  public function deleteByMultiple($table, $where, $nr=null, $rev=null)
  {
    $query="DELETE";
    $query.= " FROM " . $table;
    if ($where!=null){ 
      $query.= " WHERE";
      foreach ($where as $key=>$value) {
	$query.= " ". $key . " = '" . $value . "' and";
      }
      $query=rtrim($query, "and");
      $query=rtrim($query);
    }
    if ($rev==1) $query.= " ORDER BY id DESC";
    if ($nr!=null) $query.= " LIMIT " . $nr;
    $this->myLog->log(LOG_INFO, "delete query is " . $query);
    return $this->query($query, false);
  }


  /**
   * Function to do a custom query on database connection 
   *
   * @param string $query Database query
   * @return mixed 
   *
   */
  public function customQuery($query)
  {
    return $this->query($query, true);
  }

  /**
   * Function to do a custom query on database connection 
   *
   * @return int number of rows affected by last statement or 0 if database connection is not functional.
   *
   */
  public function rowCount()
  {
    if($this->result) return $this->result->rowCount();
    else return 0;
  }

  /**
   * helper function used to get rows from Db table in reversed order. 
   * defaults to obtaining 1 row. 
   *
   * @param string $table Database table to update row in
   * @param string $key Column to select rows by
   * @param string $value Value to select rows by
   * @param int $nr Number of rows to collect. NULL=>inifinity. Default=1.
   * @return mixed Array with values from Db row or 2d-array with multiple rows or false on failure.
   *
   */
  public function lastBy($table, $key, $value, $nr=1)
  {
    return Db::findBy($table, $key, $value, $nr, 1);
  }

  /**
   * helper function used to get rows from Db table in standard order. 
   * defaults to obtaining 1 row. 
   *
   * @param string $table Database table to update row in
   * @param string $key Column to select rows by
   * @param string $value Value to select rows by
   * @param int $nr Number of rows to collect. NULL=>inifinity. Default=1.
   * @return mixed Array with values from Db row or 2d-array with multiple rows or false on failure.
   *
   */
  public function firstBy($table, $key, $value, $nr=1)
  {
    return Db::findBy($table, $key, $value, $nr);
  }
  
}


?>