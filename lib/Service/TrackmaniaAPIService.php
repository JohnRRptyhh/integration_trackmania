<?php
/**
 * Nextcloud - Trackmania
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Trackmania\Service;

use Datetime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Trackmania\AppInfo\Application;
use OCA\Trackmania\Controller\ConfigController;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\IL10N;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class TrackmaniaAPIService {

	private IClient $client;

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		IClientService $clientService
	) {
		$this->client = $clientService->newClient();
	}

	public function getFavoritesWithPosition(string $userId): array {
		$allFavs = $this->getAllFavorites($userId);

		//// METHOD 1: get from top on each map (slow)
		//foreach ($allFavs as $k => $fav) {
		//	$pos = $this->getMyPositionFromTop($userId, $fav['uid']);
		//	$allFavs[$k]['myPosition'] = $pos;
		//}

		$allMyPbs = $this->getMapRecords($userId);
		$allMyPbsByMapId = [];
		foreach ($allMyPbs as $pb) {
			$allMyPbsByMapId[$pb['mapId']] = $pb;
		}

		//// METHOD 2: one by one
		//foreach ($allFavs as $k => $fav) {
		//	$mapId = $fav['mapId'];
		//	if (isset($allMyPbsByMapId[$mapId])) {
		//		$time = $allMyPbsByMapId[$mapId]['recordScore']['time'];
		//		$allFavs[$k]['myRecordTime'] = $time;
		//		$allFavs[$k]['myRecordPosition'] = $this->getScorePosition($userId, $fav['uid'], $time);
		//	} else {
		//		$allFavs[$k]['myRecordTime'] = null;
		//		$allFavs[$k]['myRecordPosition'] = null;
		//	}
		//}


		// METHOD 3: all at once
		$allMyPbTimesByMapUid = [];
		foreach ($allFavs as $fav) {
			$time = $allMyPbsByMapId[$fav['mapId']]['recordScore']['time'];
			if ($time !== null) {
				$allMyPbTimesByMapUid[$fav['uid']] = $time;
			}
		}
		$positionsByMapUid = $this->getScorePositions($userId, $allMyPbTimesByMapUid);
		$results = [];
		foreach ($allFavs as $k => $fav) {
			$oneResult = [
				'record' => $allMyPbsByMapId[$fav['mapId']],
				'mapInfo' => $fav,
			];
			$mapUid = $fav['uid'];
			if (isset($allMyPbTimesByMapUid[$mapUid])) {
				$oneResult['recordPosition'] = $positionsByMapUid[$mapUid];
			} else {
				$oneResult['recordPosition'] = null;
			}
			$results[] = $oneResult;
		}

		return $results;
	}

	public function getAllMapsWithPosition(string $userId): array {
		$pbs = $this->getMapRecords($userId);
		$pbTimesByMapId = [];
		foreach ($pbs as $pb) {
			$pbTimesByMapId[$pb['mapId']] = $pb['recordScore']['time'];
		}
		$mapInfos = $this->getMapInfo($userId, array_keys($pbTimesByMapId));
		$allMyPbTimesByMapUid = [];
		foreach ($mapInfos as $mapInfo) {
			$mapInfoByMapId[$mapInfo['mapId']] = $mapInfo;
			$time = $pbTimesByMapId[$mapInfo['mapId']];
			if ($time !== null) {
				$allMyPbTimesByMapUid[$mapInfo['mapUid']] = $time;
			}
		}
		$positionsByMapUid = $this->getScorePositions($userId, $allMyPbTimesByMapUid);
		$results = [];
		foreach ($pbs as $k => $pb) {
			$oneResult = [
				'record' => $pb,
			];
			$mapId = $pb['mapId'];
			if (isset($mapInfoByMapId[$mapId])) {
				$mapUid = $mapInfoByMapId[$mapId]['mapUid'];
				$oneResult['mapInfo'] = $mapInfoByMapId[$mapId];
				if (isset($allMyPbTimesByMapUid[$mapUid])) {
					$oneResult['recordPosition'] = $positionsByMapUid[$mapUid];
				} else {
					$oneResult['recordPosition'] = null;
				}
			}
			$results[] = $oneResult;
		}

		return $results;
	}

	public function getMapInfo(string $userId, ?array $mapIds = null, ?array $mapUids = null): array {
		if ($mapIds !== null) {
			$paramName = 'mapIdList';
			$itemList = $mapIds;
		} elseif ($mapUids !== null) {
			$paramName = 'mapUidList';
			$itemList = $mapUids;
		} else {
			return [];
		}

		$mapInfos = [];
		$offset = 0;
		while ($offset < count($itemList)) {
			$oneRequestItemList = [];
			$stringListLength = 0;
			while ($stringListLength < 7000 && $offset < count($itemList)) {
				$oneRequestItemList[] = $itemList[$offset];
				$stringListLength += strlen($itemList[$offset]) + 1;
				$offset++;
			}
			$params = [
				$paramName => implode(',', $oneRequestItemList),
			];
			// max URI length: 8220 chars
			$oneChunk = $this->request($userId, Application::AUDIENCE_CORE, 'maps/', $params);
			if (!isset($oneChunk['error'])) {
				$mapInfos = array_merge($mapInfos, $oneChunk);
			}
		}
		return $mapInfos;
	}

	public function getAllFavorites(string $userId): array {
		$maps = [];
		$chunkSize = 200;
		$favs = $this->getFavoriteMaps($userId, 0, $chunkSize);
		if (isset($favs['itemCount']) && is_numeric($favs['itemCount'])) {
			$nbMaps = $favs['itemCount'];
			$maps = array_merge($maps, $favs['mapList']);
			While (count($maps) < $nbMaps || isset($favs['error'])) {
				$favs = $this->getFavoriteMaps($userId, count($maps), $chunkSize);
				$maps = array_merge($maps, $favs['mapList']);
			}
		}
		return $maps;
	}

	public function getFavoriteMaps(string $userId, int $offset = 0, int $limit = 20): array {
		$params = [
			'offset' => $offset,
			'length' => $limit,
		];
		return $this->request($userId, Application::AUDIENCE_LIVE, 'map/favorite', $params);
	}

	/**
	 * @param string $userId
	 * @param array|null $accountIds connected account is used if null
	 * @param array|null $mapIds all records are retrieved if null (only works with the connected account)
	 * @return array|string[]
	 * @throws PreConditionNotMetException
	 */
	public function getMapRecords(string $userId, ?array $accountIds = null, ?array $mapIds = null): array {
		$prefix = Application::AUDIENCES[Application::AUDIENCE_CORE]['token_config_key_prefix'];
		$accountIdList = $accountIds === null
			? $this->config->getUserValue($userId, Application::APP_ID, $prefix . 'account_id')
			: implode(',', $accountIds);
		$params = [
			'accountIdList' => $accountIdList,
//			'seasonId' => '???',
		];
		if ($mapIds !== null) {
			$params['mapIdList'] = implode(',', $mapIds);
		}

		// max URI length: 8220 chars
		return $this->request($userId, Application::AUDIENCE_CORE, 'mapRecords/', $params);
	}

	public function getScorePositions(string $userId, array $scoresByMapUid): array {
		$positionsByMapUid = [];
		$uids = array_keys($scoresByMapUid);
		file_put_contents('/tmp/uids', json_encode($scoresByMapUid));
		$chunkSize = 50;
		$offset = 0;
		while ($offset < count($uids)) {
			$uidsToLook = array_slice($uids, $offset, $chunkSize);
			$params = [
				'maps' => [],
			];
			foreach ($uidsToLook as $uid) {
				$params['maps'][] = [
					'mapUid' => $uid,
					'groupUid' => 'Personal_Best',
				];
			}
			$getParams = array_map(function($uid) use ($scoresByMapUid) {
				return 'scores[' . $uid . ']=' . $scoresByMapUid[$uid];
			}, $uidsToLook);
			$positions = $this->request($userId, Application::AUDIENCE_LIVE, 'leaderboard/group/map?' . implode('&', $getParams), $params, 'POST');
			file_put_contents('/tmp/a' . $offset, json_encode($positions));
			file_put_contents('/tmp/b' . $offset, json_encode($getParams));
			if (!isset($positions['error'])) {
				foreach ($positions as $position) {
					$positionsByMapUid[$position['mapUid']] = $position;
				}
			}
			$offset = $offset + $chunkSize;
		}

		return $positionsByMapUid;
	}

	public function getScorePosition(string $userId, string $mapUid, int $score): array {
		$params = [
			'maps' => [
				[
					'mapUid' => $mapUid,
					'groupUid' => 'Personal_Best',
				],
			],
		];
		return $this->request($userId, Application::AUDIENCE_LIVE, 'leaderboard/group/map?scores['.$mapUid.']='.$score, $params, 'POST');
	}

	public function getMapTop(string $userId, string $mapUid, int $offset = 0, int $length = 20, bool $onlyWorld = true): array	{
		$params = [
			'onlyWorld' => $onlyWorld ? 'true' : 'false',
			'offset' => $offset,
			'length' => $length,
		];
		return $this->request($userId, Application::AUDIENCE_LIVE, 'leaderboard/group/Personal_Best/map/' . $mapUid . '/top', $params);
	}

	/**
	 * Works but is slow
	 *
	 * @param string $userId
	 * @param string $mapUid
	 * @return array|null
	 */
	public function getMyPositionFromTop(string $userId, string $mapUid): ?array {
		$chunkSize = 100;
		$prefix = Application::AUDIENCES[Application::AUDIENCE_LIVE]['token_config_key_prefix'];
		$accountId = $this->config->getUserValue($userId, Application::APP_ID, $prefix . 'account_id');
		$pos = null;
		$offset = 0;
		while ($pos === null && $offset < 10000) {
			error_log('getMyPosition[' . $offset . ']');
			$top = $this->getMapTop($userId, $mapUid, $offset, $chunkSize);
			if (isset($top['error'])) {
				return null;
			}
			$pos = $this->findMyPosition($accountId, $top);
			$offset = $offset + $chunkSize;
		}
		return $pos;
	}

	public function findMyPosition(string $accountId, array $top): ?array {
		if (isset($top['tops']) && is_array($top['tops']) && count($top['tops']) === 1) {
			$positions = $top['tops'][0]['top'];
			foreach ($positions as $position) {
				if ($position['accountId'] === $accountId) {
					return $position;
				}
			}
		}
		return null;
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]
	 * @throws PreConditionNotMetException
	 */
	public function request(
		string $userId, string $audience, string $endPoint, array $params = [], string $method = 'GET', bool $jsonResponse = true
	) {
		$this->checkTokenExpiration($userId, $audience);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'token');
		try {
			$url = Application::AUDIENCES[$audience]['base_url'] . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'nadeo_v1 t=' . $accessToken,
					'Content-Type' => 'application/json',
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);

					$url .= '?' . $paramsContent;
				} else {
					$options['json'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return $body;
				}
			}
		} catch (ServerException | ClientException $e) {
			$body = $e->getResponse()->getBody();
			$this->logger->warning('API error : ' . $body, ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('API error', ['exception' => $e, 'app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function checkTokenExpiration(string $userId, string $audience): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId, $audience);
			}
		}
	}

	/**
	 * @param string $userId
	 * @return bool
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function refreshToken(string $userId, string $audience): bool {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'refresh_token');
		if (!$refreshToken) {
			$this->logger->error('No refresh token found', ['app' => Application::APP_ID]);
			return false;
		}
		try {
			$url = Application::TOKEN_REFRESH_URL;
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Authorization' => 'nadeo_v1 t=' . $refreshToken,
				],
			];
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				error_log('refresh failed:' . $respCode);
				return false;
			} else {
				$bodyArray = json_decode($body, true);
//				error_log('refresh BODY2 KEYS:' . implode('||', array_keys($bodyArray)));
				if (isset($bodyArray['accessToken'], $bodyArray['refreshToken'])) {
					$this->logger->info('access token successfully refreshed', ['app' => Application::APP_ID]);
					$accessToken = $bodyArray['accessToken'];
					$refreshToken = $bodyArray['refreshToken'];
					$this->config->setUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'token', $accessToken);
					$this->config->setUserValue($userId, Application::APP_ID, Application::AUDIENCES[$audience]['token_config_key_prefix'] . 'refresh_token', $refreshToken);

					$prefix = Application::AUDIENCES[$audience]['token_config_key_prefix'];
					$decodedToken = ConfigController::decodeToken($accessToken);
					$expiresAt = $decodedToken['exp'];
					$this->config->setUserValue($userId, Application::APP_ID, $prefix . 'token_expires_at', $expiresAt);
					return true;
				}
				return false;
			}
		} catch (Exception $e) {
			error_log('refresh exception '.$e->getMessage());
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it. '
				. $result['error'] . ' '
				. $result['error_description'] ?? '[no error description]',
				['app' => Application::APP_ID]
			);
			return false;
		}
	}

	/**
	 * @param string $login
	 * @param string $password
	 * @return array
	 */
	public function login(string $login, string $password): array {
		try {
			$url = 'https://public-ubiservices.ubi.com/v3/profiles/sessions';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
					'Ubi-AppId' => '86263886-327a-4328-ac69-527f0d20a237',
					'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
				],
			];
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				$bodyArray = json_decode($body, true);
				error_log('BODY KEYS:' . implode('||', array_keys($bodyArray)));
				error_log('BODY ticket:' . $bodyArray['ticket']);
				if (isset($bodyArray['ticket'], $bodyArray['userId'], $bodyArray['nameOnPlatform'])) {
					foreach (Application::AUDIENCES as $audienceKey => $v) {
						$tokens = $this->login2($bodyArray['ticket'], $audienceKey);
						if (isset($tokens['accessToken'], $tokens['refreshToken'])) {
							$bodyArray[$audienceKey] = $tokens;
						}
					}
					return $bodyArray;
				}
				return ['error' => $this->l10n->t('Error during login1111')];
			}
		} catch (Exception $e) {
			$this->logger->warning('login error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	public function login2(string $ticket, string $audience): array {
		try {
			$url = 'https://prod.trackmania.core.nadeo.online/v2/authentication/token/ubiservices';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
					'Authorization' => 'ubi_v1 t=' . $ticket,
				],
				'json' => [
					'audience' => $audience,
//					'audience' => 'NadeoLiveServices',
//					'audience' => 'NadeoServices',
//					'audience' => 'NadeoClubServices',
				],
			];
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				error_log('login2 failed:' . $respCode);
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				$bodyArray = json_decode($body, true);
				error_log('BODY2 KEYS:' . implode('||', array_keys($bodyArray)));
				if (isset($bodyArray['accessToken'], $bodyArray['refreshToken'])) {
					return $bodyArray;
				}
				return ['error' => $this->l10n->t('Error during login22222')];
			}
		} catch (Exception $e) {
			error_log('login2 exception '.$e->getMessage());
			$this->logger->warning('login error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
