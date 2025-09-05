<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\RunSqlStep;

require_once __DIR__ . '/StepTestCase.php';

class RunSQLStepTest extends StepTestCase {
	/**
	 * Test running a simple SQL query
	 */
	public function testRunSimpleSQLQuery() {
		$sql = "CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100));";
		$this->execution_context->put_contents( 'test.sql', $sql );

		$step = new RunSqlStep( DataReference::create( './test.sql', [
			ExecutionContextPath::class
		] ) );
		$step->run( $this->runtime, new Tracker() );

		$table_exists = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
global $wpdb;
$table_name = 'test_table';
$result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
append_output( ($result === $table_name) ? 'true' : 'false' );
PHP

		)->output_file_content;

		$this->assertEquals( 'true', $table_exists );
	}

	/**
	 * Test running SQL queries that insert data
	 */
	public function testRunSQLQueryWithInserts() {
		$sql = <<<SQL
CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100));
INSERT INTO test_table (name) VALUES ('Test 1');
INSERT INTO test_table (name) VALUES ('Test 2');
INSERT INTO test_table (name) VALUES ('Test 3');
SQL;
		$this->execution_context->put_contents( 'test.sql', $sql );

		$step = new RunSqlStep( DataReference::create( './test.sql', [
			ExecutionContextPath::class
		] ) );
		$step->run( $this->runtime, new Tracker() );

		$result = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
global $wpdb;
$count = $wpdb->get_var("SELECT COUNT(*) FROM test_table");
$rows = $wpdb->get_results("SELECT * FROM test_table ORDER BY id", ARRAY_A);
append_output( json_encode([
'count' => (int)$count,
'rows' => $rows
]) );
PHP

		)->output_file_content;

		$data = json_decode( $result, true );
		$this->assertEquals( 3, $data['count'] );
		$this->assertEquals( 'Test 1', $data['rows'][0]['name'] );
		$this->assertEquals( 'Test 2', $data['rows'][1]['name'] );
		$this->assertEquals( 'Test 3', $data['rows'][2]['name'] );
	}

	/**
	 * Test running SQL queries that modify WordPress options
	 */
	public function testRunSQLQueryModifyingWordPressOptions() {
		$sql = <<<SQL
INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sql_test_option', 'sql_test_value', 'yes');
UPDATE wp_options SET option_value = 'updated_via_sql' WHERE option_name = 'sql_test_option';
SQL;
		$this->execution_context->put_contents( 'test.sql', $sql );

		$step = new RunSqlStep( DataReference::create( './test.sql', [
			ExecutionContextPath::class
		] ) );
		$step->run( $this->runtime, new Tracker() );

		$option_value = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
append_output( get_option('sql_test_option') );
PHP

		)->output_file_content;

		$this->assertEquals( 'updated_via_sql', $option_value );
	}

	/**
	 * Test running multiple SQL statements
	 */
	public function testRunMultipleSQLStatements() {
		$sql = <<<SQL
CREATE TABLE IF NOT EXISTS test_table_1 (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(100));
CREATE TABLE IF NOT EXISTS test_table_2 (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(100));
INSERT INTO test_table_1 (value) VALUES ('table_1_data');
INSERT INTO test_table_2 (value) VALUES ('table_2_data');
SQL;
		$this->execution_context->put_contents( 'test.sql', $sql );

		$step = new RunSqlStep( DataReference::create( './test.sql', [
			ExecutionContextPath::class
		] ) );
		$step->run( $this->runtime, new Tracker() );

		$result = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
global $wpdb;

$table1_data = $wpdb->get_var("SELECT value FROM test_table_1 LIMIT 1");
$table2_data = $wpdb->get_var("SELECT value FROM test_table_2 LIMIT 1");

append_output( json_encode([
'table1' => $table1_data,
'table2' => $table2_data
]) );
PHP

		)->output_file_content;

		$data = json_decode( $result, true );
		$this->assertEquals( 'table_1_data', $data['table1'] );
		$this->assertEquals( 'table_2_data', $data['table2'] );
	}

	/**
	 * Test handling SQL errors
	 */
	public function testHandleSQLErrors() {
		$sql = "CREATE TABLE test_table (id INT PRIMARY KEY); INSERT INTO nonexistent_table VALUES (1);";
		$this->execution_context->put_contents( 'test.sql', $sql );

		$step = new RunSqlStep( DataReference::create( './test.sql', [
			ExecutionContextPath::class
		] ) );
		$step->run( $this->runtime, new Tracker() );

		$table_exists = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
global $wpdb;
$table_name = 'test_table';
$result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
append_output( ($result === $table_name) ? 'true' : 'false' );
PHP

		)->output_file_content;

		$this->assertEquals( 'true', $table_exists );
	}
}
