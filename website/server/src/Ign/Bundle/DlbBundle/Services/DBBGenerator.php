<?php
namespace Ign\Bundle\DlbBundle\Services;

use Ign\Bundle\GincoBundle\Entity\RawData\DEE;
use Ign\Bundle\GincoBundle\Entity\Website\Message;
use Ign\Bundle\GincoBundle\Exception\DEEException;
use Ign\Bundle\OGAMBundle\Entity\Generic\QueryForm;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Ign\Bundle\OGAMBundle\Entity\RawData\Jdd;
use Ign\Bundle\OGAMBundle\Services\ConfigurationManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class DBBGenerator
 * Responsible of the export of the DBB
 *
 * @package Ign\Bundle\DlbBundle\Services
 *         
 * @author AMouget
 */
class DBBGenerator {

	/**
	 * DBBGenerator constructor.
	 *
	 * @param
	 *        	$em
	 * @param
	 *        	$configuration
	 * @param
	 *        	$genericService
	 * @param
	 *        	$queryService
	 * @param
	 *        	$logger
	 */
	public function __construct($em, $configuration, $genericService, $queryService, $logger) {
		$this->em = $em;
		$this->configuration = $configuration;
		$this->genericService = $genericService;
		$this->queryService = $queryService;
		$this->logger = $logger;
	}

