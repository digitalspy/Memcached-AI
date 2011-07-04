<?php
// +----------------------------------------------------------------------+
// | Copyright (c) 2011 Digital Spy Ltd (http://www.digitalspy.co.uk)     |
// +----------------------------------------------------------------------+
// | This library is free software; you can redistribute it and/or        |
// | modify it under the terms of the GNU Lesser General Public           |
// | License as published by the Free Software Foundation; either         |
// | version 2.1 of the License, or (at your option) any later version.   |
// |                                                                      |
// | This library is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU    |
// | Lesser General Public License for more details.                      |
// |                                                                      |
// | You should have received a copy of the GNU Lesser General Public     |
// | License along with this library; if not, write to the Free Software  |
// | Foundation, Inc., 59 Temple Place, Suite 330,Boston,MA 02111-1307 USA|
// +----------------------------------------------------------------------+
// | Author: Jason Margolin (Digital Spy)                                 |
// +----------------------------------------------------------------------+
//

/**
 * 
 * A system to automatically manage and expire memcached MySQL queries
 *
 * @name Memcached AI
 * @author Jason Margolin <engineering.software@digitalspy.co.uk>
 * @version 0.1
 */

class MemcachedAI {
	
	/*
	 * Memcached instance
	 */
	private $memcached;
	
	/*
	 * Memcached expire time
	 */
	private $memcachedExpire;
	
	/*
	 * MySQL link identifier
	 */
	private $conn;
	
	/*
	 * Class constructor
	 * @param $db_host the database host or false if already connected
	 * @param $db_username the database username
	 * @param $db_password the database password
	 * @param $db_name the database name to select
	 * @param $memcachedExpire the memcached expiration time for keys
	 */
	public function __construct($db_host, $db_username, $db_password, $db_name, $memcachedExpire = 0) {
		
		// Create new Memcached instance
		if (class_exists('Memcached')) {
			$this->memcached = new Memcached();
		}
		elseif (class_exists('Memcache')) {
			$this->memcached = new Memcache();
		}
		else {
			trigger_error('Could not load an instance of Memcached', E_USER_ERROR);
		}
		
		$this->memcachedExpire = $memcachedExpire;
		
		// Create new MySQL instance
		if (!extension_loaded('mysql')) {
			trigger_error('Could not load an instance of MySQL', E_USER_ERROR);
		}
		else {
			$this->conn = mysql_connect($db_host, $db_username, $db_password) or die(mysql_error());
			mysql_select_db($db_name, $this->conn) or die(mysql_error());
		}
	}
	
	/*
	 * Memcached - Add a server to the server pool
	 * @param $host the hostname of the memcache server
	 * @param $port the port on which memcache is running
	 * @param $weight the weight of the server relative to the total weight of all the servers in the pool
	 */
	public function memcachedAddServer($host, $port, $weight = 0) {
		if ($this->memcached instanceof Memcached) {
			return $this->memcached->addServer($host, $port, $weight);
		}
		else {
			if (method_exists($this->memcached, 'addServer')) {
				return $this->memcached->addServer($host, $port, true, $weight);
			}
			else {
				return $this->memcached->connect($host, $port);
			}
		}
	}
	
	/*
	 * Memcached - Retrieve an item
	 * @param $key the key of the item to retrieve
	 */
	private function memcachedGet($key) {
		return $this->memcached->get($key);
	}
	
	/*
	 * Memcached - Store an item
	 * @param $key the key under which to store the value
	 * @param $result the value to store
	 */
	private function memcachedSet($key, $value) {
		if ($this->memcached instanceof Memcached) {
			return $this->memcached->set($key, $value, $this->memcachedExpire);
		}
		else {
			return $this->memcached->set($key, $value, 0, $this->memcachedExpire);
		}
	}
	
	/*
	 * Memcached - Delete an item
	 * @param $key the key to be deleted
	 */
	private function memcachedDelete($key) {
		return $this->memcached->delete($key);
	}
	
	/*
	 * Perform an SQL query on the database and cache the result if necessary
	 * @param $sql the SQL query
	 * @param $memCacheID the memcached key to store or false
	 */
	private function databaseQuery($sql, $memCacheID = false) {
		$results = array();
		$query = mysql_query($sql, $this->conn);
		while ($row = mysql_fetch_array($query, MYSQL_ASSOC)) {
			$results[] = $row;
		}
		if ($memCacheID !== false) $this->memcachedSet($memCacheID, $results);
		return $results;
	}
	
