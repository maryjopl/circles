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

use OC\AppFramework\Http;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Service\FederatedService;
use OCA\Circles\Service\CirclesService;
use OCA\Circles\Service\ConfigService;
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
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param $token
	 * @param $uniqueId
	 * @param $sourceName
	 * @param $linkTo
	 * @param $address
	 *
	 * @return DataResponse
	 */
	public function requestedLink($token, $uniqueId, $sourceName, $linkTo, $address) {

		if (!$this->configService->isFederatedAllowed()) {
			return $this->federatedFail('federated_not_allowed');
		}

		$circle = $this->circlesService->infoCircleByName($linkTo);
		if ($circle === null) {
			return $this->federatedFail('circle_does_not_exist');
		}

		if ($circle->getUniqueId() === $uniqueId) {
			return $this->federatedFail('duplicate_unique_id');
		}

		if ($this->federatedService->getLink($circle->getId(), $uniqueId) !== null) {
			return $this->federatedFail('duplicate_link');
		}

		$link = new FederatedLink();
		$link->setToken($token)
			 ->setUniqueId($uniqueId)
			 ->setRemoteCircleName($sourceName)
			 ->setAddress($address);

		if ($this->federatedService->initiateLink($circle, $link)) {
			return $this->federatedSuccess(
				['status' => $link->getStatus(), 'uniqueId' => $circle->getUniqueId()], $link
			);
		} else {
			return $this->federatedFail('link_failed');
		}
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function broadcastItem() {

		$this->miscService->log("BroadItem start");

		// We don't want to keep the connection with the client up and running
		// as he might have others things to do
		$this->asyncAndLeaveClientOutOfThis('done');

		sleep(15);
		$this->miscService->log("BroadItem end");
		exit();
	}

	/**
	 * Hacky way to async the rest of the process without keeping client on hold.
	 *
	 * @param string $result
	 */
	private function asyncAndLeaveClientOutOfThis($result = '') {
		if (ob_get_contents() !== false) {
			ob_end_clean();
		}

		header("Connection: close");
		ignore_user_abort();
		ob_start();
		echo($result);
		$size = ob_get_length();
		header("Content-Length: $size");
		ob_end_flush();
		flush();
	}

	/**
	 * @param array $data
	 * @param FederatedLink $link
	 *
	 * @return DataResponse
	 */
	private function federatedSuccess($data, $link) {
		return new DataResponse(
			array_merge($data, ['token' => $link->getToken()]), Http::STATUS_OK
		);

	}

	/**
	 * @param $reason
	 *
	 * @return DataResponse
	 */
	private function federatedFail($reason) {
		return new DataResponse(
			[
				'status' => FederatedLink::STATUS_ERROR,
				'reason' => $reason
			],
			Http::STATUS_OK
		);
	}
}