	/**
	 * Create the CSV of the DBB for jdd with the id given.
	 * Write it in file.
	 *
	 * @param DEE $DEE        	
	 * @return bool
	 * @throws DEEException
	 */
	public function generateDBB(DEE $DEE) {
		// Configure memory and time limit because the program asks a lot of resources
		ini_set("memory_limit", $this->configuration->getConfig('memory_limit', '1024M'));
		ini_set("max_execution_time", 0);
		
		// Get validated (published) submissions in the jdd, stored in the DEE line
		//$submissionsIds = $DEE->getSubmissions();
		
		// Get the jdd and the data model
		$jdd = $DEE->getJdd();

		$submissions = $jdd->getSuccessfulSubmissions();
		$submissionsIds = array();
		foreach ($submissions as $submission) {
			$this->logger->debug('submission : ' . $submission->getId());
			$submissionsIds[] = $submission->getId();
		}
		//$DEE->setSubmissions($submissionsIds);
		//$this->em->flush();
		
		$model = $jdd->getModel();
		
		// -- Create a query object : the query must find all lines with given submission_ids
		// And print a list of all fields in the model
		$queryForm = new QueryForm();
		
		// Get all table fields for model
		$tableFields = $this->em->getRepository('OGAMBundle:Metadata\TableField')->getTableFieldsForModel($model->getId());
		// Get all Form Fields for Model
		$formFields = $this->em->getRepository('OGAMBundle:Metadata\FormField')->getFormFieldsFromModel($model->getId());
		
		// -- Criteria fields for the query : we only add SUBMISSION_IDs
		// -- Result fields for the query : all fields of the model, we will sort them later
		foreach ($formFields as $formField) {
			$data = $formField->getData()->getData();
			$format = $formField->getFormat()->getFormat();
			
			// Search criteria
			switch ($data) {
				case 'SUBMISSION_ID':
					// Add submissions ids as an array, it will result in a x OR y OR z
					$queryForm->addCriterion($format, 'SUBMISSION_ID', $submissionsIds);
					break;
			}
			// Result columns
			$queryForm->addColumn($format, $data);
		}
		
		// -- Generate the SQL Request
		
		$options = array(
			'geometry_format' => 'wkt',
			'geometry_srs' => 4326,
			"date_format" => 'YYYY-MM-DD',
			"datetime_format" => 'YYYY-MM-DD"T"HH24:MI:SSTZ',
			"time_format" => 'HH24:mi:ss'
		);
		
		// Get the mappings for the query form fields
		$queryForm = $this->queryService->setQueryFormFieldsMappings($queryForm);
		$mappingSet = $queryForm->getFieldMappingSet($queryForm);
		
		// Fake user params, OK
		$userInfos = [
			"providerId" => NULL,
			"DATA_QUERY_OTHER_PROVIDER" => true,
			"DATA_EDITION_OTHER_PROVIDER" => true
		];
		
		$select = $this->genericService->generateSQLSelectRequest('RAW_DATA', $queryForm->getColumns(), $mappingSet, $userInfos, $options);
		$from = $this->genericService->generateSQLFromRequest('RAW_DATA', $mappingSet);
		$where = $this->genericService->generateSQLWhereRequest('RAW_DATA', $queryForm->getCriteria(), $mappingSet, $userInfos);
		$sqlPKey = $this->genericService->generateSQLPrimaryKey('RAW_DATA', $mappingSet);
		$order = " ORDER BY " . $sqlPKey;
		$sql = $select . $from . $where . $order;
		
		// Count Results
		$total = $this->queryService->getQueryResultsCount($from, $where);
		
		// -- Export results to a CSV file
		if ($total != 0) {
			
			// Opens a file
			$fileNameDBB = $this->generateFilePathDBB($jdd);
			
			$out = fopen($fileNameDBB, 'w');
			if (!$out) {
				throw new DEEException("Error: could not open (w) file: $fileName");
			}
			fclose($out);
			
			// -- Batch execute the request, and write observations to the output file
			$batchLines = 1000;
			$batches = ceil($total / $batchLines);
			
			$sortFields = array(
				'jddmetadonneedeeid',
				'identifiantpermanent',
				'organismegestionnairedonnee',
				'statutsource',
				'statutobservation',
				'nomcite',
				'cdnom',
				'cdref',
				'jourdatedebut',
				'jourdatefin',
				'observateurnomorganisme',
				'determinateurnomorganisme',
				'datedetermination',
				'occmethodedetermination',
				'sensible',
				'sensiniveau',
				'sensidateattribution',
				'sensireferentiel',
				'sensiversionreferentiel',
				'geometrie',
				'precisiongeometrie',
				'natureobjetgeo',
				'nomcommune',
				'nomcommunecalcule',
				'codecommunecalcule',
				'codemaillecalcule',
				'codedepartementcalcule',
				'obscontexte',
				'obsdescription',
				'obsmethode',
				'occetatbiologique',
				'occnaturalite',
				'occsexe',
				'occstadedevie',
				'occstatutbiogeographique',
				'occstatutbiologique',
				'objetdenombrement',
				'typedenombrement',
				'denombrementmax',
				'denombrementmin',
				'commentaire',
				'identifiantregroupementpermanent',
				'methoderegroupement',
				'typeregroupement',
				'altitudemax',
				'altitudemin',
				'altitudemoyenne',
				'profondeurmax',
				'profondeurmin',
				'profondeurmoyenne',
				'preuveexistante',
				'preuvenonnumerique',
				'preuvenumerique',
				'referencebiblio',
				'identifiantorigine',
				'jddcode',
				'jddid',
				'jddsourceid',
				'versionrefmaille',
				'versiontaxref'
			);
			
			// Get the column names
			$line = array();
			foreach ($sortFields as $sortField) {
				$data = $this->em->getRepository('OGAMBundle:Metadata\Data')->findOneByData($sortField);
				$line[] = $data->getLabel();
			}
			
			// Put the lines to write in an array
			$resultsArray = array();
			$resultsArray[] = $line;
			
			for ($i = 0; $i < $batches; $i ++) {
				
				$batchSQL = $sql . " LIMIT $batchLines OFFSET " . $i * $batchLines;
				
				// -- Execute query and put results in a formatted array of strings
				$results = $this->queryService->getQueryResults($batchSQL);
				
				// Put lines in a formatted array
				foreach ($results as $line) {
					$resultLine = array();
					foreach ($tableFields as $tableField) {
						$key = strtolower($tableField->getName());
						$value = $line[$key];
						$data = $tableField->getData()->getData();
						
						if ($value == null) {
							$resultLine[$data] = '';
						} else {
							$type = $tableField->getData()
								->getUnit()
								->getType();
							switch ($type) {
								case 'ARRAY':
									// Just sanitize string (remove "", {}, and [])
									$bad = array(
										"[",
										"]",
										"\"",
										"'"
									);
									$value = str_replace($bad, "", $value);
									$resultLine[$data] = $value;
									break;
								
								case "CODE":
								default:
									// Default case : String or numeric value
									$resultLine[$data] = $value;
									break;
							}
						}
					}
					
					// Masks sensitive fields when data is sensitive
					$datasToMask = array(
						'geometrie',
						'nomcommune',
						'nomcommunecalcule',
						'codecommune',
						'codecommunecalcule',
						'codemaille',
						'codemaillecalcule',
						'codedepartement',
						'codedepartementcalcule'
					);
					if ($resultLine['sensiniveau'] != '0') {
						
						foreach ($datasToMask as $dataToMask) {
							$resultLine[$dataToMask] = 'Masqué';
						}
					}
					
					// Orders fields
					$properOrderedLine = array();
					foreach ($sortFields as $sortField) {
						$properOrderedLine[$sortField] = $resultLine[$sortField];
					}
					
					$resultsArray[] = $properOrderedLine;
				}
				
				// Writes the file
				$file = fopen($fileNameDBB, 'w');
				if ($resultsArray != null) {
					// Write each line in the csv
					foreach ($resultsArray as $line) {
						// keep only the first count($resultColumns), because there is 2 or 3 technical fields sent back (after the result columns).
						$line = array_slice($line, 0, count($queryForm->getColumns()));
						// implode all arrays
						foreach ($line as $index => $value) {
							if (is_array($value)) {
								$line[$index] = join(",", $value); // just use join because we don't want double enclosure...
							}
						}
						
						$line = array_map(function ($field) {
							return trim($field, '"');
						}, $line);
						
						// use the default csv handler to write line in file
						fputcsv($file, $line, ";", '"');
					}
				}
				fclose($file);
			}
		}
		
		// Create an archive of the csv (zip)
		$parentDir = dirname($fileNameDBB); // dbbPublicDirectory
		$file = basename($fileNameDBB); // fichier.csv
		$archiveName = $parentDir . '/' . basename($fileNameDBB, '.csv') . '.zip';
		try {
			chdir($parentDir);
			system("zip -r $archiveName $file");
		} catch (\Exception $e) {
			throw new DEEException("Could not create archive $archiveName:" . $e->getMessage());
		}
		// Delete CSV file
		// unlink($fileNameDBB);
		
		// Put zip filePath in JddField
		$this->logger->debug('fileDBB : ' . $archiveName);
		
		$jdd->setField('dbbFilePath', $archiveName);
		$this->em->flush();
		
		return $file;
	}

	/**
	 * Create the filepath of the DBB csv file
	 *
	 * @param Jdd $jdd        	
	 * @return string
	 */
	public function generateFilePathDBB(Jdd $jdd) {
		$regionCode = $this->configuration->getConfig('regionCode', 'REGION');
		$now = new \DateTime();
		$date = $now->format('Y-m-d_H-i-s');
		
		$uuid = $jdd->getField('metadataId', $jdd->getId());
		
		$fileNameWithoutExtension = $regionCode . '_' . $date . '_' . $uuid;
		$filePath = $this->configuration->getConfig('dbbPublicDirectory') . '/' . $jdd->getId();
		@mkdir($filePath); // create the jdd dir
		$filename = $fileNameWithoutExtension . '.csv';
		
		return $filePath . '/' . $filename;
	}
}