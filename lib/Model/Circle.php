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

namespace OCA\Circles\Model;

use OCA\Circles\Exceptions\CircleTypeNotValidException;
use OCA\Circles\Exceptions\FederatedCircleNotAllowedException;

class Circle extends BaseCircle implements \JsonSerializable {

	/** @var bool */
	private $fullJson = false;

	/** @var bool */
	private $lightJson = false;


	public function getTypeString() {
		switch ($this->getType()) {
			case self::CIRCLES_PERSONAL:
				return 'Personal';
			case self::CIRCLES_SECRET:
				return 'Secret';
			case self::CIRCLES_CLOSED:
				return 'Closed';
			case self::CIRCLES_PUBLIC:
				return 'Public';
			case self::CIRCLES_ALL:
				return 'All';
		}

		return 'none';
	}

	public function getTypeLongString() {
		return self::typeLongString($this->getType());
	}


	public function getInfo() {
		return $this->getTypeLongString();
	}


	public function jsonSerialize() {
		$json = array(
			'id'               => $this->getId(),
			'name'             => $this->getName(),
			'owner'            => $this->getOwner(),
			'user'             => $this->getViewer(),
			'group'            => $this->getGroupViewer(),
			'viewer'           => $this->getHigherViewer(),
			'description'      => $this->getDescription(),
			'settings'         => $this->getSettings(),
			'type'             => $this->getType(),
			'creation'         => $this->getCreation(),
			'type_string'      => $this->getTypeString(),
			'type_long_string' => $this->getTypeLongString(),
			'unique_id'        => $this->getUniqueId($this->fullJson),
			'members'          => $this->getMembers(),
			'groups'           => $this->getGroups(),
			'links'            => $this->getLinks()
		);

		if ($this->lightJson) {
			$json['members'] = [];
			$json['description'] = '';
			$json['links'] = [];
			$json['groups'] = [];
			$json['settings'] = [];
		}

		return $json;
	}


	public function getArray($full = false, $light = false) {
		$json = $this->getJson($full, $light);

		return json_decode($json, true);
	}


	public function getJson($full = false, $light = false) {
		$this->fullJson = $full;
		$this->lightJson = $light;
		$json = json_encode($this);
		$this->fullJson = false;
		$this->lightJson = false;

		return $json;
	}


	/**
	 * set all infos from an Array.
	 *
	 * @param $arr
	 *
	 * @deprecated
	 *
	 * @return $this
	 */
	public static function fromArray($arr) {
		$circle = new Circle();

		$circle->setId($arr['id']);
		$circle->setName($arr['name']);
		$circle->setUniqueId($arr['unique_id']);
		$circle->setDescription($arr['description']);
		if (key_exists('links', $arr)) {
			$circle->setLinks($arr['links']);
		}
		if (key_exists('settings', $arr)) {
			$circle->setSettings($arr['settings']);
		}
		$circle->setType($arr['type']);
		$circle->setCreation($arr['creation']);

		// TODO: 0.15.0 - remove condition is null
		if (key_exists('user', $arr) && $arr['user'] !== null) {
			$viewer = Member::fromArray($arr['user']);
			$viewer->setType(Member::TYPE_USER);
			$circle->setViewer($viewer);
		}

		if (key_exists('owner', $arr) && $arr['owner'] !== null) {
			$owner = Member::fromArray($arr['owner']);
			$owner->setType(Member::TYPE_USER);
			$circle->setOwner($owner);
		}

		return $circle;
	}


	/**
	 * @param $json
	 *
	 * @deprecated
	 * @return Circle
	 */
	public static function fromJSON($json) {
		return self::fromArray(json_decode($json, true));
	}


	/**
	 * @throws CircleTypeNotValidException
	 */
	public function cantBePersonal() {
		if ($this->getType() === self::CIRCLES_PERSONAL) {
			throw new CircleTypeNotValidException(
				$this->l10n->t("This feature is not available for personal circles")
			);
		}
	}


	/**
	 * @throws FederatedCircleNotAllowedException
	 */
	public function hasToBeFederated() {
		if ($this->getSetting('allow_links') !== 'true') {
			throw new FederatedCircleNotAllowedException(
				$this->l10n->t('The circle is not Federated')
			);
		}
	}

	/**
	 * @param $type
	 *
	 * @return string
	 */
	public static function typeLongString($type) {
		switch ($type) {
			case self::CIRCLES_PERSONAL:
				return 'Personal circle';
			case self::CIRCLES_SECRET:
				return 'Secret circle';
			case self::CIRCLES_CLOSED:
				return 'Closed circle';
			case self::CIRCLES_PUBLIC:
				return 'Public circle';
			case self::CIRCLES_ALL:
				return 'All circles';
		}

		return 'none';
	}


}


