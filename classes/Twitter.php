<?php
/**
 * Twitter API
 * @module Twitter
 * @main Twitter
 */
/**
 * Static methods for the Twitter models.
 * @class Twitter
 * @extends Base_Twitter
 */
abstract class Twitter
{
	/*
	 * This is where you would place all the static methods for the models,
	 * the ones that don't strongly pertain to a particular row or table.
	 * If file 'Telegram.php.inc' exists, its content is included
	 * * * */

	/* * * */

    static function isValidUsername($username)
    {
        $normalized = Q_Utils::normalize($username, '_', null, 200, true);
        return $username === $normalized;
    }

    /**
     * Retrieve information about users by their usernames
     * @param {string} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {array} $usernames An array of usernames on Twitter, but they must have been sanitized already
     * @param {array} [$fields=array()] Optionally limit the  fields to retrieve, otherwise it will return
     *   created_at, description, entities, id, location, most_recent_tweet_id, name, pinned_tweet_id, profile_image_url, protected, public_metrics, url, username, verified, verified_type, withheld
     * @return {array}
     */
    static function byUsernames($appId, $usernames = array(), $fields = array(
        'created_at', 'description', 'entities', 'id', 'location', 
        'most_recent_tweet_id', 'name', 'pinned_tweet_id', 
        'profile_image_url', 'protected', 'public_metrics', 'url', 
        'username', 'verified', 'verified_type', 'withheld'
    )) {
        $params = 'usernames=' . implode(',', $usernames);
        $params .= ($fields ? "&user.fields=" . implode(',', $fields) : '');
        return self::api($appId, 'users/by', null, $params);
    }

    /**
     * Search recent tweets and return 10-100 of them, with possible extra information
     * @param {string} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {string} $query Tweet content to search for, can include text, hashtags or anything else from 
     *    https://developer.x.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
     * @param {array} [$options=array()] Additional options for the method, such as
     * @param {string} [$options.end_time] Date in ISO format YYYY-MM-DDTHH:mm:ssZ (ISO 8601/RFC 3339)
     * @param {array} [$options.expansions=array("author_id")] can be an array of
     *    attachments.poll_ids, attachments.media_keys, author_id, edit_history_tweet_ids, entities.mentions.username, geo.place_id, in_reply_to_user_id, referenced_tweets.id, referenced_tweets.id.author_id
     * @param {number} [$options.max_results=10] The maximum number of results to return, up to 100
     * @param {array} [$options.media_fields] can be an array of
     *    duration_ms, height, media_key, preview_image_url, type, url, width, public_metrics, non_public_metrics, organic_metrics, promoted_metrics, alt_text, variants
     * @param {array} [$options.tweet_fields] can be an array of
     *     attachments, author_id, context_annotations, conversation_id, created_at, edit_controls, entities, geo, id, in_reply_to_user_id, lang, non_public_metrics, public_metrics, organic_metrics, promoted_metrics, possibly_sensitive, referenced_tweets, reply_settings, source, text, withheld
     * @param {array} [$options.user_fields] pass if expansions contained one of
     *     author_id, entities.mentions.username, in_reply_to_user_id, referenced_tweets.id.author_id.
     *     It can be an array of
     *     created_at, description, entities, id, location, most_recent_tweet_id, name, pinned_tweet_id, profile_image_url, protected, public_metrics, url, username, verified, verified_type, withheld
     * @return {array}
     */
    static function searchRecentTweets($appId, $query = '', $options = array())
    {
        if (!isset($options['user.fields'])) {
            $options['user.fields'] = array(
                'created_at', 'description', 'entities', 'id', 'location', 
                'most_recent_tweet_id', 'name', 'pinned_tweet_id', 
                'profile_image_url', 'protected', 'public_metrics', 'url', 
                'username', 'verified', 'verified_type', 'withheld'
            );
        }
        if (!isset($options['expansions'])) {
            $options['expansions'] = array(
                'attachments.poll_ids', 'attachments.media_keys', 'author_id',
                'edit_history_tweet_ids', 'entities.mentions.username', 
                'geo.place_id', 'in_reply_to_user_id', 'referenced_tweets.id', 
                'referenced_tweets.id.author_id'
            );
        }
        $params = 'query=' . urlencode($query);
        foreach ($options as $k => $v) {
            $params .= ($options[$k] ? "&$k=" . implode(',', $options[$k]) : '');
        }
        return self::api($appId, 'tweets/search/recent', null, $params);
    }

