<?php

/**
 * @module Users
 */

/**
 * Class representing a Twitter-authenticated user.
 *
 * Authentication uses Twitter OAuth 2.0 with PKCE (Authorization Code Flow).
 * Config keys read from Users/apps/twitter/{appId}:
 *   clientId        — OAuth 2.0 client ID (required)
 *   clientSecret    — OAuth 2.0 client secret (optional; omit for public clients)
 *   oauth2/scopes   — array of scopes, e.g. ["tweet.read","users.read","dm.write","offline.access"]
 *   oauth2/redirectUri — full callback URL, e.g. "https://mysite.com/action/Users/oauth2callback"
 *
 * @class Users_ExternalFrom_Twitter
 * @extends Users_ExternalFrom
 */
class Users_ExternalFrom_Twitter extends Users_ExternalFrom implements Users_ExternalFrom_Interface
{
    // Twitter OAuth 2.0 endpoints
    const AUTHORIZE_URL = 'https://twitter.com/i/oauth2/authorize';
    const TOKEN_URL     = 'https://api.twitter.com/2/oauth2/token';
    const REVOKE_URL    = 'https://api.twitter.com/2/oauth2/revoke';

    /**
     * Gets a Users_ExternalFrom_Twitter object constructed from the current request.
     * It is your job to populate it with a userId and save it.
     *
     * Flow:
     *  1. JS sends Users.authPayload.twitter (after completing the popup/redirect OAuth dance)
     *  2. Or: server detects ?code=&state= in the request (redirect-based flow)
     *  3. Or: valid access token already stored in cookie from a prior session
     *
     * On the initial page hit that requires auth, this method initiates the redirect
     * and returns null. The browser lands back on the redirectUri with ?code=..., at
     * which point this method exchanges the code and returns a populated object.
     *
     * @method authenticate
     * @static
     * @param {string} [$appId=Q::app()]
     * @param {boolean} [$setCookie=true]  Persist token data in a cookie
     * @param {boolean} [$longLived=true]  Refresh expired tokens when possible
     * @return {Users_ExternalFrom_Twitter|null}
     */
    static function authenticate($appId = null, $setCookie = true, $longLived = true)
    {
        list($appId, $appInfo) = Users::appInfo('twitter', $appId);

        $result = array(
            'accessToken'     => null,
            'refreshToken'    => null,
            'expires'         => null,
            'userID'          => null,
            'username'        => null,
            'profileImageUrl' => null,
        );

        // ---- Source 1: JS sent Users.authPayload.twitter in the POST body ----
        // This is the preferred path when using a popup-based OAuth flow on the
        // client side (Users.js handles the popup and sends back the payload).
        if ($authPayload = Q_Request::special('Users.authPayload.twitter', null)) {
            $result = array_merge($result, Q::take($authPayload, array(
                'accessToken', 'refreshToken', 'expires', 'expiresIn',
                'userID', 'username', 'profileImageUrl'
            )));
            if (!isset($result['expires']) && isset($result['expiresIn'])) {
                $result['expires'] = time() + $result['expiresIn'];
            }
        }

        // ---- Source 2: OAuth 2.0 PKCE server-side callback (?code=&state=) ----
        // Used when the app performs a server-side redirect (not a popup).
        if (empty($result['accessToken'])) {
            $code  = Q_Request::get('code', null);
            $state = Q_Request::get('state', null);
            $sessionKey  = "twitter_oauth2_{$appId}";
            $storedState = Q_Session::get("{$sessionKey}_state", null);

            if ($code && $state && $storedState && hash_equals($storedState, $state)) {
                $codeVerifier = Q_Session::get("{$sessionKey}_verifier", null);
                $redirectUri  = Q_Config::get('Users', 'apps', 'twitter', $appId, 'oauth2', 'redirectUri', null);

                $tokens = self::exchangeCode($appId, $appInfo, $code, $codeVerifier, $redirectUri);

                // Clear PKCE session state regardless of outcome
                Q_Session::clear("{$sessionKey}_state");
                Q_Session::clear("{$sessionKey}_verifier");

                if ($tokens && !empty($tokens['access_token'])) {
                    $result['accessToken']  = $tokens['access_token'];
                    $result['refreshToken'] = Q::ifset($tokens, 'refresh_token', null);
                    $result['expires']      = isset($tokens['expires_in'])
                        ? time() + $tokens['expires_in']
                        : null;
                }
            }
        }

        // ---- Source 3: Cookie from a prior authenticated session ----
        if (empty($result['accessToken'])) {
            $cookieName = "twitter_at_{$appId}";
            if (!empty($_COOKIE[$cookieName])) {
                $stored = Q::json_decode($_COOKIE[$cookieName], true);
                if (!empty($stored['accessToken'])) {
                    $result = array_merge($result, $stored);
                }
            }
        }

        // ---- Nothing found — initiate the OAuth 2.0 PKCE redirect flow ----
        if (empty($result['accessToken'])) {
            $clientId    = Q_Config::get('Users', 'apps', 'twitter', $appId, 'clientId', null);
            $scopes      = Q_Config::get('Users', 'apps', 'twitter', $appId, 'oauth2', 'scopes',
                array('tweet.read', 'users.read', 'offline.access'));
            $redirectUri = Q_Config::get('Users', 'apps', 'twitter', $appId, 'oauth2', 'redirectUri', null);

            if (!$clientId || !$redirectUri) {
                return null; // misconfigured; can't redirect
            }

            $state        = self::generateVerifier(16); // shorter random string for state
            $verifier     = self::generateVerifier();
            $challenge    = self::generateChallenge($verifier);
            $sessionKey   = "twitter_oauth2_{$appId}";

            Q_Session::set("{$sessionKey}_state",    $state);
            Q_Session::set("{$sessionKey}_verifier", $verifier);

            $params = http_build_query(array(
                'response_type'         => 'code',
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'scope'                 => implode(' ', (array)$scopes),
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ));

            Q_Response::redirect(self::AUTHORIZE_URL . '?' . $params);
            return null;
        }

        // ---- Refresh the token if it is expired or close to expiring ----
        if ($longLived
        && !empty($result['refreshToken'])
        && !empty($result['expires'])
        && $result['expires'] < time() + 300) {
            $tokens = self::refreshToken($appId, $appInfo, $result['refreshToken']);
            if ($tokens) {
                $result['accessToken']  = $tokens['access_token'];
                $result['refreshToken'] = Q::ifset($tokens, 'refresh_token', $result['refreshToken']);
                $result['expires']      = isset($tokens['expires_in'])
                    ? time() + $tokens['expires_in']
                    : $result['expires'];
            }
        }

        // ---- Fetch the user profile if we don't yet have the xid ----
        if (empty($result['userID']) && !empty($result['accessToken'])) {
            $me = self::fetchMe($result['accessToken']);
            if ($me) {
                $result['userID']          = Q::ifset($me, 'id', null);
                $result['username']        = Q::ifset($me, 'username', null);
                $result['profileImageUrl'] = Q::ifset($me, 'profile_image_url', null);
            }
        }

        if (empty($result['userID'])) {
            return null;
        }

        // ---- Persist in cookie ----
        $cookieName  = "twitter_at_{$appId}";
        $cookieNames = array($cookieName, $cookieName . '_expires');
        if ($setCookie) {
            $payload = Q::json_encode(array(
                'accessToken'     => $result['accessToken'],
                'refreshToken'    => $result['refreshToken'],
                'expires'         => $result['expires'],
                'userID'          => $result['userID'],
                'username'        => $result['username'],
                'profileImageUrl' => $result['profileImageUrl'],
            ));
            Q_Response::setCookie($cookieName,             $payload,           $result['expires']);
            Q_Response::setCookie($cookieName . '_expires', $result['expires'], $result['expires']);
        }

        $ef = new Users_ExternalFrom_Twitter();
        // Note: $ef->userId (Qbix user id) is NOT set here — the caller does that
        $ef->platform    = 'twitter';
        $ef->appId       = $appId;
        $ef->xid         = $result['userID'];
        $ef->accessToken = $result['accessToken'];
        $ef->expires     = isset($result['expires']) && is_integer($result['expires'])
            ? $result['expires']
            : Db::fromDateTime($result['expires']);
        $ef->set('username',           $result['username']);
        $ef->set('profileImageUrl',    $result['profileImageUrl']);
        $ef->set('refreshToken',       $result['refreshToken']);
        $ef->set('cookiesToClearOnLogout', $cookieNames);
        return $ef;
    }

