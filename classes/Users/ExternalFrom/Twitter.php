<?php

/**
 * @module Users
 */

/**
 * Class representing an X (Twitter) user, authenticated via OAuth 2.0 (PKCE).
 *
 * Modeled on Users_ExternalFrom_Facebook rather than Telegram: because X uses a
 * per-user OAuth token (not a global bot token), the token lives on this row and
 * this row does the user-context API work ("me", DMs). The Twitter class keeps
 * the app-context read methods (byUsernames, timelines) and offers thin
 * conveniences (Twitter::getMe, etc.) that resolve a row and call it.
 *
 * The OAuth round trip happens in the Users/oauth handler (popup). By the time
 * this adapter's authenticate() runs on the opener, the handler has exchanged the
 * code, resolved the xid, and staged the tokens in a server-only Users_ExternalFrom
 * row keyed (twitter, appId, xid). This adapter locates that staged row from the
 * intent, returns a fresh ExternalFrom (userId unset, like Facebook), and lets
 * Users::authenticate own the DB writes.
 *
 * @class Users_ExternalFrom_Twitter
 * @extends Users_ExternalFrom
 */
class Users_ExternalFrom_Twitter extends Users_ExternalFrom implements Users_ExternalFrom_Interface
{
	/**
	 * Builds a Users_ExternalFrom_Twitter from a completed intent.
	 * It is Users::authenticate()'s job to stamp userId and save it.
	 * @method authenticate
	 * @static
	 * @param {string} [$appId=Q::app()] Internal or external app id
	 * @param {boolean} [$setCookie=true] Unused; kept for signature parity
	 * @param {boolean} [$longLived=true] Unused; kept for signature parity
	 * @return {Users_ExternalFrom_Twitter|null} null if nothing is authenticated
	 */
	static function authenticate($appId = null, $setCookie = true, $longLived = true)
	{
		list($appId, $appInfo) = Users::appInfo('twitter', $appId);

		// The opener carries the intent token, either flattened from
		// Q.Users.authPayload.twitter or as a plain request field.
		$token = null;
		$payload = Q_Request::special('Users.authPayload.twitter', null);
		if (is_array($payload)) {
			$token = Q::ifset($payload, 'intent', null);
		} else if (is_string($payload) && $payload) {
			$token = $payload;
		}
		if (!$token) {
			$token = Q_Request::get('intent', null);
		}
		if (!$token) {
			return null;
		}

		$intent = Users_Intent::fromToken($token);
		if (!$intent || empty($intent->completedTime)) {
			return null; // user canceled, or flow not finished
		}

		$xid = $intent->getInstruction('xid');
		if (!$xid) {
			$results = $intent->getInstruction('results');
			$xid = is_array($results) ? Q::ifset($results, 'xid', null) : null;
		}
		if (!$xid) {
			return null;
		}

		// Read the staged row (tokens + profile subset), then remove it so that
		// Users::authenticate inserts a clean row with the correct userId,
		// exactly like the Facebook/Telegram adapters do.
		$staged = new Users_ExternalFrom_Twitter();
		$staged->platform = 'twitter';
		$staged->appId    = $appId;
		$staged->xid      = $xid;
		if (!$staged->retrieve()) {
			return null;
		}
		$accessToken = $staged->accessToken;
		$expires     = $staged->expires;
		$extra       = isset($staged->extra) ? $staged->extra : null;

		Users_ExternalFrom::delete()->where(array(
			'platform' => 'twitter',
			'appId'    => $appId,
			'xid'      => $xid
		))->execute();

		$ef = new Users_ExternalFrom_Twitter();
		// note that $ef->userId is intentionally not set
		$ef->platform     = 'twitter';
		$ef->appId        = $appId;
		$ef->xid          = $xid;
		$ef->responseType = 'code';
		$ef->accessToken  = $accessToken;
		$ef->expires      = $expires;
		if ($extra) {
			// carries refreshToken + the small profile subset (no columns of their own)
			$ef->extra = is_string($extra) ? $extra : Q::json_encode($extra, Q::JSON_FORCE_OBJECT);
		}
		return $ef;
	}

	/**
	 * Identity-resolution hook called by the Users/oauth handler in phase 2: turn a
	 * freshly minted access token into the user's profile (and xid). There is no
	 * stored row or logged-in user yet, so the token is supplied directly; we just
	 * wrap it in a transient row and call the instance method.
	 * @method fetchMe
	 * @static
	 * @param {string} $appId Internal app id (unused; kept for the generic hook signature)
	 * @param {string} $accessToken
	 * @return {array|null}
	 */
	static function fetchMe($appId, $accessToken)
	{
		$ef = new Users_ExternalFrom_Twitter();
		$ef->accessToken = $accessToken;
		return $ef->getMe();
	}