    /**
     * Calculate an endpoint for calling methods
     * @method endpoint
     * @static
     * @param {string} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {string} $methodName The name of the Telegram Bot API method in https://core.telegram.org/bots/api
     * @param {string|number} $id The id to pass to the method
     */
    private static function endpoint($appId, $methodName, $id = null) 
    {
        if (empty($id)) {
            return "https://api.x.com/2/$methodName";
        }
        if (is_array($id)) {
            $id = implode(',', $id);
        }
        return  "https://api.x.com/2/$methodName/$id";
    }

    /**
     * Get bearer token from appId in config
     * @method bearerTokenFromConfig
     * @static
     * @param {string} $appId The appId under Users/apps/telegram config
     * @return {string}
     * @throws {Q_Exception_MissingConfig}
     */
    protected static function bearerTokenFromConfig($appId)
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        return Q::ifset($info, 'bearerToken', null);
    }

    protected static function encodeConsumerPayload()
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        $apiKey = Q::ifset($info, 'apiKey', null);
        $secret = Q::ifset($info, 'secret', null);
        return base64_encode(urlencode($apiKey) . ':' . urlencode($secret));
    }

    static function obtainBearerToken()
    {
        $data = 'grant_type=client_credentials';
        $basic = self::encodeConsumerPayload();
        $response = Q_Utils::post('https://api.x.com/oauth2/token', $data, Q_Config::get(
            'Twitter', 'userAgent', 'Qbix', null
        ), [], [
            "Authorization: Basic $basic",
            'Host: api.x.com',
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8'
        ], 30, false);
        $arr = Q::json_decode($response, true);
        if (empty($arr['token_type'])
        or $arr['token_type'] != 'bearer') {
            return null;
        }
        return $arr['access_token'];
    }
    
    /**
     * Call the Twitter v2 API
     * @method api
     * @param {string} $appId Corresponds to the Twitter app's ID
     * @param {string} $methodName The name of the Telegram Bot API method in https://core.telegram.org/bots/api
     * @param {string} $id The id to pass to the method
     * @param {array|string} [$params=array()]
     * @static
     * @return {array} $param An array with keys "data" and "errors", typically.
     * @throws {Telegram_Exception_API} if there is an error
     */
    protected static function api($appId, $methodName, $id, $params = '')
    {
        $endpoint = self::endpoint($appId, $methodName, $id);
        $data = $params && is_array($params) ? http_build_query($params) : $params;
        $bearerToken = self::bearerTokenFromConfig($appId);
        if (!$bearerToken) {
            $bearerToken = self::obtainBearerToken(); // TODO: save it
        }
        if (!$bearerToken) {
            throw new Q_Exception_WrongValue(array('field' => 'bearerToken'));
        }
        $response = Q_Utils::get("$endpoint?$data", Q_Config::get(
            'Twitter', 'userAgent', 'Qbix', null
        ), [], [
            'Authorization' => "Bearer $bearerToken",
            'Host' => 'api.x.com',
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip',
            'Content-type'=> 'application/json'
        ], 30, false);
        $arr = Q::json_decode($response, true);
        if (!empty($arr['status']) and $arr['status'] != 200) {
            throw new Twitter_Exception_API([
                'error_code' => $arr['status'],
                'description' => $error['detail']
            ]);
        }
        // if (!empty($arr['errors'])) {
        //     $error = reset($arr['errors']);
        //     throw new Twitter_Exception_API([
        //         'error_code' => $error['code'],
        //         'description' => $error['message']
        //     ]);
        // }
        return $arr;
    }

};