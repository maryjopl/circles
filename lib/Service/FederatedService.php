<?php
/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Circles\Service;


use Exception;
use OC\Http\Client\ClientService;
use OCA\Circles\Api\v1\Circles;
use OCA\Circles\Db\CirclesRequest;
use OCA\Circles\Db\FederatedLinksRequest;
use OCA\Circles\Exceptions\CircleDoesNotExistException;
use OCA\Circles\Exceptions\FederatedCircleLinkFormatException;
use OCA\Circles\Exceptions\FederatedCircleNotAllowedException;
use OCA\Circles\Exceptions\CircleTypeNotValidException;
use OCA\Circles\Exceptions\FederatedCircleStatusUpdateException;
use OCA\Circles\Exceptions\FederatedLinkUpdateException;
use OCA\Circles\Exceptions\FederatedRemoteCircleDoesNotExistException;
use OCA\Circles\Exceptions\FederatedRemoteDoesNotAllowException;
use OCA\Circles\Exceptions\FederatedRemoteIsDownException;
use OCA\Circles\Exceptions\PayloadDeliveryException;
use OCA\Circles\Exceptions\SharingFrameAlreadyExistException;
use OCA\Circles\Exceptions\FederatedLinkCreationException;
use OCA\Circles\Exceptions\MemberIsNotAdminException;
use OCA\Circles\Exceptions\SharingFrameDoesNotExistException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Model\SharingFrame;
use OCP\IL10N;

class FederatedService {

	const REMOTE_URL_LINK = '/index.php/apps/circles/v1/link';
	const REMOTE_URL_PAYLOAD = '/index.php/apps/circles/v1/payload';

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var CirclesRequest */
	private $circlesRequest;

	/** @var ConfigService */
	private $configService;

	/** @var CirclesService */
	private $circlesService;

	/** @var BroadcastService */
	private $broadcastService;

	/** @var FederatedLinksRequest */
	private $federatedLinksRequest;

	/** @var EventsService */
	private $eventsService;

	/** @var string */
	private $serverHost;

	/** @var ClientService */
	private $clientService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CirclesService constructor.
	 *
	 * @param $userId
	 * @param IL10N $l10n
	 * @param CirclesRequest $circlesRequest
	 * @param ConfigService $configService
	 * @param CirclesService $circlesService
	 * @param BroadcastService $broadcastService
	 * @param FederatedLinksRequest $federatedLinksRequest
	 * @param EventsService $eventsService
	 * @param ClientService $clientService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId,
		IL10N $l10n,
		CirclesRequest $circlesRequest,
		ConfigService $configService,
		CirclesService $circlesService,
		BroadcastService $broadcastService,
		FederatedLinksRequest $federatedLinksRequest,
		EventsService $eventsService,
		ClientService $clientService,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->circlesRequest = $circlesRequest;
		$this->configService = $configService;
		$this->circlesService = $circlesService;
		$this->broadcastService = $broadcastService;
		$this->federatedLinksRequest = $federatedLinksRequest;
		$this->eventsService = $eventsService;
		$this->serverHost = $this->configService->getLocalAddress();


