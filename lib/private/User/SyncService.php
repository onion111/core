<?php
/**
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\User;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\User\IProvidesEMailBackend;
use OCP\User\IProvidesExtendedSearchBackend;
use OCP\User\IProvidesQuotaBackend;
use OCP\UserInterface;

/**
 * Class SyncService
 *
 * All users in a user backend are transferred into the account table.
 * In case a user is know all preferences will be transferred from the table
 * oc_preferences into the account table.
 *
 * @package OC\User
 */
class SyncService {

	/** @var UserInterface */
	private $backend;
	/** @var AccountMapper */
	private $mapper;
	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;
	/** @var string */
	private $backendClass;

	/**
	 * SyncService constructor.
	 *
	 * @param AccountMapper $mapper
	 * @param UserInterface $backend
	 * @param IConfig $config
	 * @param ILogger $logger
	 */
	public function __construct(AccountMapper $mapper,
								UserInterface $backend,
								IConfig $config,
								ILogger $logger) {
		$this->mapper = $mapper;
		$this->backend = $backend;
		$this->backendClass = get_class($backend);
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * @param \Closure $callback is called for every user to allow progress display
	 * @return array
	 */
	public function getNoLongerExistingUsers(\Closure $callback) {
		// detect no longer existing users
		$toBeDeleted = [];
		$this->mapper->callForAllUsers(function (Account $a) use (&$toBeDeleted, $callback) {
			if ($a->getBackend() == $this->backendClass) {
				if (!$this->backend->userExists($a->getUserId())) {
					$toBeDeleted[] = $a->getUserId();
				}
			}
			$callback($a);
		}, '', false);

		return $toBeDeleted;
	}

	/**
	 * @param SyncServiceCallback $callback methods are called for every user to progress display
	 */
	public function run(SyncServiceCallback $callback) {
		$limit = 500;
		$offset = 0;
		do {
			$users = $this->backend->getUsers('', $limit, $offset);

			foreach ($users as $uid) {
				try {
					$this->syncUser($uid, $callback);
				} catch (BackendMismatchException $ex) {
					$callback->onBackendMismatchException($ex);
					continue;
				}
			}

			$offset += $limit;
		} while(count($users) >= $limit);
	}

	/**
	 * update existing and insert new users
	 * @param string $uid user ids to sync
	 * @param SyncServiceCallback $callback methods are called for every user to progress display
	 * @throws BackendMismatchException if a uid is already used by another backend
	 */
	public function syncUser($uid, SyncServiceCallback $callback) {
			$callback->startSync($uid);
			try {
				$account = $this->mapper->getByUid($uid);
				if ($account->getBackend() !== $this->backendClass) {
					throw new BackendMismatchException($account, $this->backendClass);
				}
				$account = $this->setupAccount($account, $uid);
				$this->mapper->update($account);
				// clean the user's preferences
				$this->cleanPreferences($uid); // TODO always?
				$callback->endUpdated($account);
			} catch (DoesNotExistException $ex) {
				$account = $this->createNewAccount($uid);
				$this->setupAccount($account, $uid);
				/** @var Account $account */
				$this->mapper->insert($account); // will the id be set in this account or do we need the return value?
				// clean the user's preferences
				$this->cleanPreferences($uid); // TODO always?
				$callback->endCreated($account);
			}
	}

	/**
	 * @param Account $a
	 * @param string $uid
	 * @return Account
	 */
	public function setupAccount(Account $a, $uid) {
		list($hasKey, $value) = $this->readUserConfig($uid, 'core', 'enabled');
		if ($hasKey) {
			$a->setState(($value === 'true') ? Account::STATE_ENABLED : Account::STATE_DISABLED);
		}
		list($hasKey, $value) = $this->readUserConfig($uid, 'login', 'lastLogin');
		if ($hasKey) {
			$a->setLastLogin($value);
		}
		if ($this->backend instanceof IProvidesEMailBackend) {
			$a->setEmail($this->backend->getEMailAddress($uid));
		} else {
			list($hasKey, $value) = $this->readUserConfig($uid, 'settings', 'email');
			if ($hasKey) {
				$a->setEmail($value);
			}
		}
		if ($this->backend instanceof IProvidesQuotaBackend) {
			$quota = $this->backend->getQuota($uid);
			if ($quota !== null) {
				$a->setQuota($quota);
			}
		} else {
			list($hasKey, $value) = $this->readUserConfig($uid, 'files', 'quota');
			if ($hasKey) {
				$a->setQuota($value);
			}
		}
		if ($this->backend->implementsActions(\OC_User_Backend::GET_HOME)) {
			$home = $this->backend->getHome($uid);
			if (!is_string($home) || substr($home, 0, 1) !== '/') {
				$home = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . "/$uid";
				$this->logger->warning(
					"User backend {$this->backendClass} provided no home for <$uid>, using <$home>.",
					['app' => self::class]
				);
			}
			$a->setHome($home);
		}
		if ($this->backend->implementsActions(\OC_User_Backend::GET_DISPLAYNAME)) {
			$a->setDisplayName($this->backend->getDisplayName($uid));
		}
		// Check if backend supplies an additional search string
		if ($this->backend instanceof IProvidesExtendedSearchBackend) {
			$a->setSearchTerms($this->backend->getSearchTerms($uid));
		}
		return $a;
	}

	private function createNewAccount($uid) {
		$a = new Account();
		$a->setUserId($uid);
		$a->setState(Account::STATE_ENABLED);
		$a->setBackend(get_class($this->backend));
		return $a;
	}

	/**
	 * @param string $uid
	 * @param string $app
	 * @param string $key
	 * @return array
	 */
	private function readUserConfig($uid, $app, $key) {
		$keys = $this->config->getUserKeys($uid, $app);
		if (in_array($key, $keys)) {
			$enabled = $this->config->getUserValue($uid, $app, $key);
			return [true, $enabled];
		}
		return [false, null];
	}

	/**
	 * These attributes are now stored in the appconfig table
	 * @param string $uid
	 */
	private function cleanPreferences($uid) {
		// FIXME use a single query to delete these from the preferences table
		$this->config->deleteUserValue($uid, 'core', 'enabled');
		$this->config->deleteUserValue($uid, 'login', 'lastLogin');
		$this->config->deleteUserValue($uid, 'settings', 'email');
		$this->config->deleteUserValue($uid, 'files', 'quota');
	}

}
