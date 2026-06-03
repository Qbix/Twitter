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

	// -------------------------------------------------------------------------
	// User-context write/action API (X API v2), all using THIS row's user token.
	// For self-scoped endpoints the authenticating user's id is $this->xid.
	// Twitter::<method>($appId, ..., $userId) conveniences resolve a row and call
	// these. Some endpoints are tier-gated (e.g. likes create and bookmarks need a
	// paid API tier as of 2025); they return X's error envelope unchanged.
	// -------------------------------------------------------------------------

	/**
	 * Create a Post (optionally a reply, quote, with media or a poll).
	 * @method postTweet
	 * @param {string} $text
	 * @param {array} [$options]
	 *   reply_to, exclude_reply_user_ids, quote_tweet_id, media_ids, tagged_user_ids,
	 *   poll_options, poll_duration_minutes, reply_settings, for_super_followers_only,
	 *   community_id, body (raw merge escape hatch)
	 * @return {array|null} decoded { data: { id, text }, ... }
	 */
	function postTweet($text, $options = array())
	{
		$body = array();
		if (strlen($text)) {
			$body['text'] = $text;
		}
		if (!empty($options['reply_to'])) {
			$body['reply'] = array('in_reply_to_tweet_id' => $options['reply_to']);
			if (!empty($options['exclude_reply_user_ids'])) {
				$body['reply']['exclude_reply_user_ids'] = (array)$options['exclude_reply_user_ids'];
			}
		}
		if (!empty($options['quote_tweet_id'])) {
			$body['quote_tweet_id'] = $options['quote_tweet_id'];
		}
		if (!empty($options['media_ids'])) {
			$body['media'] = array('media_ids' => (array)$options['media_ids']);
			if (!empty($options['tagged_user_ids'])) {
				$body['media']['tagged_user_ids'] = (array)$options['tagged_user_ids'];
			}
		}
		if (!empty($options['poll_options'])) {
			$body['poll'] = array(
				'options'          => (array)$options['poll_options'],
				'duration_minutes' => intval(Q::ifset($options, 'poll_duration_minutes', 1440)),
			);
		}
		if (!empty($options['reply_settings'])) {
			$body['reply_settings'] = $options['reply_settings'];
		}
		if (!empty($options['for_super_followers_only'])) {
			$body['for_super_followers_only'] = true;
		}
		if (!empty($options['community_id'])) {
			$body['community_id'] = $options['community_id'];
		}
		if (!empty($options['body']) && is_array($options['body'])) {
			$body = array_merge($body, $options['body']);
		}
		return $this->apiRequest('POST', 'https://api.x.com/2/tweets', $body);
	}

	/**
	 * Delete one of the authenticating user's Posts.
	 * @method deleteTweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { deleted: true } }
	 */
	function deleteTweet($tweetId)
	{
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/tweets/' . rawurlencode($tweetId));
	}

	/**
	 * Like a Post. (Create-like is restricted to paid API tiers as of 2025.)
	 * @method likeTweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { liked: true } }
	 */
	function likeTweet($tweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/likes',
			array('tweet_id' => (string)$tweetId));
	}

	/**
	 * Remove a Like.
	 * @method unlikeTweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { liked: false } }
	 */
	function unlikeTweet($tweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/likes/' . rawurlencode($tweetId));
	}

	/**
	 * Repost (retweet) a Post.
	 * @method retweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { retweeted: true } }
	 */
	function retweet($tweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/retweets',
			array('tweet_id' => (string)$tweetId));
	}

	/**
	 * Undo a Repost.
	 * @method unretweet
	 * @param {string} $sourceTweetId
	 * @return {array|null} decoded { data: { retweeted: false } }
	 */
	function unretweet($sourceTweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/retweets/' . rawurlencode($sourceTweetId));
	}

	/**
	 * Follow a user. If the target is protected, this sends a follow request.
	 * @method followUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { following: bool, pending_follow: bool } }
	 */
	function followUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/following',
			array('target_user_id' => (string)$targetUserId));
	}

	/**
	 * Unfollow a user.
	 * @method unfollowUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { following: false } }
	 */
	function unfollowUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/following/' . rawurlencode($targetUserId));
	}

	/**
	 * Mute a user.
	 * @method muteUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { muting: true } }
	 */
	function muteUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/muting',
			array('target_user_id' => (string)$targetUserId));
	}

	/**
	 * Unmute a user.
	 * @method unmuteUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { muting: false } }
	 */
	function unmuteUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/muting/' . rawurlencode($targetUserId));
	}

	/**
	 * Block a user.
	 * @method blockUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { blocking: true } }
	 */
	function blockUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/blocking',
			array('target_user_id' => (string)$targetUserId));
	}

	/**
	 * Unblock a user.
	 * @method unblockUser
	 * @param {string} $targetUserId
	 * @return {array|null} decoded { data: { blocking: false } }
	 */
	function unblockUser($targetUserId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/blocking/' . rawurlencode($targetUserId));
	}

	/**
	 * Bookmark a Post. (Bookmark endpoints require a paid API tier as of 2025.)
	 * @method bookmarkTweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { bookmarked: true } }
	 */
	function bookmarkTweet($tweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/bookmarks',
			array('tweet_id' => (string)$tweetId));
	}

	/**
	 * Remove a Bookmark.
	 * @method unbookmarkTweet
	 * @param {string} $tweetId
	 * @return {array|null} decoded { data: { bookmarked: false } }
	 */
	function unbookmarkTweet($tweetId)
	{
		if (!$this->xid) {
			return null;
		}
		return $this->apiRequest('DELETE',
			'https://api.x.com/2/users/' . rawurlencode($this->xid)
			. '/bookmarks/' . rawurlencode($tweetId));
	}

	/**
	 * Hide or unhide a reply to one of the authenticating user's Posts.
	 * @method hideReply
	 * @param {string} $tweetId The reply's id
	 * @param {boolean} [$hidden=true]
	 * @return {array|null} decoded { data: { hidden: bool } }
	 */
	function hideReply($tweetId, $hidden = true)
	{
		return $this->apiRequest('PUT',
			'https://api.x.com/2/tweets/' . rawurlencode($tweetId) . '/hidden',
			array('hidden' => (bool)$hidden));
	}

	/**
	 * Convenience: unhide a previously hidden reply.
	 * @method unhideReply
	 * @param {string} $tweetId
	 * @return {array|null}
	 */
	function unhideReply($tweetId)
	{
		return $this->hideReply($tweetId, false);
	}

	/**
	 * The authenticating user's most recent bookmarked Posts.
	 * @method getBookmarks
	 * @param {array} [$options] max_results, pagination_token, tweet.fields, expansions, ...
	 * @return {array|null}
	 */
	function getBookmarks($options = array())
	{
		if (!$this->xid) {
			return null;
		}
		$url = 'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/bookmarks';
		$qs = $this->buildQuery($options);
		return $this->apiRequest('GET', $qs ? "$url?$qs" : $url);
	}

	/**
	 * Posts liked by the authenticating user.
	 * @method getLikedTweets
	 * @param {array} [$options] max_results, pagination_token, tweet.fields, expansions, ...
	 * @return {array|null}
	 */
	function getLikedTweets($options = array())
	{
		if (!$this->xid) {
			return null;
		}
		$url = 'https://api.x.com/2/users/' . rawurlencode($this->xid) . '/liked_tweets';
		$qs = $this->buildQuery($options);
		return $this->apiRequest('GET', $qs ? "$url?$qs" : $url);
	}

	/**
	 * Send a direct message to a recipient (by their xid) as this user. General
	 * counterpart to handlePushNotification, which targets this row's own user.
	 * @method sendDirectMessage
	 * @param {string} $recipientXid
	 * @param {string} $text
	 * @param {array} [$options] attachments (array of { media_id })
	 * @return {array|null} decoded { data: { dm_conversation_id, dm_event_id } }
	 */
	function sendDirectMessage($recipientXid, $text, $options = array())
	{
		if (!$recipientXid || !strlen($text)) {
			return null;
		}
		$body = array('text' => $text);
		if (!empty($options['attachments'])) {
			$body['attachments'] = $options['attachments'];
		}
		return $this->apiRequest('POST',
			'https://api.x.com/2/dm_conversations/with/'
			. rawurlencode($recipientXid) . '/messages', $body);
	}

	/**
	 * Upload a single media file (image/GIF) via the v2 endpoint and return its id,
	 * for use in postTweet(['media_ids' => [...]]) or DM attachments. Requires the
	 * media.write scope. This is the simple single-request path; large video needs
	 * the chunked INIT/APPEND/FINALIZE flow, not implemented here.
	 * @method uploadMedia
	 * @param {string} $filePath Absolute path to a readable file
	 * @param {array} [$options] media_category (default 'tweet_image'), media_type (MIME)
	 * @return {array|null} decoded { data: { id, media_key, ... } }
	 */
	function uploadMedia($filePath, $options = array())
	{
		if (!$this->accessToken || !is_readable($filePath)) {
			return null;
		}
		$mimeType = Q::ifset($options, 'media_type', null);
		$fields = array(
			'media' => class_exists('CURLFile')
				? new CURLFile($filePath, $mimeType ? $mimeType : null)
				: '@' . $filePath,
			'media_category' => Q::ifset($options, 'media_category', 'tweet_image'),
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.x.com/2/media/upload');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); // multipart/form-data
		curl_setopt($ch, CURLOPT_USERAGENT,
			Q_Config::get('Twitter', 'userAgent', 'Qbix', null));
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer ' . $this->accessToken,
			'Accept: application/json',
		));
		$response = curl_exec($ch);
		curl_close($ch);
		if ($response === false) {
			return null;
		}
		return Q::json_decode($response, true);
	}

	/**
	 * Issue an authenticated X API v2 request with this row's user token. GET/POST
	 * go through Q_Utils (matching the rest of the plugin); DELETE/PUT use curl,
	 * since Q_Utils has no verb-specific helper for those.
	 * @method apiRequest
	 * @protected
	 * @param {string} $method GET|POST|DELETE|PUT
	 * @param {string} $url Full https://api.x.com/2/... url
	 * @param {array|string|null} [$body] JSON-encoded if an array
	 * @return {array|null} decoded response, or null on transport failure / missing token
	 */
	protected function apiRequest($method, $url, $body = null)
	{
		if (!$this->accessToken) {
			return null;
		}
		$method = strtoupper($method);
		$userAgent = Q_Config::get('Twitter', 'userAgent', 'Qbix', null);
		if ($method === 'GET') {
			$response = Q_Utils::get($url, $userAgent, [], array(
				'Authorization' => 'Bearer ' . $this->accessToken,
				'Accept'        => 'application/json',
			), 30, false);
		} else if ($method === 'POST') {
			$payload = ($body === null)
				? ''
				: (is_string($body) ? $body : Q::json_encode($body));
			$response = Q_Utils::post($url, $payload, $userAgent, [], array(
				'Authorization: Bearer ' . $this->accessToken,
				'Content-Type: application/json',
				'Accept: application/json',
			), 30, false);
		} else {
			$ch = curl_init();
			$headers = array(
				'Authorization: Bearer ' . $this->accessToken,
				'Accept: application/json',
			);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			if ($body !== null) {
				$headers[] = 'Content-Type: application/json';
				curl_setopt($ch, CURLOPT_POSTFIELDS,
					is_string($body) ? $body : Q::json_encode($body));
			}
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$response = curl_exec($ch);
			curl_close($ch);
			if ($response === false) {
				return null;
			}
		}
		return Q::json_decode($response, true);
	}

	/**
	 * Build a query string from an options array: arrays become comma-joined
	 * (X v2 field/expansion lists), scalars are urlencoded.
	 * @method buildQuery
	 * @protected
	 * @param {array} $options
	 * @return {string}
	 */
	protected function buildQuery($options)
	{
		$parts = array();
		foreach ($options as $k => $v) {
			if (is_array($v)) {
				if ($v) {
					$parts[] = "$k=" . implode(',', $v);
				}
			} else if ($v !== null && $v !== '') {
				$parts[] = "$k=" . urlencode($v);
			}
		}
		return implode('&', $parts);
	}
}