    /**
     * Get icon URLs for this user.
     *
     * Twitter's profile_image_url (stored during authenticate/import) points to
     * the original highest-resolution image. We return that same URL for every
     * requested size; Users will download it once and resize locally.
     *
     * @method icon
     * @param {array} [$sizes] Array of size strings like "80x80"; defaults to Users/icon config
     * @param {string} [$suffix='']
     * @return {array|null}  Keys are "$size$suffix", values are the original image URL
     */
    function icon($sizes = null, $suffix = '')
    {
        if (!isset($sizes)) {
            $sizes = array_keys(Q_Image::getSizes('Users/icon'));
        }
        if (!$this->xid) {
            return null;
        }

        $url = $this->get('profileImageUrl', null);
        if (!$url) {
            return null;
        }

        // Strip the size suffix to get the original (largest) image.
        // Twitter stores profile_image_url as e.g. ".../photo_normal.jpg"
        $original = preg_replace('/_(?:mini|normal|bigger|200x200)(\.\w+)$/', '$1', $url);

        $icon = array();
        foreach ($sizes as $size) {
            $icon[$size . $suffix] = $original;
        }
        return $icon;
    }

    /**
     * Import profile fields from Twitter into Users::$cache['platformUserData'].
     * Called after authenticate() to pull in data for creating/updating a Qbix user row.
     *
     * @method import
     * @param {array|null} $fieldNames  Twitter v2 user fields, or null to use
     *   the Users/import/twitter config key. Common values:
     *   id, name, username, description, location, profile_image_url,
     *   public_metrics, verified, created_at, url, entities
     * @return {array}  The raw Twitter v2 user object
     */
    function import($fieldNames)
    {
        if (!is_array($fieldNames)) {
            $fieldNames = Q_Config::get('Users', 'import', 'twitter', null);
        }
        if (!$fieldNames) {
            return array();
        }

        $result = null;

        // Prefer a user-level access token (richer data, respects protected accounts)
        if ($this->accessToken) {
            $result = self::fetchMe($this->accessToken, $fieldNames);
        }

        // Fall back to app-level lookup via Twitter::byUsernames() (public data only)
        if (!$result) {
            $username = $this->get('username', null);
            if ($username) {
                $r      = Twitter::byUsernames($this->appId, array($username), $fieldNames);
                $result = Q::ifset($r, 'data', 0, null);
            }
        }

        if (!$result) {
            return array();
        }

        if (!empty($result['profile_image_url'])) {
            $this->set('profileImageUrl', $result['profile_image_url']);
        }
        if (!empty($result['username'])) {
            $this->set('username', $result['username']);
        }

        Users::$cache['platformUserData'] = array('twitter' => $result);
        return $result;
    }

