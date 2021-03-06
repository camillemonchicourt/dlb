<?php
namespace Ign\Bundle\DlbBundle\Controller;

use Ign\Bundle\GincoBundle\Entity\RawData\DEE;
use Ign\Bundle\GincoBundle\Entity\Website\Message;
use Ign\Bundle\GincoBundle\Exception\DEEException;
use Ign\Bundle\OGAMBundle\Controller\GincoController;
use Ign\Bundle\OGAMBundle\Entity\RawData\Jdd;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @Route("/")
 *
 * @author AMouget
 */
class DBBController extends GincoController {

	/**
	 * DLB generation action
	 * Publish a RabbitMQ message to generate the DLB in background
	 * Include generation of dbb, metadatas, certificate and dee
	 *
	 * @param Request $request
	 * @return JsonResponse GET parameter: jddId, the Jdd identifier
	 *
	 *         @Route("/dlb/generate_dlb", name = "generate_dlb")
	 */
	public function generateDLB(Request $request) {
		$em = $this->get('doctrine.orm.entity_manager');

		// Find jddId if given in GET parameters
		$jddId = intval($request->query->get('jddId', 0));
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);
		if (!$jdd) {
			return new JsonResponse([
				'success' => false,
				'message' => 'No jdd found for this id: ' . $jddId
			]);
		}

		$dbbProcess = $this->get('dlb.dbb_process');
		$deeProcess = $this->get('ginco.dee_process');

		// Create a line in the DEE table
		$newDEE = $deeProcess->createDEELine($jdd, $this->getUser(), 'Dépôt Légal de données de Biodiversité');

		// Add information in jddField table
		$jdd->setField('status', 'generating');
		$now = new \DateTime();
		$jdd->setField('publishedAt', $now->format('Y-m-d_H-i-s'));
		$em->flush();

		// Publish the message to RabbitMQ
		$messageId = $this->get('old_sound_rabbit_mq.ginco_generic_producer')->publish('dbbProcess', [
			'DEEId' => $newDEE->getId()
		]);
		$message = $em->getRepository('IgnGincoBundle:Website\Message')->findOneById($messageId);

		// Attach message id to the DEE (use it for the progress bar)
		$newDEE->setMessage($message);
		$em->flush();