	/**
	 * GET /2/users/me using THIS row's user access token. The user-context
	 * workhorse; Twitter::getMe() and the OAuth login path both reach it.
	 * @method getMe
	 * @param {array} [$fields] user.fields to request
	 * @return {array|null} e.g. { id, name, username, profile_image_url }
	 */
	function getMe($fields = array('profile_image_url', 'name', 'username'))
	{
		if (!$this->accessToken) {
			return null;
		}
		$params = 'user.fields=' . implode(',', $fields);
		$response = Q_Utils::get("https://api.x.com/2/users/me?$params",
			Q_Config::get('Twitter', 'userAgent', 'Qbix', null), [], array(
				'Authorization' => 'Bearer ' . $this->accessToken,
				'Accept'        => 'application/json',
			), 30, false
		);
		$arr = Q::json_decode($response, true);
		return Q::ifset($arr, 'data', null);
	}

	/**
	 * This user's profile, memoized on the instance. Prefers the subset persisted
	 * in `extra` during the OAuth flow; falls back to a live getMe().
	 * @method me
	 * @return {array}
	 */
	function me()
	{
		$cached = $this->get('me', null);
		if ($cached !== null) {
			return $cached;
		}
		$me = $this->getExtra('profile', null);
		if (!$me) {
			$me = $this->getMe();
		}
		$me = is_array($me) ? $me : array();
		$this->set('me', $me);
		return $me;
	}

	/**
	 * Profile icon urls keyed by size. Uses the Twitter class's pure transform.
	 * @method icon
	 * @param {array} [$sizes=Q_Image::getSizes('Users/icon')]
	 * @param {string} [$suffix='']
	 * @return {array}
	 */
	function icon($sizes = null, $suffix = '')
	{
		return Twitter::userIcon($this->me(), $sizes, $suffix);
	}

	/**
	 * Import profile fields gathered during the OAuth flow. Fills platformUserData.
	 * @method import
	 * @param {array} [$fieldNames]
	 * @return {array}
	 */
	function import($fieldNames = null)
	{
		$me = $this->me();
		if (!$me) {
			return array();
		}
		$result = array();
		if (!empty($me['username'])) {
			$result['username'] = $me['username'];
		}
		if (!empty($me['name'])) {
			$result['name'] = $me['name'];
			$result['displayName'] = $me['name'];
		}
		$iconUrl = Twitter::userIconUrl($me);
		if ($iconUrl) {
			$result['icon'] = $iconUrl;
		}
		Users::$cache['platformUserData'] = array('twitter' => $me);
		return $result;
	}

	/**
	 * X has no role-based contact queries here; return our single xid per roleId.
	 * @method fetchXids
	 * @param {array} $roleIds
	 * @param {array} [$options=array()]
	 * @return {array}
	 */
	public function fetchXids(array $roleIds, array $options = array())
	{
		$xid = $this->xid;
		if (!$xid) {
			return array();
		}
		$results = array();
		foreach ($roleIds as $roleId) {
			$label = "twitter_{$this->appId}/{$roleId}";
			$results[$label] = array($xid);
		}
		return $results;
	}

	/**
	 * Sends a direct message via the X API v2 using THIS row's user token — a
	 * user-context call, so it lives on the row (not the Twitter class).
	 *
	 * NOTE: it sends with this row's token. When the row is the recipient, that is
	 * the user messaging themselves; an app->user notification would instead need
	 * the app account's token, which is a different resolution. Left best-effort
	 * until that policy is settled.
	 * @method handlePushNotification
	 * @param {array} $notification
	 * @param {array} [$options=array()]
	 * @return {boolean}
	 */
	public function handlePushNotification($notification, $options = array())
	{
		$xid = $this->xid;
		if (!$xid || !$this->accessToken) {
			return false;
		}

		$alert = Q::ifset($notification, 'alert', null);
		$text = is_string($alert)
			? $alert
			: (is_array($alert) && !empty($alert['body']) ? $alert['body'] : '');
		if (!$text) {
			return false;
		}

		$href = Q::ifset($notification, 'href', '');
		if ($href && $href[0] === '/') {
			$href = Q_Config::get(array('Users', 'apps', 'baseUrl'), '') . $href;
		}
		if ($href) {
			$text = rtrim($text) . "\n" . $href;
		}

		$endpoint = 'https://api.x.com/2/dm_conversations/with/'
			. rawurlencode($xid) . '/messages';
		try {
			$response = Q_Utils::post($endpoint, Q::json_encode(array('text' => $text)),
				Q_Config::get('Twitter', 'userAgent', 'Qbix', null), [], array(
					'Authorization: Bearer ' . $this->accessToken,
					'Content-Type: application/json',
					'Accept: application/json',
				), 30, false
			);
			$arr = Q::json_decode($response, true);
			if (!empty($arr['errors'])) {
				$msg = strtolower(Q::ifset($arr['errors'], 0, 'message', ''));
				$e = new Exception($msg);
				if (strpos($msg, 'forbidden') !== false
				|| strpos($msg, 'not authorized') !== false
				|| strpos($msg, 'invalid') !== false) {
					$e->rejected = true;
				}
				throw $e;
			}
			return !empty($arr['data']['dm_conversation_id']);
		} catch (Exception $e) {
			$msg = strtolower($e->getMessage());
			if (strpos($msg, 'rate') !== false || strpos($msg, '429') !== false) {
				$e->rateLimited = true;
			}
			return false;
		}
	}
}
