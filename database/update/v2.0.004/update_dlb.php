<?php
$currentDir = dirname(__FILE__);
// Require file from dev or build environnement
if (is_file("$currentDir/../../../lib/share.php")) {
	require_once "$currentDir/../../../lib/share.php";
} else if (is_file("$currentDir/../../../../ginco/lib/share.php")) {
	require_once "$currentDir/../../../../ginco/lib/share.php";
} else {
	echo "Can't find file ..../lib/share.php\n\n";
	exit(1);
}

// ----------------------------------------------------
// Synopsis: migrate DB GINCO/DLB from v2.0.003 to v2.0.004
// ----------------------------------------------------
function usage($mess = NULL) {
	echo "------------------------------------------------------------------------\n";
	echo ("\nApplies DLB patches to latest Ginco/DLB version database (the database should be up to date before launching this script");
	echo ("> php update_dlb.php -f <configFile> [{-D<propertiesName>=<Value>}]\n\n");
	echo "o <configFile>: a java style properties file for the instance on which you work\n";
	echo "o -D : inline options to complete or override the config file.\n";
	echo "------------------------------------------------------------------------\n";
	if (!is_null($mess)) {
		echo ("$mess\n\n");
		exit(1);
	}
	exit();
}

if (count($argv) == 1)
	usage();
$config = loadPropertiesFromArgs();
$paramStr = implode(' ', array_slice($argv, 1));

try {
	/* patch code here */
	execCustSQLFile("$currentDir/add_cancel_jdd_publication_permission.sql", $config);
	execCustSQLFile("$currentDir/add_permission_on_published_dataset.sql", $config);
	execCustSQLFile("$currentDir/add_integration_service_event_listener.sql", $config);

	# setting metadata and metadata_work schema
	system("php $currentDir/metadata/import_metadata_from_csv.php $paramStr -Dschema=metadata");
	system("php $currentDir/metadata/import_metadata_from_csv.php $paramStr -Dschema=metadata_work");

	execCustSQLFile("$currentDir/add_tps_id_field.sql", $config);
	execCustSQLFile("$currentDir/update_home_content.sql", $config);
} catch (Exception $e) {
	echo "$currentDir/update_dlb.php\n";
	echo "exception: " . $e->getMessage() . "\n";
	exit(1);
} finally {
	echo "Finished applying patches on DLB database.\n";
}

$CLIParams = implode(' ', array_slice($argv, 1));
/* patch user raw_data here */
system("php $currentDir/update_roles.php $CLIParams", $returnCode1);

if ($returnCode1 != 0) {
	echo "$currentDir/apply_db_patch.php\n";
	echo "exception: " . $e->getMessage() . "\n";
	exit(1);
}
