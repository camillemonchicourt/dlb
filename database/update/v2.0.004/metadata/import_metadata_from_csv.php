<?php
/*
 * Synopsis: see usage
 *
 * TODO: Possible evolution is automating deletion and re-creation of foreign keys constraints
 */
$metadataDir = dirname(__FILE__);
require_once "$metadataDir/../../../lib/share.php";

// in order to read CSV with exotic endlines
ini_set("auto_detect_line_endings", true);

$templateFilePath = "$metadataDir/import_metadata_from_csv.tpl";
$sqlFilePath = "$metadataDir/import_metadata.sql";

define('INSERT_TAG', '@INSERT@');
define('SCHEMA_TAG', '@SCHEMA@');

// This function print the inline doucmentation
// ------------------------------------------------------------------------------
function usage($param_definition) {
	print("\nphp import_metadata_from_csv.php -f /path/to/CONFIG_FILE -Dschema=SCHEMA [{-DOPTION=VALUE}]\n\n");
	print(" * CONFIG_FILE : properties file containing the connection informations.");
	print(" * SCHEMA : The schema to populate (usualy metadata or metadata_work.");
	print(" * OPTION : The option you need to overwrite or complete the config file.");
	print("Synopsis:
	Generate import_metadata.sql file. This sql script will be executed in order to
	supply data described in CSV files into the schema SCHEMA.
	The CSV files must be in the current directory.
	The sql file is generated by copying the template file import_metadata_from_csv.tpl
	and replacing lines like \"@INSERT@ file.csv\" by the set of insert commands for the data in the file.csv\n\n");
}


// Synopsis:
// Write insert commands in the $sqlFile file. Data come from $csvFilePath file
// Note:
// DB connection is needfull in order to get the type of table columns.
// This is importnant to quote the text like values.
// Note:
// CSV files are to be in the current directory
// ------------------------------------------------------------------------------
function writeInsertFromCSV($csvFilePath, $sqlFile, $db_con, $schema) {
	$tableName = substr(basename($csvFilePath), 0, -4);
	
	// columns type are collect here so as to know if values must be quoted
	$qry = $db_con->prepare('SELECT * FROM ' . $schema . '.' . $tableName . ' LIMIT 1');
	$qry->execute();
	$field_quote = array();
	
	for ($i = 0; $i < $qry->columnCount(); $i ++) {
		$type = $qry->getColumnMeta($i)['native_type'];
		if ($type === 'varchar' || $type === 'bpchar' || $type === 'text' || $type === 'date') {
			$field_quote[] = TRUE;
		} else {
			$field_quote[] = FALSE;
		}
	}
	
	$csvFile = fopen($csvFilePath, 'r');
	if (!$csvFile) {
		die("can't read the file $csvFile !");
		return;
	}
	
	// insert command are written now
	fwrite($sqlFile, "\n-- INSERTION IN TABLE " . $tableName . "\n");
	while (($fields = fgetcsv($csvFile, 0, ';')) !== false) {
		// empty csv values are always translate to NULL
		for ($i = 0; $i < count($fields); $i ++) {
			if ($fields[$i] == '') {
				$fields[$i] = 'NULL';
				continue;
			}
			// values are quoted if necessary.
			if ($field_quote[$i]) {
				$fields[$i] = $db_con->quote($fields[$i]);
			}
		}
		$values = implode(',', $fields);
		fwrite($sqlFile, 'INSERT INTO ' . $tableName . ' VALUES (' . $values . ');' . "\n");
	}
	fclose($csvFile);
}


// MAIN
// ------------------------------------------------------------------------------
# get config
$config = loadPropertiesFromArgs();

try {
	$db_con = new PDO("pgsql:host=" . $config['db.host'] . ";port=" . $config['db.port'] . ";dbname=" . $config['db.name'] . ";", $config['db.adminuser'], $config['db.adminuser.pw']);
} catch (Exception $e) {
	die('Erreur :' . $e->getMessage());
}

$dir_files_names = scandir($metadataDir);
$csv_list = array();
foreach ($dir_files_names as $file_name) {
	if (substr($file_name, -4, 4) == '.csv') {
		$csv_list[] = $file_name;
	}
}

$tplFile = fopen($templateFilePath, 'r');
$sqlFile = fopen($sqlFilePath, 'w');

// copy the template and replace "@INSERT@ fichier.csv" by the insert commands
while (($line = fgets($tplFile)) !== false) {
	// @SCHEMA@ is replace by the target schema
	$line = str_replace(SCHEMA_TAG, $config['schema'], $line);
	
	// replacement of @INSERT@ tags
	if (strpos(trim($line), INSERT_TAG) === 0) {
		$csv_name = trim(substr($line, strlen(INSERT_TAG)));
		// if csv file is not present in the current directory it is skiped.
		if (in_array($csv_name, $csv_list)) {
			writeInsertFromCSV("$metadataDir/$csv_name", $sqlFile, $db_con, $config['schema']);
		}
	} else {
		fwrite($sqlFile, $line);
	}
}

// close DB connection
$db_con = null;

// DB update
// connection string format is used tp pass the password: http://www.postgresql.org/docs/current/interactive/libpq-connect.html#LIBPQ-PARAMKEYWORDS
/*
exec('psql "host=' . $config['db.host'] . ' port=' . $config['db.port'] . ' user=' . $config['db.adminuser'] . ' password=' . $config['db.adminuser.pw'] . ' dbname=' . $config['db.name'] . '" -f ' . $sqlFilePath, $output);
foreach ($output as $line) {
	print "$line\n";
}
*/
execSQLFile($sqlFilePath, $config);