    /**
     * Returns externalLabel => array(xids) for the given roleIds.
     * Twitter has no app-level role groups; we return the single authenticated
     * xid for each requested roleId (same pattern as Facebook).
     *
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
            $label           = "twitter_{$this->appId}/{$roleId}";
            $results[$label] = array($xid);
        }
        return $results;
    }

    /**
     * Send a push notification to this user via a Twitter DM.
     * Requires the authenticated user to allow DMs from the sending account
     * and the app to have the dm.write scope.
     *
     * $notification keys:
     *   alert  (string | array with 'body') — message text
     *   href   (string, optional)           — appended on a new line if present
     *
     * @method handlePushNotification
     * @param {array} $notification
     * @param {array} [$options]
     * @return {boolean}
     */
    public function handlePushNotification($notification, $options = array())
    {
        $xid = $this->xid;
        if (!$xid || !$this->accessToken) {
            return false;
        }

        $alert = Q::ifset($notification, 'alert', null);
        $text  = '';
        if (is_string($alert)) {
            $text = $alert;
        } elseif (is_array($alert) && !empty($alert['body'])) {
            $text = $alert['body'];
        }
        if (!$text) {
            return false;
        }

        $href = Q::ifset($notification, 'href', '');
        if ($href && $href[0] === '/') {
            $baseUrl = Q_Config::get(array('Users', 'apps', 'baseUrl'), '');
            $href    = $baseUrl . $href;
        }
        if ($href) {
            $text = rtrim($text) . "\n" . $href;
        }

        $endpoint = "https://api.x.com/2/dm_conversations/with/{$xid}/messages";
        try {
            $response = Q_Utils::post($endpoint, Q::json_encode(array('text' => $text)),
                Q_Config::get('Twitter', 'userAgent', 'Qbix', null), [], [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ], 30, false
            );
            $arr = Q::json_decode($response, true);
            if (!empty($arr['errors'])) {
                $msg = strtolower(Q::ifset($arr['errors'], 0, 'message', ''));
                $e   = new Exception($msg);
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
    // Private helpers — PKCE
    // -------------------------------------------------------------------------

    /**
     * Generate a PKCE code_verifier: 32 random bytes → 43-char base64url string.
     * @param {int} [$bytes=32]
     * @return {string}
     */
    private static function generateVerifier($bytes = 32)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Derive the code_challenge from a verifier: BASE64URL(SHA256(verifier)).
     * @param {string} $verifier
     * @return {string}
     */
    private static function generateChallenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Exchange an authorization code for an access token.
     * @param {string} $appId
     * @param {array}  $appInfo
     * @param {string} $code
     * @param {string|null} $codeVerifier  PKCE verifier stored in session
     * @param {string} $redirectUri
     * @return {array|null}  Token response or null on failure
     */
    private static function exchangeCode($appId, $appInfo, $code, $codeVerifier, $redirectUri)
    {
        $clientId     = Q::ifset($appInfo, 'clientId', null);
        $clientSecret = Q::ifset($appInfo, 'clientSecret', null);

        if (!$clientId) return null;

        $body = array(
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
        );
        if ($codeVerifier) {
            $body['code_verifier'] = $codeVerifier;
        }

        $headers = array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json');
        if ($clientSecret) {
            // Confidential client — use HTTP Basic auth
            $headers[] = 'Authorization: Basic ' . base64_encode("$clientId:$clientSecret");
        }

        $response = Q_Utils::post(self::TOKEN_URL, http_build_query($body),
            Q_Config::get('Twitter', 'userAgent', 'Qbix', null),
            [], $headers, 30, false
        );
        $arr = Q::json_decode($response, true);
        return (!empty($arr['access_token'])) ? $arr : null;
    }

    /**
     * Refresh an expired access token using the stored refresh token.
     * Requires offline.access scope to have been granted.
     * @param {string} $appId
     * @param {array}  $appInfo
     * @param {string} $refreshToken
     * @return {array|null}
     */
    private static function refreshToken($appId, $appInfo, $refreshToken)
    {
        $clientId     = Q::ifset($appInfo, 'clientId', null);
        $clientSecret = Q::ifset($appInfo, 'clientSecret', null);

        if (!$clientId) return null;

        $body = array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
        );

        $headers = array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json');
        if ($clientSecret) {
            $headers[] = 'Authorization: Basic ' . base64_encode("$clientId:$clientSecret");
        }

        $response = Q_Utils::post(self::TOKEN_URL, http_build_query($body),
            Q_Config::get('Twitter', 'userAgent', 'Qbix', null),
            [], $headers, 30, false
        );
        $arr = Q::json_decode($response, true);
        return (!empty($arr['access_token'])) ? $arr : null;
    }

    /**
     * Fetch the authenticated user's own profile via GET /2/users/me.
     * Uses the user-level access token, not the app bearer token.
     * @param {string} $accessToken  User OAuth 2.0 access token
     * @param {array}  [$fields]     Twitter v2 user fields to request
     * @return {array|null}
     */
    private static function fetchMe($accessToken, $fields = array(
        'id', 'name', 'username', 'description', 'location',
        'profile_image_url', 'public_metrics', 'verified', 'created_at', 'url'
    )) {
        $params   = 'user.fields=' . implode(',', $fields);
        $response = Q_Utils::get("https://api.x.com/2/users/me?$params",
            Q_Config::get('Twitter', 'userAgent', 'Qbix', null), [], [
                'Authorization'   => "Bearer $accessToken",
                'Accept'          => 'application/json',
                'Accept-Encoding' => 'gzip',
            ], 30, false
        );
        $arr = Q::json_decode($response, true);
        return Q::ifset($arr, 'data', null);
    }
}