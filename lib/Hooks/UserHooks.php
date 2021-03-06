<?php


namespace OCA\Circles\Hooks;

use OCA\Circles\AppInfo\Application;


class UserHooks {

	static protected function getController() {
		$app = new Application();

		return $app->getContainer()
				   ->query('UserEvents');
	}


	public static function onUserDeleted($params) {
		self::getController()
			->onUserDeleted($params);
	}


	public static function onGroupDeleted($params) {
		self::getController()
			->onGroupDeleted($params);
	}

}