		return new JsonResponse($this->getStatus($jddId));
	}

	/**
	 * Direct generation of DLB (dbb, metadatas, certificate, dee) for testing
	 *
	 * @Route("/dlb/{id}/generate_dlb_direct", name = "generate_dlb_direct", requirements={"id": "\d+"})
	 */
	public function directDLBAction(Jdd $jdd) {
		$dbbProcess = $this->get('dlb.dbb_process');
		$deeProcess = $this->get('ginco.dee_process');

		// Create a line in the DEE table
		$newDEE = $deeProcess->createDEELine($jdd, $this->getUser(), 'Dépôt Légal de données de Biodiversité - test');

		$dbbProcess->generateAndSendDBB($newDEE->getId());

		return $this->redirect($this->generateUrl('integration_home'));
	}

	/**
	 * Undo generation of DLB and unpublish JDD
	 * (for testing)
	 *
	 * @Route("/dlb/{id}/unpublish", name = "unpublish_dlb", requirements={"id": "\d+"})
	 */
	public function undoDLBAction(Jdd $jdd) {
		if (!$this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
			throw $this->createAccessDeniedException();
		}
		if (!$this->getUser()->isAllowed('CANCEL_DATASET_PUBLICATION')) {
			throw $this->createAccessDeniedException();
		}

		$dbbProcess = $this->get('dlb.dbb_process');
		$dbbProcess->unpublishJdd($jdd);
		return $this->redirect($this->generateUrl('integration_home'));
	}

	/**
	 * Direct generation of DBB for testing
	 *
	 * @Route("/dlb/{jddId}/generate_dbb", name = "generate_dbb", requirements={"jddId": "\d+"})
	 */
	public function generateDbbCsvAction($jddId) {
		$em = $this->get('doctrine.orm.entity_manager');
		$deeProcess = $this->get('ginco.dee_process');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);

		// Create a line in the DEE table
		$newDEE = $deeProcess->createDEELine($jdd, $this->getUser(), 'Dépôt Légal de données de Biodiversité - test');

		// Use DEE entity to generate dbb (attributes are the same)
		$DEE = $em->getRepository('IgnGincoBundle:RawData\DEE')->findOneByJdd($jddId);
		$this->get('dlb.dbb_generator')->generateDBB($DEE);

		return $this->redirect($this->generateUrl('integration_home'));
	}

	/**
	 * Save PDF Certificate for testing
	 *
	 * @Route("/dlb/{jddId}/generate_certificate", name="generate_certificate", requirements={"jddId": "\d+"})
	 */
	public function pdfSaveAction($jddId) {
		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);

		$this->get('dlb.certificate_generator')->generateCertificate($jdd);

		return $this->redirect($this->generateUrl('integration_home'));
	}

	/**
	 * Save PDF Certificate for testing
	 *
	 * @Route("/dlb/{jddId}/generate_certificate_twig", name="generate_certificate_twig", requirements={"jddId": "\d+"})
	 */
	public function pdftwigSaveAction($jddId) {
		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);

		return $this->render('IgnDlbBundle:Jdd:certificate_pdf.html.twig', array(
			'jdd' => $jdd,
			'jddCAMetadataFileDownloadServiceURL' => 'dsf'
		));
	}

	/**
	 * Download dbb (zipped csv)
	 *
	 * @param
	 *        	$jddId
	 * @return BinaryFileResponse @Route("/dlb-download/{jddId}/download-dbb", name = "download_dbb", requirements={"jddId": "\d+"})
	 */
	public function downloadDbb($jddId) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);
		$filePath = $jdd->getField('dbbFilePath');

		return $this->download($filePath);
	}

	/**
	 * Download certificate
	 *
	 * @param
	 *        	$jddId
	 * @return BinaryFileResponse @Route("/dlb-download/{jddId}/download-certificate", name = "download_certificate", requirements={"jddId": "\d+"})
	 */
	public function downloadCertificate($jddId) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);
		$filePath = $jdd->getField('certificateFilePath');

		return $this->download($filePath);
	}

	/**
	 * Download metadata CA
	 *
	 * @param
	 *        	$jddId
	 * @return BinaryFileResponse @Route("/dlb-download/{jddId}/download-mtdca", name = "download_mtdca", requirements={"jddId": "\d+"})
	 */
	public function downloadMtdCA($jddId) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);

		$dbbPublicDirectory = $this->get('ogam.configuration_manager')->getConfig('dbbPublicDirectory');
		$metadataCAId = $jdd->getField('metadataCAId');

		$caMetadataFile = $dbbPublicDirectory . '/' . $jdd->getId() . '/' . $metadataCAId;

		return $this->download($caMetadataFile);
	}

	/**
	 * Download metadata Jdd
	 *
	 * @param
	 *        	$jddId
	 * @return BinaryFileResponse @Route("/dlb-download/{jddId}/download-mtdjdd", name = "download_mtdjdd", requirements={"jddId": "\d+"})
	 */
	public function downloadMtdJdd($jddId) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);

		$dbbPublicDirectory = $this->get('ogam.configuration_manager')->getConfig('dbbPublicDirectory');
		$metadataId = $jdd->getField('metadataId');

		$jddMetadataFile = $dbbPublicDirectory . '/' . $jdd->getId() . '/' . $metadataId;

		return $this->download($jddMetadataFile);
	}

	/**
	 * Download the zip archive of a DEE for a jdd
	 * Note: direct downloading is prohibited by apache configuration, except for a list of IPs
	 *
	 * @param DEE $DEE
	 * @return BinaryFileResponse
	 * @throws DEEException @Route("/dlb/{jddId}/download-dee-dlb", name = "download_dee_dlb", requirements={"jddId": "\d+"})
	 */
	public function downloadDEE($jddId) {
		$em = $this->get('doctrine.orm.entity_manager');
		$jdd = $em->getRepository('OGAMBundle:RawData\Jdd')->findOneById($jddId);
		$DEE = $em->getRepository('IgnGincoBundle:RawData\DEE')->findOneByJdd($jddId);

		// Get archive
		$archivePath = $DEE->getFilePath();
		if (!$archivePath) {
			throw new DEEException("No archive file path for this DEE: " . $DEE->getId());
		}

		// Test the existence of the zip file
		$fileName = pathinfo($archivePath, PATHINFO_BASENAME);
		$archiveFilePath = $this->get('ogam.configuration_manager')->getConfig('deePublicDirectory') . '/' . $fileName;
		if (!is_file($archiveFilePath)) {
			throw new DEEException("DEE archive file does not exist for this DEE: " . $DEE->getId() . ' ' . $archiveFilePath);
		}

		// Get back the file
		$response = new BinaryFileResponse($archiveFilePath);
		$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
		return $response;
	}

	/**
	 * Download a file
	 * Note: direct downloading is prohibited by apache configuration, except for a list of IPs
	 *
	 * @param string $file
	 * @return BinaryFileResponse
	 * @throws Exception
	 *
	 */
	private function download($filePath) {
		if (!is_file($filePath)) {
			throw new \Exception("file does not exist: " . $filePath);
		}

		$fileName = pathinfo($filePath, PATHINFO_BASENAME);

		// -- Get back the file
		$response = new BinaryFileResponse($filePath);
		$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
		return $response;
	}

	/**
	 * DBB generation - get status of the background task
	 *
	 * @param Request $request
	 * @return JsonResponse GET parameter: jddId, the Jdd identifier
	 *
	 *         @Route("/status", name = "dbb_status")
	 */
	public function getDBBStatus(Request $request) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}
		// Find jddId if given in GET parameters
		$jddId = intval($request->query->get('jddId', 0));
		return new JsonResponse($this->getStatus($jddId));
	}

	/**
	 * DBBgeneration - get status of a set of background task
	 *
	 * @param Request $request
	 * @return JsonResponse GET parameter: jddIds, an array of Jdd identifiers
	 *
	 *         @Route("/status/all", name = "dbb_status_all")
	 */
	public function getDBBStatusAll(Request $request) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		// Find jddIds if given in GET parameters
		$jddIds = $request->query->get('jddIds', []);

		$json = array();
		foreach ($jddIds as $jddId) {
			$json[] = $this->getStatus($jddId);
		}

		return new JsonResponse($json);
	}

	/**
	 * Returns a json array with informations about the DBB generation process
	 * This is the expected return of all DBB Ajax actions on the Jdd pages
	 *
	 * @param
	 *        	$jddId
	 * @param DBB|null $DBB
	 * @return array @Route("/status/get", name = "dbb_status_get")
	 */
	protected function getStatus($jddId) {
		// Checks rights as non authentificated user has VIEW_PUBLISHED_DATASETS permission
		if (!$this->getUser()->isAllowed('VIEW_PUBLISHED_DATASETS') && !$this->getUser()->isAllowed('MANAGE_DATASETS')) {
			throw $this->createAccessDeniedException();
		}

		$em = $this->get('doctrine.orm.entity_manager');
		$jddRepo = $em->getRepository('OGAMBundle:RawData\Jdd');

		// The returned informations
		$json = array(
			'jddId' => $jddId,
			'success' => true
		);

		$jdd = $jddRepo->findOneById($jddId);
		if (!$jdd) {
			$json['success'] = false;
			$json['error_message'] = 'No jdd found';
		} else {
			// Do the JDD has submissions ?
			// Are they all successful ?
			// If yes, the DBB can be generated
			$submissionCount = $jdd->getActiveSubmissions()->count();
			$submissionSuccessfulCount = $jdd->getSuccessfulSubmissions()->count();

			$json['canGenerateDBB'] = ($submissionCount == $submissionSuccessfulCount && $submissionSuccessfulCount > 0);

			if (empty($jdd->getField('status'))) {
				$json['dbb'] = array(
					'status' => 'unpublished'
				);
			} else {
				$createdDateTime = \DateTime::createFromFormat('Y-m-d_H-i-s', $jdd->getField('publishedAt'));

				$json['dbb'] = array(
					'id' => $jdd->getId(),
					'status' => $jdd->getField('status'),
					'createdDate' => $createdDateTime->format('d/m/Y'),
					'createdTime' => $createdDateTime->format('H\hi'),
					'fullCreated' => $jdd->getField('publishedAt')
				);

				if ($jdd->getField('status') == 'generating') {
					$DEE = $em->getRepository('IgnGincoBundle:RawData\DEE')->findOneByJdd($jddId);
					$message = $DEE->getMessage();

					if (!$message) {
						$json['message'] = array(
							'status' => Message::STATUS_NOTFOUND,
							'error_message' => 'No message found for this dee',
							'createdDate' => '1970-01-01',
							'createdTime' => '00:00',
							'fullCreated' => date('c', mktime(0, 0, 0, 1, 1, 1970))
						);
					} else {
						$json['message'] = array(
							'status' => $message->getStatus(),
							'progress' => $message->getProgressPercentage()
						);
					}
				}
			}
		}
		return $json;
	}
}