	/*
	 * Perform an SQL query on the database that does not return a row
	 * @param $databaseInsert return the ID generated in the last query
	 */
	private function databaseWrite($sql, $databaseInsert = false) {
		if (!mysql_query($sql, $this->conn)) {
			return false;
		}
		else {
			if ($databaseInsert) {
				return mysql_insert_id($this->conn);
			}
			return true;
		}
	}
	
	/*
	 * Escapes special characters in a string for use in an SQL statement
	 * @param $value the value
	 */
	private function databaseEscape($value) {
		return mysql_real_escape_string($value, $this->conn);
	}
	
	/*
	 * Query a table for a specific record or records in a standardised way for memcached and expiring
	 * @param $table the table to query for the data
	 * @param $fields an array of key/value
	 * @param $order an array of key/value
	 * @param $limit a number or string
	 */
	public function selectTable($table, $fields, $order = false, $limit = false) {
		
		// Create select query
		$sql = "SELECT * FROM " . $table . " WHERE";
		foreach ($fields as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "' AND";
		}
		
		$sql = substr($sql, 0, -4);
		
		if ($order !== false && !empty($order)) {
			$sql .= " ORDER BY";
			foreach ($order as $key => $value) {
				$sql .= " " . $key . " " . strtoupper($value) . ",";
			}
			$sql = substr($sql, 0, -1);
		}
		
		if ($limit !== false) {
			$sql .= " LIMIT " . $limit;
		}
		
		$memcacheID = "md5_" . md5($sql);
		
		// Return cached results
		if (($results = $this->memcachedGet($memcacheID)) !== false) {
			return $results;
		}
		
		// Cache results
		$results = $this->databaseQuery($sql, $memcacheID);
		
		// Maintain field indexes
		foreach ($results as $row) {
			foreach ($row as $key => $value) {
				$memcacheKey = $table . "_" . $key . "_" . $value;
				$memcacheKey = "index_" . md5($memcacheKey);
				
				if ($index = $this->memcachedGet($memcacheKey)) {
					if (!in_array($memcacheID, $index)) {
						$index[] = $memcacheID;
						$this->memcachedSet($memcacheKey, $index);
					}
				}
				else {
					$index = array($memcacheID);
					$this->memcachedSet($memcacheKey, $index);
				}
			}
		}
		
		return $results;
	}
	
	/*
	 * Update a table for a specific record or records in a standardised way for memcached and expiring
	 * @param $table the table to query for the data
	 * @param $fields an array of key/value
	 * @param $where an array of key/value
	 */
	public function updateTable($table, $fields, $where) {
		
		$field_keys = array_keys($fields);
		
		// Create select query to retrieve current data
		$sql = "SELECT * FROM " . $table . " WHERE";
		foreach ($where as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "' AND";
		}
		
		$sql = substr($sql, 0, -4);
		$results = $this->databaseQuery($sql);

		// Expire old field indexes
		foreach ($results as $row) {
			foreach ($row as $key => $value) {
				if (in_array($key, $field_keys) && $fields[$key] != $value) {
					$memcacheKey = $table . "_" . $key . "_" . $value;
					$memcacheKey = "index_" . md5($memcacheKey);
					
					if ($index = $this->memcachedGet($memcacheKey)) {
						foreach ($index as $memcacheID) {
							$this->memcachedDelete($memcacheID);
						}
					}
				}
			}
		}
		
		// Expire new field indexes
		foreach ($fields as $key => $value) {
			$memcacheKey = $table . "_" . $key . "_" . $value;
			$memcacheKey = "index_" . md5($memcacheKey);
			
			if ($index = $this->memcachedGet($memcacheKey)) {
				foreach ($index as $memcacheID) {
					$this->memcachedDelete($memcacheID);
				}
			}
		}
		
		// Create update query
		$sql = "UPDATE " . $table . " SET";
		foreach ($fields as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "',";
		}
		$sql = substr($sql, 0, -1);
		$sql .= " WHERE";
		foreach ($where as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "' AND";
		}
		$sql = substr($sql, 0, -4);
		
		return $this->databaseWrite($sql);
	}
	