		$this->clientService = $clientService;
		$this->miscService = $miscService;
	}


	/**
	 * linkCircle();
	 *
	 * link to a circle.
	 * Function will check if settings allow Federated links between circles, and the format of
	 * the link ($remote). If no exception, a request to the remote circle will be initiated
	 * using requestLinkWithCircle()
	 *
	 * $remote format: <circle_name>@<remote_host>
	 *
	 * @param string $circleUniqueId
	 * @param string $remote
	 *
	 * @throws Exception
	 * @throws FederatedCircleLinkFormatException
	 * @throws CircleTypeNotValidException
	 *
	 * @return FederatedLink
	 */
	public function linkCircle($circleUniqueId, $remote) {

		if (!$this->configService->isFederatedCirclesAllowed()) {
			throw new FederatedCircleNotAllowedException(
				$this->l10n->t("Federated circles are not allowed on this Nextcloud")
			);
		}

		if (strpos($remote, '@') === false) {
			throw new FederatedCircleLinkFormatException(
				$this->l10n->t("Federated link does not have a valid format")
			);
		}

		try {
			return $this->requestLinkWithCircle($circleUniqueId, $remote);
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * linkStatus()
	 *
	 * Update the status of a link.
	 * Function will check if user can edit the status, will update it and send the update to
	 * remote
	 *
	 * @param int $linkId
	 * @param int $status
	 *
	 * @throws Exception
	 * @throws FederatedCircleLinkFormatException
	 * @throws CircleTypeNotValidException
	 * @throws MemberIsNotAdminException
	 *
	 * @return FederatedLink[]
	 */
	public function linkStatus($linkId, $status) {

		$status = (int)$status;
		$link = null;
		try {

			$link = $this->circlesRequest->getLinkFromId($linkId);
			$circle = $this->circlesRequest->getCircle($link->getCircleId(), $this->userId);
			$circle->hasToBeFederated();
			$circle->getHigherViewer()
				   ->hasToBeAdmin();
			$link->hasToBeValidStatusUpdate($status);

			if (!$this->eventOnLinkStatus($circle, $link, $status)) {
				return $this->circlesRequest->getLinksFromCircle($circle->getUniqueId());
			}

		} catch (Exception $e) {
			throw $e;
		}

		$link->setStatus($status);
		$link->setCircleId($circle->getUniqueId(true));

		try {
			$this->updateLinkRemote($link);
		} catch (Exception $e) {
			if ($status !== FederatedLink::STATUS_LINK_REMOVE) {
				throw $e;
			}
		}

		$this->federatedLinksRequest->update($link);

		return $this->circlesRequest->getLinksFromCircle($circle->getUniqueId());
	}


	/**
	 * eventOnLinkStatus();
	 *
	 * Called by linkStatus() to manage events when status is changing.
	 * If status does not need update, returns false;
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 * @param $status
	 *
	 * @return bool
	 */
	private function eventOnLinkStatus(Circle $circle, FederatedLink $link, $status) {
		if ($link->getStatus() === $status) {
			return false;
		}

		if ($status === FederatedLink::STATUS_LINK_REMOVE) {
			$this->eventsService->onLinkRemove($circle, $link);
		}

		if ($status === FederatedLink::STATUS_LINK_UP) {
			$this->eventsService->onLinkRequestAccepting($circle, $link);
			$this->eventsService->onLinkUp($circle, $link);
		}

		return true;
	}


	/**
	 * requestLinkWithCircle()
	 *
	 * Using CircleId, function will get more infos from the database.
	 * Will check if author is at least admin and initiate a FederatedLink, save it
	 * in the database and send a request to the remote circle using requestLink()
	 * If any issue, entry is removed from the database.
	 *
	 * @param string $circleUniqueId
	 * @param string $remote
	 *
	 * @return FederatedLink
	 * @throws Exception
	 */
	private function requestLinkWithCircle($circleUniqueId, $remote) {

		$link = null;
		try {
			list($remoteCircle, $remoteAddress) = explode('@', $remote, 2);

			$circle = $this->circlesService->detailsCircle($circleUniqueId);
			$circle->getHigherViewer()
				   ->hasToBeAdmin();
			$circle->hasToBeFederated();
			$circle->cantBePersonal();

			$link = new FederatedLink();
			$link->setCircleId($circleUniqueId)
				 ->setLocalAddress($this->serverHost)
				 ->setAddress($remoteAddress)
				 ->setRemoteCircleName($remoteCircle)
				 ->setStatus(FederatedLink::STATUS_LINK_SETUP)
				 ->generateToken();

			$this->federatedLinksRequest->create($link);
			$this->requestLink($circle, $link);

		} catch (Exception $e) {
			if ($link !== null) {
				$this->federatedLinksRequest->delete($link);
			}
			throw $e;
		}

		return $link;
	}


	/**
	 * @param string $remote
	 *
	 * @return string
	 */
	private function generateLinkRemoteURL($remote) {
		return $this->generateRemoteHost($remote) . self::REMOTE_URL_LINK;
	}


	/**
	 * @param string $remote
	 *
	 * @return string
	 */
	private function generatePayloadDeliveryURL($remote) {
		return $this->generateRemoteHost($remote) . self::REMOTE_URL_PAYLOAD;
	}


	/**
	 * @param string $remote
	 *
	 * @return string
	 */
	private function generateRemoteHost($remote) {
		if ((!$this->configService->isNonSSLLinksAllowed() || strpos($remote, 'http://') !== 0)
			&& strpos($remote, 'https://') !== 0
		) {
			$remote = 'https://' . $remote;
		}

		return rtrim($remote, '/');
	}


	/**
	 * requestLink()
	 *
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 *
	 * @return boolean
	 * @throws Exception
	 */
	private function requestLink(Circle $circle, FederatedLink &$link) {
		$args = [
			'apiVersion' => Circles::version(),
			'token'      => $link->getToken(true),
			'uniqueId'   => $circle->getUniqueId(true),
			'sourceName' => $circle->getName(),
			'linkTo'     => $link->getRemoteCircleName(),
			'address'    => $link->getLocalAddress()
		];

		$client = $this->clientService->newClient();

		try {
			$request = $client->put(
				$this->generateLinkRemoteURL($link->getAddress()), [
																	 'body'            => $args,
																	 'timeout'         => 10,
																	 'connect_timeout' => 10,
																 ]
			);

			$result = json_decode($request->getBody(), true);
			if ($result === null) {
				throw new FederatedRemoteIsDownException(
					$this->l10n->t(
						'The remote host is down or the Circles app is not installed on it'
					)
				);
			}

			$this->eventOnRequestLink(
				$circle, $link, $result['status'],
				((key_exists('reason', $result)) ? $result['reason'] : '')
			);

			$link->setUniqueId($result['uniqueId']);
			$this->federatedLinksRequest->update($link);

			return true;
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * eventOnRequestLink();
	 *
	 * Called by requestLink() will update status and event
	 * Will also manage errors returned by the remote link
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 * @param $status
	 * @param $reason
	 *
	 * @throws Exception
	 */
	private function eventOnRequestLink(Circle $circle, FederatedLink $link, $status, $reason) {

		try {
			if ($status === FederatedLink::STATUS_LINK_UP) {
				$link->setStatus(FederatedLink::STATUS_LINK_UP);
				$this->eventsService->onLinkUp($circle, $link);
			} else if ($status === FederatedLink::STATUS_LINK_REQUESTED) {
				$link->setStatus(FederatedLink::STATUS_REQUEST_SENT);
				$this->eventsService->onLinkRequestSent($circle, $link);
			} else {
				$this->parseRequestLinkError($reason);
			}
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * parseRequestLinkError();
	 *
	 * Will parse the error reason returned by requestLink() and throw an Exception
	 *
	 * @param $reason
	 *
	 * @throws Exception
	 * @throws FederatedRemoteCircleDoesNotExistException
	 * @throws FederatedRemoteDoesNotAllowException
	 */
	private function parseRequestLinkError($reason) {

		if ($reason === 'federated_not_allowed') {
			throw new FederatedRemoteDoesNotAllowException(
				$this->l10n->t('Federated circles are not allowed on the remote Nextcloud')
			);
		}

		if ($reason === 'circle_links_disable') {
			throw new FederatedRemoteDoesNotAllowException(
				$this->l10n->t('The remote circle does not accept federated links')
			);
		}

		if ($reason === 'duplicate_unique_id') {
			throw new FederatedRemoteDoesNotAllowException(
				$this->l10n->t('It seems that you are trying to link a circle to itself')
			);
		}

		if ($reason === 'duplicate_link') {
			throw new FederatedRemoteDoesNotAllowException(
				$this->l10n->t('This link exists already')
			);
		}

		if ($reason === 'circle_does_not_exist') {
			throw new FederatedRemoteCircleDoesNotExistException(
				$this->l10n->t('The requested remote circle does not exist')
			);
		}

		throw new Exception($reason);
	}


	/**
	 * @param string $token
	 * @param string $uniqueId
	 * @param int $status
	 *
	 * @return FederatedLink
	 * @throws Exception
	 */
	public function updateLinkFromRemote($token, $uniqueId, $status) {
		try {
			$link = $this->circlesRequest->getLinkFromToken($token, $uniqueId);
			$circle = $this->circlesRequest->forceGetCircle($link->getCircleId());
			$circle->hasToBeFederated();

			$this->checkUpdateLinkFromRemote($status);
			$this->checkUpdateLinkFromRemoteLinkUp($circle, $link, $status);
			$this->checkUpdateLinkFromRemoteLinkRemove($circle, $link, $status);

			if ($link->getStatus() !== $status) {
				$this->federatedLinksRequest->update($link);
			}

			return $link;
		} catch (Exception $e) {
			throw $e;
		}
	}

	/**
	 * checkUpdateLinkFromRemote();
	 *
	 * will throw exception is the status sent by remote is not correct
	 *
	 * @param int $status
	 *
	 * @throws FederatedCircleStatusUpdateException
	 */
	private function checkUpdateLinkFromRemote($status) {
		$status = (int)$status;
		if ($status !== FederatedLink::STATUS_LINK_UP
			&& $status !== FederatedLink::STATUS_LINK_REMOVE
		) {
			throw new FederatedCircleStatusUpdateException(
				$this->l10n->t('Cannot proceed with this status update')
			);
		}
	}


	/**
	 * checkUpdateLinkFromRemoteLinkUp()
	 *
	 * in case of a request of status update from remote for a link up, we check the current
	 * status of the link locally.
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 * @param int $status
	 *
	 * @throws FederatedCircleStatusUpdateException
	 */
	private function checkUpdateLinkFromRemoteLinkUp(Circle $circle, FederatedLink $link, $status) {
		if ((int)$status !== FederatedLink::STATUS_LINK_UP) {
			return;
		}

		if ($link->getStatus() !== FederatedLink::STATUS_REQUEST_SENT) {
			throw new FederatedCircleStatusUpdateException(
				$this->l10n->t('Cannot proceed with this status update')
			);
		}

		$this->eventsService->onLinkRequestAccepted($circle, $link);
		$this->eventsService->onLinkUp($circle, $link);
		$link->setStatus($status);
	}


	/**
	 * checkUpdateLinkFromRemoteLinkRemove();
	 *
	 * in case of a request of status update from remote for a link down, we check the current
	 * status of the link locally
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 * @param int $status
	 *
	 * @throws FederatedCircleStatusUpdateException
	 */
	private function checkUpdateLinkFromRemoteLinkRemove(
		Circle $circle, FederatedLink $link, $status
	) {
		if ((int)$status !== FederatedLink::STATUS_LINK_REMOVE) {
			return;
		}

		if ($link->getStatus() === FederatedLink::STATUS_REQUEST_SENT) {
			$link->setStatus(FederatedLink::STATUS_REQUEST_DECLINED);
			$this->eventsService->onLinkRequestRejected($circle, $link);

			return;
		}

		if ($link->getStatus() === FederatedLink::STATUS_LINK_REQUESTED) {
			$link->setStatus(FederatedLink::STATUS_LINK_REMOVE);
			$this->eventsService->onLinkRequestCanceled($circle, $link);

			return;
		}

		if ($link->getStatus() > FederatedLink::STATUS_LINK_DOWN) {
			$link->setStatus(FederatedLink::STATUS_LINK_DOWN);
			$this->eventsService->onLinkDown($circle, $link);

			return;
		}

		throw new FederatedCircleStatusUpdateException(
			$this->l10n->t('Cannot proceed with this status update')
		);
	}


	/**
	 * updateLinkRemote()
	 *
	 * Send a request to the remote of the link to update its status.
	 *
	 * @param FederatedLink $link
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function updateLinkRemote(FederatedLink &$link) {
		$args = [
			'apiVersion' => Circles::version(),
			'token'      => $link->getToken(true),
			'uniqueId'   => $link->getCircleId(true),
			'status'     => $link->getStatus()
		];

		$client = $this->clientService->newClient();
		try {
			$request = $client->post(
				$this->generateLinkRemoteURL($link->getAddress()), [
																	 'body'            => $args,
																	 'timeout'         => 10,
																	 'connect_timeout' => 10,
																 ]
			);

			$result = json_decode($request->getBody(), true);
			if ($result['status'] === -1) {
				throw new FederatedLinkUpdateException($result['reason']);
			}

			return true;
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * Create a new link into database and assign the correct status.
	 *
	 * @param Circle $circle
	 * @param FederatedLink $link
	 *
	 * @throws Exception
	 */
	public function initiateLink(Circle $circle, FederatedLink &$link) {

		try {
			$this->checkLinkRequestValidity($circle, $link);
			$link->setCircleId($circle->getUniqueId());

			if ($circle->getSetting('allow_links_auto') === 'true') {
				$link->setStatus(FederatedLink::STATUS_LINK_UP);
				$this->eventsService->onLinkUp($circle, $link);
			} else {
				$link->setStatus(FederatedLink::STATUS_LINK_REQUESTED);
				$this->eventsService->onLinkRequestReceived($circle, $link);
			}

			$this->federatedLinksRequest->create($link);
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param Circle $circle
	 * @param FederatedLink $link
	 *
	 * @throws FederatedLinkCreationException
	 */
	private function checkLinkRequestValidity($circle, $link) {
		if ($circle->getUniqueId(true) === $link->getUniqueId(true)) {
			throw new FederatedLinkCreationException('duplicate_unique_id');
		}

		if ($this->getLink($circle->getUniqueId(), $link->getUniqueId(true)) !== null) {
			throw new FederatedLinkCreationException('duplicate_link');
		}

		if ($circle->getSetting('allow_links') !== 'true') {
			throw new FederatedLinkCreationException('circle_links_disable');
		}
	}


	/**
	 * @param string $token
	 * @param string $uniqueId
	 * @param SharingFrame $frame
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function receiveFrame($token, $uniqueId, SharingFrame &$frame) {
		try {
			$link = $this->circlesRequest->getLinkFromToken((string)$token, (string)$uniqueId);
		} catch (Exception $e) {
			throw $e;
		}

		try {
			$this->circlesRequest->getFrame($link->getCircleId(), $frame->getUniqueId());
			throw new SharingFrameAlreadyExistException('shares_is_already_known');
		} catch (SharingFrameDoesNotExistException $e) {
		}

		try {
			$circle = $this->circlesRequest->forceGetCircle($link->getCircleId());
		} catch (CircleDoesNotExistException $e) {
			throw new CircleDoesNotExistException('unknown_circle');
		}

		$frame->setCircle($circle);
		$this->circlesRequest->saveFrame($frame);

		return true;
	}

	/**
	 * @param string $circleUniqueId
	 * @param string $uniqueId
	 *
	 * @return FederatedLink
	 */
	public function getLink($circleUniqueId, $uniqueId) {
		return $this->federatedLinksRequest->getFromUniqueId($circleUniqueId, $uniqueId);
	}


	/**
	 * @param string $circleUniqueId
	 *
	 * @return FederatedLink[]
	 */
	public function getLinksFromCircle($circleUniqueId) {
		return $this->federatedLinksRequest->getLinked($circleUniqueId);
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $frameUniqueId
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function initiateShare($circleUniqueId, $frameUniqueId) {
		$args = [
			'circleId'   => $circleUniqueId,
			'frameId'    => $frameUniqueId
		];

		$client = $this->clientService->newClient();
		try {
			$client->post(
				$this->generatePayloadDeliveryURL($this->serverHost), [
																		'body'            => $args,
																		'timeout'         => 10,
																		'connect_timeout' => 10,
																	]
			);

//			$result = json_decode($request->getBody(), true);
//			$this->miscService->log(
//				"initiateRemoteShare result: " . $uniqueId . '  ----  ' . var_export($result, true)
//			);

			return true;
		} catch (Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param SharingFrame $frame
	 *
	 * @throws Exception
	 */
	public function sendRemoteShare(SharingFrame $frame) {

		try {
			$circle = $this->circlesRequest->forceGetCircle(
				$frame->getCircle()
					  ->getUniqueId()
			);
		} catch (CircleDoesNotExistException $e) {
			throw new CircleDoesNotExistException('unknown_circle');
		}

		$links = $this->getLinksFromCircle(
			$frame->getCircle()
				  ->getUniqueId()
		);

		foreach ($links AS $link) {


			$args = [
				'apiVersion' => Circles::version(),
				'token'      => $link->getToken(true),
				'uniqueId'   => $circle->getUniqueId(true),
				'item'       => json_encode($frame)
			];

			$client = $this->clientService->newClient();
			try {
				$request = $client->put(
					$this->generatePayloadDeliveryURL($link->getAddress()), [
																			  'body'            => $args,
																			  'timeout'         => 10,
																			  'connect_timeout' => 10,
																		  ]
				);

				$result = json_decode($request->getBody(), true);
				if ($result['status'] === -1) {
					throw new PayloadDeliveryException($result['reason']);
				}

			} catch (Exception $e) {
				$this->miscService->log(
					'Issue while sending sharing frame to ' . $link->getAddress() . ' - '
					. $e->getMessage()
				);
			}
		}
	}


	/**
	 * generateHeaders()
	 *
	 * Generate new headers for the current Payload, and save them in the SharingFrame.
	 *
	 * @param SharingFrame $frame
	 */
	public function updateFrameWithCloudId(SharingFrame $frame) {
		$frame->setCloudId($this->serverHost);
		$this->circlesRequest->updateFrame($frame);
	}

}