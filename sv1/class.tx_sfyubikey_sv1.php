<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 - 2014 Torben Hansen <derhansen@gmail.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Service "Yubikey OTP Authentication" for the "sf_yubikey" extension.
 *
 * @author Torben Hansen <derhansen@gmail.com>
 * @package TYPO3
 * @subpackage tx_sfyubikey
 */
class tx_sfyubikey_sv1 extends tx_sv_authbase {

	/**
	 * Keeps class name.
	 *
	 * @var string
	 */
	public $prefixId = 'tx_sfyubikey_sv1';

	/**
	 * Keeps path to this script relative to the extension directory.
	 *
	 * @var string
	 */
	public $scriptRelPath = 'sv1/class.tx_sfyubikey_sv1.php';

	/**
	 * Keeps extension key.
	 *
	 * @var string
	 */
	public $extKey = 'sf_yubikey';

	/**
	 * Keeps extension configuration.
	 *
	 * @var mixed
	 */
	protected $extConf;

	/**
	 * Checks if service is available.
	 *
	 * @return boolean TRUE if service is available
	 */
	public function init () {
		$available = FALSE;
		$this->extConf = unserialize ($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['sf_yubikey']);
		if (isset($this->extConf['yubikeyEnableBE']) && (bool)$this->extConf['yubikeyEnableBE'] && TYPO3_MODE == 'BE') {
			$available = TRUE;
		} elseif (isset($this->extConf['yubikeyEnableFE']) && (bool)$this->extConf['yubikeyEnableFE'] && TYPO3_MODE == 'FE') {
			$available = TRUE;
		}

		return $available;
	}

	/**
	 * Authenticates the user by using Yubikey
	 *
	 * Will return one of following authentication status codes:
	 *  - 0 - authentication failure
	 *  - 100 - just go on. User is not authenticated but there is still no reason to stop
	 *  - 200 - the service was able to authenticate the user
	 *
	 * @param array $user Array containing the usersata
	 * @return int authentication statuscode, one of 0,100 and 200
	 */
	public function authUser (array $user) {
		// 0 means authentication failure
		$ret = 0;

		// Check if user Yubikey-Authentication is enabled for this user
		if (!$user['tx_sfyubikey_yubikey_enable']) {
			$this->writeDevLog (TYPO3_MODE . ' login using TYPO3 password authentication for user: ' . $user['username']);
			// Continue with TYPO3 authentication
			$ret = 100;
		} else {
			$this->writeDevLog (TYPO3_MODE . ' login using Yubikey authentication for user: ' . $user['username']);

			// Get Yubikey OTP
			$yubikeyOTP = t3lib_div::_GP ('t3-yubikey');
			$this->writeDevLog ('Yubikey: ' . $yubikeyOTP);
			$tempYubiKeyIds = t3lib_div::trimExplode(chr(10), $user['tx_sfyubikey_yubikey_id'], TRUE);
			$yubiKeyIds = array();
			foreach ($tempYubiKeyIds AS $tempYubiKeyId ) {
				$yubiKeyIds[] = substr($tempYubiKeyId, 0, 12);
			}
			// Check, if Yubikey-ID does match with users Yubikey-ID
			if ( in_array(substr ($yubikeyOTP, 0, 12), $yubiKeyIds) ) {
				$clientId = $this->extConf['yubikeyClientId'];
				$clientKey = $this->extConf['yubikeyClientKey'];
				$useSSL = $this->extConf['yubikeyUseHTTPS'] ? $this->extConf['yubikeyUseHTTPS'] : 0;

				$this->writeDevLog ('Yubikey config - ClientId: ' . $clientId);

				/**
				 * Check if PEAR is enabled in EM conf. Use the native
				 * client PHP otherwise.
				 */
				if ( intval($this->extConf['usePear'] === 1 ) ) {
					// Include Yubikey OTP Authentication Service
					$yubicoPear = stream_resolve_include_path('Auth/Yubico.php');
					if ($yubicoPear !== FALSE) {
						require_once 'Auth/Yubico.php';
					} else {
						require_once(t3lib_extMgm::extPath('sf_yubikey', 'lib/php-yubico/Yubico.php'));
					}

					// Initialize Yubikey Login
					$yubi = new Auth_Yubico((int)$clientId, $clientKey, $useSSL);
					$auth = $yubi->verify ($yubikeyOTP);

					if (PEAR::isError ($auth)) {
						$errorMessage = TYPO3_MODE . ' Login-attempt from %s (%s), username \'%s\', Yubikey not accepted!';
						$this->writelog (255, 3, 3, 1,
							$errorMessage,
							array(
								$this->authInfo['REMOTE_ADDR'],
								$this->authInfo['REMOTE_HOST'],
								$this->login['uname']
							)
						);
						$ret = 0;
					} else {
						// Continue to other auth-service(s)
						$ret = 100;
					}
					$this->writeDevLog ('Yubico Response:' . $yubi->getLastResponse ());
				} else {

					// Initialize Yubikey Verification
					$yubiKeyAuth = t3lib_div::makeInstance('Tx_SfYubiKey_YubiKeyAuth', $this->extConf );
					$authResult = $yubiKeyAuth->checkOtp( $yubikeyOTP );

					if ( $authResult === FALSE ) {
						$errorMessage = TYPO3_MODE . ' Login-attempt from %s (%s), username \'%s\', Yubikey not accepted!';
						$this->writelog (255, 3, 3, 1,
							$errorMessage,
							array(
								$this->authInfo['REMOTE_ADDR'],
								$this->authInfo['REMOTE_HOST'],
								$this->login['uname']
							)
						);
						$ret = 0;
					} else {
						// Continue to other auth-service(s)
						$ret = 100;
					}
				}
				// Class only available if using PEAR
				if ( intval($this->extConf['usePear'] === 1 ) ) {
					$this->writeDevLog('Yubico Response:' . $yubi->getLastResponse());
				}
			} else {
				if ($yubikeyOTP != '') {
					// Wrong Yubikey ID - Authentication failure
					$errorMessage = TYPO3_MODE . ' Login-attempt from %s (%s), username \'%s\', wrong Yubikey ID!';
					$ret = 0;
				} else {
					// Yubikey missing
					$errorMessage = TYPO3_MODE . ' Login-attempt from %s (%s), username \'%s\', Yubikey needed, but empty Yubikey supplied!';
					$ret = 0;
				}
				$this->writelog (255, 3, 3, 1,
					$errorMessage,
					array(
						$this->authInfo['REMOTE_ADDR'],
						$this->authInfo['REMOTE_HOST'],
						$this->login['uname']
					)
				);
			}
		}

		return $ret;
	}

	/**
	 * Writes to devlog if enabled
	 *
	 * @param string $message Message for devlog
	 * @return void
	 */
	private function writeDevLog ($message) {
		if ($this->extConf['devlog']) {
			t3lib_div::devLog ($message, 'tx_sfyubikey_sv1', 0);
		}
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/sf_yubikey/sv1/class.tx_sfyubikey_sv1.php']) {
    include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/sf_yubikey/sv1/class.tx_sfyubikey_sv1.php']);
}

?>