	/*
	 * Insert a specific record into a table in a standardised way for memcached and expiring
	 * @param $table the table to insert the data
	 * @param $fields an array of key/value
	 * @param $databaseInsert return the id of last insert is true otherwise returns result as a boolean
	 */
	public function insertTable($table, $fields, $databaseInsert = false) {
		
		// Expire query results that may contain this record
		foreach ($fields as $key => $value) {
			$memcacheKey = $table . "_" . $key . "_" . $value;
			$memcacheKey = "index_" . md5($memcacheKey);
			
			if ($index = $this->memcachedGet($memcacheKey)) {
				foreach ($index as $memcacheID) {
					if ($results = $this->memcachedGet($memcacheID)) {
						foreach ($results as $row) {
							foreach ($row as $row_key => $row_value) {
								if ($key == $row_key && $value == $row_value) {
									$this->memcachedDelete($memcacheID);
									break 2;
								}
							}
						}
					}
				}
			}
		}
		
		$field_keys = array_keys($fields);
		$field_values = array_values($fields);
		
		// Create insert query
		$sql = "INSERT INTO " . $table . " (";
		foreach ($field_keys as $key) {
			$sql .= $key . ",";
		}
		$sql = substr($sql, 0, -1);
		$sql .= ") VALUES (";
		
		foreach ($field_values as $value) {
			$sql .= "'" . $this->databaseEscape($value) . "',";
		}
		$sql = substr($sql, 0, -1);
		$sql .= ")";
		
		return $this->databaseWrite($sql, $databaseInsert);
	}
	
	/*
	 * Replace a specific record in a table in a standardised way for memcached and expiring
	 * @param $table the table to insert the data
	 * @param $fields an array of key/value
	 */
	public function replaceTable($table, $fields) {

		// Find primary keys
		$keys = $this->tablePrimaryKeys($table);
		$insert = false;
		
		// Primary key not referenced then insert
		foreach ($keys as $key) {
			if (!array_key_exists($key, $fields)) {
				$insert = true;
				break;
			}
		}
		
		// Check the primary key exists to update otherwise insert
		if (!$insert) {
			$sql = "SELECT * FROM " . $table . " WHERE";
			foreach ($keys as $key) {
				$sql .= " " . $key . " = '" . $this->databaseEscape($fields[$key]) . "' AND";
			}
			
			$sql = substr($sql, 0, -4);
			$results = $this->databaseQuery($sql);
			
			if (!empty($results)) {
				$where = $results[0];
			}
			else {
				$insert = true;
			}
		}
		
		if (!$insert) {
			return $this->updateTable($table, $fields, $where);
		}
		else {
			return $this->insertTable($table, $fields);
		}
	}
	
	/*
	 * Delete a record from a table in a standardised way for memcached and expiring
	 * @param $table the table to query for the data
	 * @param $fields an array of key/value
	 */
	public function deleteTable($table, $fields) {
		
		// Create select query to retrieve current data
		$sql = "SELECT * FROM " . $table . " WHERE";
		foreach ($fields as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "' AND";
		}
		
		$sql = substr($sql, 0, -4);
		$results = $this->databaseQuery($sql);

		// Expire field indexes
		foreach ($results as $row) {
			foreach ($row as $key => $value) {
				$memcacheKey = $table . "_" . $key . "_" . $value;
				$memcacheKey = "index_" . md5($memcacheKey);
				
				if ($index = $this->memcachedGet($memcacheKey)) {
					foreach ($index as $memcacheID) {
						$this->memcachedDelete($memcacheID);
					}
				}
			}
		}
		
		// Create delete query
		$sql = "DELETE FROM " . $table . " WHERE";
		foreach ($fields as $key => $value) {
			$sql .= " " . $key . " = '" . $this->databaseEscape($value) . "' AND";
		}
		$sql = substr($sql, 0, -4);
		
		return $this->databaseWrite($sql);
	}
	
	/*
	 * Find primary keys for a table
	 * @param $table the table to look at
	 */
	private function tablePrimaryKeys($table) {
		
		$memcacheID = "primary_keys_" . $table;
		
		if (!$keys = $this->memcachedGet($memcacheID)) {
			$keys = array();
			$sql = "SHOW COLUMNS FROM " . $table;
			$results = $this->databaseQuery($sql);
			foreach ($results as $row) {
				if ($row['Key'] == 'PRI') {
					$keys[] = $row['Field'];
				}
			}
			$this->memcachedSet($memcacheID, $keys);
		}
		
		return $keys;
	}
}