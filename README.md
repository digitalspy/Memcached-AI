Memcached AI
============

A system to automatically manage and expire memcached MySQL queries.

Use these functions to run single select queries and keep them updated in memcached.

Example
-------

	<?php
	
	// Create instance and connect to database server
	$cache = new MemcachedAI('localhost', 'database_user', 'database_password', 'database_name');
	
	// Connect to memcached server
	if (!$cache->memcachedAddServer('localhost', 11211)) {
		echo "Failed to connect to Memcached server";
		exit();
	}
	
	// Select admin users
	$users = $cache->selectTable('users', array('user_active' => '1', 'user_group' => 'admin'), array('user_id' => 'desc'));
	foreach ($users as $user) {
		echo $user['user_id'] . " - " . $user['user_name'] . "<br />";
	}
	
	// Update a user
	$cache->updateTable('users', array('user_name' => 'Bob'), array('user_id' => '45'));
	
	// Insert new user
	$user_id = $cache->insertTable('users', array('user_name' => 'Mike', 'user_email' => 'mike@test.com', 'user_active' => '1', 'user_group' => 'guest'), true);
	echo "Added " . $user_id;
	
	// Delete user
	$cache->deleteTable('users', array('user_id' => '45'));
	
	// Replace user
	$cache->replaceTable('users', array('user_id' => 46, 'user_name' => 'Michael', 'user_email' => 'michael@test.com', 'user_active' => '1', 'user_group' => 'guest'));
	?>

Requirements
-------------

* PHP 5+ with Memcached or Memcache class
* MySQL
* Memcached

Feedback
--------

Feel free to get in touch - engineering.software@digitalspy.co.uk