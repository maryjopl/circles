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

namespace OCA\Circles\Controller;

use Exception;
use OC\AppFramework\Http;
use OCA\Circles\Api\v1\Circles;
use OCA\Circles\Exceptions\CircleDoesNotExistException;
use OCA\Circles\Exceptions\FederatedLinkCreationException;
use OCA\Circles\Exceptions\SharingFrameAlreadyExistException;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Model\SharingFrame;
use OCA\Circles\Service\CirclesService;
use OCA\Circles\Service\ConfigService;
use OCA\Circles\Service\FederatedService;
use OCA\Circles\Service\MembersService;
use OCA\Circles\Service\MiscService;
use OCA\Circles\Service\SharesService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;

class FederatedController extends BaseController {

	/** @var string */
	protected $userId;

	/** @var IL10N */
	protected $l10n;

	/** @var ConfigService */
	protected $configService;

	/** @var CirclesService */
	protected $circlesService;

	/** @var MembersService */
	protected $membersService;

	/** @var SharesService */
	protected $sharesService;

	/** @var FederatedService */
	protected $federatedService;

	/** @var MiscService */
	protected $miscService;


	/**
	 * requestedLink()
	 *
	 * Called when a remote circle want to create a link.
	 * The function check if it is possible first; then create a link-object
	 * and sent it to be saved in the database.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $apiVersion
	 * @param string $token
	 * @param string $uniqueId
	 * @param string $sourceName
	 * @param string $linkTo
	 * @param string $address
	 *
	 * @return DataResponse
	 * @throws FederatedLinkCreationException
	 */
	public function requestedLink($apiVersion, $token, $uniqueId, $sourceName, $linkTo, $address) {

		if ($uniqueId === '' || !$this->configService->isFederatedCirclesAllowed()) {
			return $this->federatedFail('federated_not_allowed');
		}

		try {
			Circles::compareVersion($apiVersion);
			$circle = $this->circlesService->infoCircleByName($linkTo);
			$link = new FederatedLink();
			$link->setToken($token)
				 ->setUniqueId($uniqueId)
				 ->setRemoteCircleName($sourceName)
				 ->setAddress($address);

			$this->federatedService->initiateLink($circle, $link);

			return $this->federatedSuccess(
				['status' => $link->getStatus(), 'uniqueId' => $circle->getUniqueId(true)], $link
			);
		} catch (CircleDoesNotExistException $e) {
			return $this->federatedFail('circle_does_not_exist');
		} catch (Exception $e) {
			return $this->federatedFail($e->getMessage());
		}
	}


	/**
	 * receiveFederatedDelivery()
	 *
	 * Note: this function will close the request mid-run from the client but will still
	 * running its process.
	 * Called by a remote circle to broadcast a Share item, the function will save the item
	 * in the database and broadcast it locally. A status response is sent to the remote to free
	 * the remote process before starting to broadcast the item to other federated links.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $apiVersion
	 * @param string $token
	 * @param string $uniqueId
	 * @param string $item
	 *
	 * @return DataResponse
	 */
	public function receiveFederatedDelivery($apiVersion, $token, $uniqueId, $item) {

		if ($uniqueId === '' || !$this->configService->isFederatedCirclesAllowed()) {
			return $this->federatedFail('federated_not_allowed');
		}

		try {
			Circles::compareVersion($apiVersion);
			$frame = SharingFrame::fromJSON($item);
			$this->federatedService->receiveFrame($token, $uniqueId, $frame);
		} catch (SharingFrameAlreadyExistException $e) {
			return $this->federatedSuccess();
		} catch (Exception $e) {
			return $this->federatedFail($e->getMessage());
		}

		$this->miscService->asyncAndLeaveClientOutOfThis('done');
		$this->broadcastService->broadcastFrame($frame);
		$this->federatedService->sendRemoteShare($frame);
		exit();
	}


	/**
	 * updateLink();
	 *
	 * Update the current status of a link, based on UniqueId and Token.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $apiVersion
	 * @param string $token
	 * @param string $uniqueId
	 * @param $status
	 *
	 * @return DataResponse
	 */
	public function updateLink($apiVersion, $token, $uniqueId, $status) {

		if ($uniqueId === '' || !$this->configService->isFederatedCirclesAllowed()) {
			return $this->federatedFail('federated_not_allowed');
		}

		try {
			Circles::compareVersion($apiVersion);
			$link = $this->federatedService->updateLinkFromRemote($token, $uniqueId, $status);
		} catch (Exception $e) {
			return $this->federatedFail($e->getMessage());
		}

		return $this->federatedSuccess(
			['status' => 1, 'link' => $link], $link
		);
	}


	/**
	 * send a positive response to a request with an array of data, and confirm
	 * the identity of the link with a token
	 *
	 * @param array $data
	 * @param FederatedLink $link
	 *
	 * @return DataResponse
	 */
	private function federatedSuccess(array $data = array(), FederatedLink $link = null) {

		if (!key_exists('status', $data)) {
			$data['status'] = 1;
		}

		if ($link !== null) {
			$data = array_merge($data, ['token' => $link->getToken(true)]);
		}

		return new DataResponse($data, Http::STATUS_OK);
	}


	/**
	 * send a negative response to a request, with a reason of the failure.
	 *
	 * @param string $reason
	 *
	 * @return DataResponse
	 */
	private function federatedFail($reason) {
		$this->miscService->log(2, 'federated fail: ' . $reason);

		return new DataResponse(
			[
				'status' => FederatedLink::STATUS_ERROR,
				'reason' => $reason
			],
			Http::STATUS_OK
		);
	}
}