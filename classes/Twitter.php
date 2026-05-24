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
     * If file 'Twitter.php.inc' exists, its content is included
     * * * */

    /* * * */

    // -------------------------------------------------------------------------
    // Public utility
    // -------------------------------------------------------------------------

    static function isValidUsername($username)
    {
        $normalized = Q_Utils::normalize($username, '_', null, 200, true);
        return $username === $normalized;
    }

    // -------------------------------------------------------------------------
    // Public API — Users
    //
    // All methods return Twitter v2-shaped arrays regardless of provider,
    // so callers are fully provider-agnostic.
    //
    // Standard envelope:
    //   { data: [...], includes: { users: [...] }, meta: { next_token, result_count } }
    //
    // Individual user/tweet objects use Twitter v2 snake_case field names.
    // The original provider response is preserved under '_raw'.
    // -------------------------------------------------------------------------

    /**
     * Retrieve information about users by their usernames.
     * @method byUsernames
     * @static
     * @param {string} $appId
     * @param {array} $usernames Pre-sanitized Twitter usernames
     * @param {array} [$fields] x.com only: limit which user fields to return
     * @return {array} { data: [ ...user objects... ] }
     */
    static function byUsernames($appId, $usernames = array(), $fields = array(
        'created_at', 'description', 'entities', 'id', 'location',
        'most_recent_tweet_id', 'name', 'pinned_tweet_id',
        'profile_image_url', 'protected', 'public_metrics', 'url',
        'username', 'verified', 'verified_type', 'withheld'
    )) {
        if (self::provider($appId) === 'twitterapi.io') {
            // twitterapi.io has no batch-by-username endpoint; loop one at a time.
            $results = array();
            foreach ($usernames as $username) {
                $r = self::taiApi($appId, 'user/info', 'userName=' . urlencode($username));
                if (!empty($r['data'])) {
                    $results[] = self::normalizeUser($r['data']);
                }
            }
            return array('data' => $results);
        }
        $params = 'usernames=' . implode(',', $usernames);
        $params .= ($fields ? '&user.fields=' . implode(',', $fields) : '');
        return self::api($appId, 'users/by', null, $params);
    }

    /**
     * Get tweets from a user's timeline (most recent first).
     * @method getUserTimeline
     * @static
     * @param {string} $appId
     * @param {string} $username
     * @param {array} [$options]
     *   twitterapi.io: cursor
     *   x.com: max_results, next_token
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function getUserTimeline($appId, $username, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'userName=' . urlencode($username);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'user/last_tweets', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'tweets', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }
        $userId = self::resolveUserId($appId, $username);
        if (!$userId) return self::emptyTweetEnvelope();
        $params = 'tweet.fields=created_at,public_metrics,entities,lang,author_id'
            . '&expansions=author_id'
            . '&max_results=' . Q::ifset($options, 'max_results', 10);
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'users', $userId . '/tweets', $params);
    }

    /**
     * Get one or more tweets by ID.
     * @method getTweets
     * @static
     * @param {string} $appId
     * @param {array} $ids Tweet IDs
     * @return {array} { data:[...tweets] }
     */
    static function getTweets($appId, array $ids)
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'tweet_ids=' . implode(',', $ids);
            $r = self::taiApi($appId, 'tweet/tweets', $p);
            $tweets = array_map(array(get_called_class(), 'normalizeTweet'), Q::ifset($r, 'tweets', array()));
            return array('data' => $tweets);
        }
        $params = 'ids=' . implode(',', $ids)
            . '&tweet.fields=created_at,public_metrics,entities,lang,author_id'
            . '&expansions=author_id';
        return self::api($appId, 'tweets', null, $params);
    }

    /**
     * Get followers of a user (full profiles, newest first).
     * @method getUserFollowers
     * @static
     * @param {string} $appId
     * @param {string} $username
     * @param {array} [$options] cursor (twitterapi.io) | max_results, next_token (x.com)
     * @return {array} { data:[...users], meta:{ next_token } }
     */
    static function getUserFollowers($appId, $username, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'userName=' . urlencode($username);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'user/followers', $p);
            $users = array_map(array(get_called_class(), 'normalizeUser'), Q::ifset($r, 'followers', array()));
            return array(
                'data' => $users,
                'meta' => array('next_token' => Q::ifset($r, 'next_cursor', null)),
            );
        }
        $userId = self::resolveUserId($appId, $username);
        if (!$userId) return array('data' => array());
        $params = 'user.fields=created_at,description,id,name,profile_image_url,public_metrics,username,verified';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'users', $userId . '/followers', $params);
    }

    /**
     * Get accounts a user follows (full profiles, newest first).
     * @method getUserFollowing
     * @static
     * @param {string} $appId
     * @param {string} $username
     * @param {array} [$options] cursor (twitterapi.io) | max_results, next_token (x.com)
     * @return {array} { data:[...users], meta:{ next_token } }
     */
    static function getUserFollowing($appId, $username, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'userName=' . urlencode($username);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'user/followings', $p);
            $users = array_map(array(get_called_class(), 'normalizeUser'), Q::ifset($r, 'followings', array()));
            return array(
                'data' => $users,
                'meta' => array('next_token' => Q::ifset($r, 'next_cursor', null)),
            );
        }
        $userId = self::resolveUserId($appId, $username);
        if (!$userId) return array('data' => array());
        $params = 'user.fields=created_at,description,id,name,profile_image_url,public_metrics,username,verified';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'users', $userId . '/following', $params);
    }

    /**
     * Get mentions of a user.
     * @method getUserMentions
     * @static
     * @param {string} $appId
     * @param {string} $username
     * @param {array} [$options] cursor (twitterapi.io) | max_results, next_token (x.com)
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function getUserMentions($appId, $username, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'userName=' . urlencode($username);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'user/mentions', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'tweets', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }
        $userId = self::resolveUserId($appId, $username);
        if (!$userId) return self::emptyTweetEnvelope();
        $params = 'tweet.fields=created_at,public_metrics,entities,lang,author_id'
            . '&expansions=author_id';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'users', $userId . '/mentions', $params);
    }

    // -------------------------------------------------------------------------
    // Public API — Tweets
    // -------------------------------------------------------------------------

    /**
     * Search recent tweets.
     * @method searchRecentTweets
     * @static
     * @param {string} $appId
     * @param {string} $query See https://developer.x.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
     * @param {array} [$options]
     *   x.com (arrays):  expansions, tweet.fields, user.fields, media.fields
     *   x.com (scalars): end_time, max_results, next_token
     *   twitterapi.io:   queryType ('Latest'|'Top'), cursor
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function searchRecentTweets($appId, $query = '', $options = array())
    {
        // twitterapi.io branch — must sit before the x.com params loop
        if (self::provider($appId) === 'twitterapi.io') {
            $p  = 'query=' . urlencode($query);
            $p .= '&queryType=' . urlencode(Q::ifset($options, 'queryType', 'Latest'));
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'tweet/advanced_search', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'tweets', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }

        // x.com path
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
        $taiOnlyKeys = array('queryType', 'cursor');
        $params = 'query=' . urlencode($query);
        foreach ($options as $k => $v) {
            if (in_array($k, $taiOnlyKeys)) continue;
            if (is_array($v) && $v) {
                $params .= "&$k=" . implode(',', $v);
            } elseif (!is_array($v) && $v !== '' && $v !== null) {
                $params .= "&$k=" . urlencode($v);
            }
        }
        return self::api($appId, 'tweets/search/recent', null, $params);
    }

    /**
     * Get replies to a tweet.
     * @method getTweetReplies
     * @static
     * @param {string} $appId
     * @param {string} $tweetId
     * @param {array} [$options] cursor (twitterapi.io) | next_token (x.com)
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function getTweetReplies($appId, $tweetId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'tweetId=' . urlencode($tweetId);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'tweet/replies', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'replies', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }
        // x.com: replies live in the conversation; search by conversation_id
        $params = 'query=' . urlencode("conversation_id:$tweetId -is:retweet")
            . '&tweet.fields=created_at,public_metrics,entities,lang,author_id,in_reply_to_user_id'
            . '&expansions=author_id';
        if (!empty($options['next_token'])) {
            $params .= '&next_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'tweets/search/recent', null, $params);
    }

    /**
     * Get users who retweeted a tweet (~100 per page, newest first).
     * @method getTweetRetweeters
     * @static
     * @param {string} $appId
     * @param {string} $tweetId
     * @param {array} [$options] cursor (twitterapi.io) | max_results, next_token (x.com)
     * @return {array} { data:[...users], meta:{ next_token } }
     */
    static function getTweetRetweeters($appId, $tweetId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'tweetId=' . urlencode($tweetId);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'tweet/retweeters', $p);
            $users = array_map(array(get_called_class(), 'normalizeUser'), Q::ifset($r, 'users', array()));
            return array(
                'data' => $users,
                'meta' => array('next_token' => Q::ifset($r, 'next_cursor', null)),
            );
        }
        $params = 'user.fields=created_at,description,id,name,profile_image_url,public_metrics,username,verified';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'tweets', $tweetId . '/retweeted_by', $params);
    }

    /**
     * Get quote tweets for a tweet (20 per page, newest first).
     * @method getTweetQuotes
     * @static
     * @param {string} $appId
     * @param {string} $tweetId
     * @param {array} [$options]
     *   twitterapi.io: cursor, sinceTime (unix), untilTime (unix), includeReplies (bool)
     *   x.com: next_token
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function getTweetQuotes($appId, $tweetId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            $p = 'tweetId=' . urlencode($tweetId);
            foreach (array('cursor', 'sinceTime', 'untilTime', 'includeReplies') as $k) {
                if (isset($options[$k])) {
                    $p .= "&$k=" . urlencode($options[$k]);
                }
            }
            $r = self::taiApi($appId, 'tweet/quotes', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'tweets', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }
        // x.com v2 has no dedicated quotes endpoint; search is the standard workaround
        $params = 'query=' . urlencode("quotes_of_tweet_id:$tweetId")
            . '&tweet.fields=created_at,public_metrics,entities,lang,author_id'
            . '&expansions=author_id';
        if (!empty($options['next_token'])) {
            $params .= '&next_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'tweets/search/recent', null, $params);
    }

    // -------------------------------------------------------------------------
    // Public API — Lists
    // -------------------------------------------------------------------------

    /**
     * Get metadata for a Twitter List by ID.
     * Note: twitterapi.io has no standalone list-info endpoint; always uses x.com.
     * @method getList
     * @static
     * @param {string} $appId
     * @param {string} $listId
     * @return {array} { data: { id, name, description, owner_id, member_count, follower_count, private, created_at } }
     */
    static function getList($appId, $listId)
    {
        $params = 'list.fields=created_at,description,follower_count,member_count,owner_id,private';
        return self::api($appId, 'lists', $listId, $params);
    }

    /**
     * Get members of a Twitter List (20 per page on twitterapi.io).
     * @method getListMembers
     * @static
     * @param {string} $appId
     * @param {string} $listId
     * @param {array} [$options]
     *   twitterapi.io: cursor
     *   x.com: max_results, next_token
     * @return {array} { data:[...users], meta:{ next_token } }
     */
    static function getListMembers($appId, $listId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            // twitterapi.io uses list_id (snake_case) for this endpoint
            $p = 'list_id=' . urlencode($listId);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'list/members', $p);
            $users = array_map(array(get_called_class(), 'normalizeUser'), Q::ifset($r, 'members', array()));
            return array(
                'data' => $users,
                'meta' => array('next_token' => Q::ifset($r, 'next_cursor', null)),
            );
        }
        $params = 'user.fields=created_at,description,id,name,profile_image_url,public_metrics,username,verified';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'lists', $listId . '/members', $params);
    }

    /**
     * Get followers of a Twitter List (20 per page on twitterapi.io).
     * @method getListFollowers
     * @static
     * @param {string} $appId
     * @param {string} $listId
     * @param {array} [$options]
     *   twitterapi.io: cursor
     *   x.com: max_results, next_token
     * @return {array} { data:[...users], meta:{ next_token } }
     */
    static function getListFollowers($appId, $listId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            // twitterapi.io uses list_id (snake_case) for this endpoint
            $p = 'list_id=' . urlencode($listId);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'list/followers', $p);
            $users = array_map(array(get_called_class(), 'normalizeUser'), Q::ifset($r, 'followers', array()));
            return array(
                'data' => $users,
                'meta' => array('next_token' => Q::ifset($r, 'next_cursor', null)),
            );
        }
        $params = 'user.fields=created_at,description,id,name,profile_image_url,public_metrics,username,verified';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'lists', $listId . '/followers', $params);
    }

    /**
     * Get tweets from a Twitter List timeline (most recent first).
     * twitterapi.io path: GET /twitter/list/tweets_timeline?listId=...
     * x.com path:         GET /2/lists/{id}/tweets
     * @method getListTweets
     * @static
     * @param {string} $appId
     * @param {string} $listId
     * @param {array} [$options]
     *   twitterapi.io: cursor
     *   x.com: max_results, next_token
     * @return {array} { data:[...tweets], includes:{ users:[...] }, meta:{ next_token, result_count } }
     */
    static function getListTweets($appId, $listId, $options = array())
    {
        if (self::provider($appId) === 'twitterapi.io') {
            // twitterapi.io uses listId (camelCase) for this endpoint
            $p = 'listId=' . urlencode($listId);
            if (!empty($options['cursor'])) {
                $p .= '&cursor=' . urlencode($options['cursor']);
            }
            $r = self::taiApi($appId, 'list/tweets_timeline', $p);
            return self::buildTweetEnvelope(
                Q::ifset($r, 'tweets', array()),
                Q::ifset($r, 'next_cursor', null)
            );
        }
        $params = 'tweet.fields=created_at,public_metrics,entities,lang,author_id'
            . '&expansions=author_id';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'lists', $listId . '/tweets', $params);
    }

    /**
     * Get Lists owned by a user (x.com only; twitterapi.io has no equivalent).
     * @method getUserLists
     * @static
     * @param {string} $appId
     * @param {string} $username
     * @param {array} [$options] max_results, next_token
     * @return {array} { data:[...list objects], meta:{ next_token } }
     */
    static function getUserLists($appId, $username, $options = array())
    {
        $userId = self::resolveUserId($appId, $username);
        if (!$userId) return array('data' => array());
        $params = 'list.fields=created_at,description,follower_count,member_count,owner_id,private';
        if (!empty($options['max_results'])) {
            $params .= '&max_results=' . intval($options['max_results']);
        }
        if (!empty($options['next_token'])) {
            $params .= '&pagination_token=' . urlencode($options['next_token']);
        }
        return self::api($appId, 'users', $userId . '/owned_lists', $params);
    }

    // -------------------------------------------------------------------------
    // Public API — Discovery
    // -------------------------------------------------------------------------

    /**
     * Get trending topics for a location (twitterapi.io only; x.com v2 has no trends endpoint).
     * Pass woeid=1 for worldwide trends.
     * Woeid list: https://gist.github.com/tedyblood/5bb5a9f78314cc1f478b3dd7cde790b9
     *
     * @method getTrends
     * @static
     * @param {string} $appId
     * @param {int} $woeid  Where-on-Earth ID (1 = worldwide, 2459115 = New York)
     * @param {int} [$count=30] Number of trends (minimum 30)
     * @return {array} { data: [ { name, query, rank, meta_description }, ... ] }
     * @throws {Q_Exception_WrongType} if provider is not twitterapi.io
     */
    static function getTrends($appId, $woeid = 1, $count = 30)
    {
        if (self::provider($appId) !== 'twitterapi.io') {
            throw new Q_Exception_WrongType(array(
                'field' => 'provider',
                'type'  => 'twitterapi.io',
                'value' => self::provider($appId),
            ));
        }
        $p = 'woeid=' . intval($woeid) . '&count=' . intval($count);
        $r = self::taiApi($appId, 'trends', $p);
        $trends = array();
        foreach (Q::ifset($r, 'trends', array()) as $t) {
            $trends[] = array(
                'name'             => Q::ifset($t, 'name', null),
                'query'            => Q::ifset($t, 'target', 'query', null),
                'rank'             => Q::ifset($t, 'rank', null),
                'meta_description' => Q::ifset($t, 'meta_description', null),
            );
        }
        return array('data' => $trends);
    }

    // -------------------------------------------------------------------------
    // Private / protected helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the configured provider string, e.g. 'twitterapi.io' or null (x.com default).
     * Set Users/apps/twitter/{appId}/provider in local/app.json.
     */
    private static function provider($appId)
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        return Q::ifset($info, 'provider', null);
    }

    /**
     * Resolve a username to a Twitter numeric user ID.
     * Used internally when x.com endpoints require an ID rather than a handle.
     */
    private static function resolveUserId($appId, $username)
    {
        $r = self::byUsernames($appId, array($username));
        return Q::ifset($r, 'data', 0, 'id', null);
    }

    /**
     * Empty tweet envelope returned when a prerequisite lookup fails.
     */
    private static function emptyTweetEnvelope()
    {
        return array(
            'data'     => array(),
            'includes' => array('users' => array()),
            'meta'     => array('next_token' => null, 'result_count' => 0),
        );
    }

    /**
     * Normalize a twitterapi.io user object to Twitter v2 snake_case shape.
     * Original preserved under '_raw'.
     * @param {array} $u Raw twitterapi.io user object
     * @return {array}
     */
    protected static function normalizeUser(array $u)
    {
        return array(
            'id'                => Q::ifset($u, 'id', null),
            'username'          => Q::ifset($u, 'userName', null),
            'name'              => Q::ifset($u, 'name', null),
            'description'       => Q::ifset($u, 'description', null),
            'location'          => Q::ifset($u, 'location', null),
            'profile_image_url' => Q::ifset($u, 'profilePicture', null),
            'url'               => Q::ifset($u, 'url', null),
            'protected'         => Q::ifset($u, 'protected', false),
            'verified'          => Q::ifset($u, 'isBlueVerified', false),
            'verified_type'     => Q::ifset($u, 'verifiedType', null),
            'created_at'        => Q::ifset($u, 'createdAt', null),
            'pinned_tweet_ids'  => Q::ifset($u, 'pinnedTweetIds', array()),
            'withheld'          => Q::ifset($u, 'withheldInCountries', array()),
            'public_metrics'    => array(
                'followers_count' => Q::ifset($u, 'followers', 0),
                'following_count' => Q::ifset($u, 'following', 0),
                'tweet_count'     => Q::ifset($u, 'statusesCount', 0),
                'media_count'     => Q::ifset($u, 'mediaCount', 0),
                'listed_count'    => 0,
            ),
            '_raw' => $u,
        );
    }

    /**
     * Normalize a twitterapi.io tweet object to Twitter v2 snake_case shape.
     * If the tweet carries an author, it is normalized and stashed under '_author'
     * so buildTweetEnvelope() can hoist it into includes.users.
     * @param {array} $t Raw twitterapi.io tweet object
     * @return {array}
     */
    protected static function normalizeTweet(array $t)
    {
        $normalized = array(
            'id'                  => Q::ifset($t, 'id', null),
            'text'                => Q::ifset($t, 'text', null),
            'created_at'          => Q::ifset($t, 'createdAt', null),
            'lang'                => Q::ifset($t, 'lang', null),
            'source'              => Q::ifset($t, 'source', null),
            'conversation_id'     => Q::ifset($t, 'conversationId', null),
            'in_reply_to_user_id' => Q::ifset($t, 'inReplyToUserId', null),
            'possibly_sensitive'  => Q::ifset($t, 'possiblySensitive', false),
            'entities'            => Q::ifset($t, 'entities', null),
            'public_metrics'      => array(
                'retweet_count'    => Q::ifset($t, 'retweetCount', 0),
                'reply_count'      => Q::ifset($t, 'replyCount', 0),
                'like_count'       => Q::ifset($t, 'likeCount', 0),
                'quote_count'      => Q::ifset($t, 'quoteCount', 0),
                'bookmark_count'   => Q::ifset($t, 'bookmarkCount', 0),
                'impression_count' => Q::ifset($t, 'viewCount', 0),
            ),
            '_raw' => $t,
        );
        if (!empty($t['author'])) {
            $normalized['author_id'] = Q::ifset($t['author'], 'id', null);
            $normalized['_author']   = self::normalizeUser($t['author']);
        }
        return $normalized;
    }

    /**
     * Normalize a raw twitterapi.io tweet array into a standard v2 envelope.
     * Authors are deduplicated and hoisted from tweets into includes.users.
     * The '_author' key is removed from each tweet object after extraction.
     *
     * @param {array}       $rawTweets  Raw tweet objects from twitterapi.io
     * @param {string|null} $nextCursor
     * @return {array} { data, includes: { users }, meta: { next_token, result_count } }
     */
    private static function buildTweetEnvelope(array $rawTweets, $nextCursor = null)
    {
        $tweets   = array_map(array(get_called_class(), 'normalizeTweet'), $rawTweets);
        $usersMap = array();
        foreach ($tweets as &$t) {
            if (!empty($t['_author'])) {
                $uid = $t['_author']['id'];
                if ($uid && !isset($usersMap[$uid])) {
                    $usersMap[$uid] = $t['_author'];
                }
                unset($t['_author']);
            }
        }
        unset($t);
        return array(
            'data'     => $tweets,
            'includes' => array('users' => array_values($usersMap)),
            'meta'     => array(
                'next_token'   => $nextCursor,
                'result_count' => count($tweets),
            ),
        );
    }

    /**
     * Call the twitterapi.io REST API.
     * Auth: single x-api-key header — no OAuth, no token exchange.
     * Config: Users/apps/twitter/{appId}/apiKey
     *
     * @method taiApi
     * @static
     * @param {string} $appId
     * @param {string} $path  e.g. 'user/info', 'list/members', 'tweet/advanced_search'
     * @param {array|string} $params  Query string or array
     * @return {array}
     * @throws {Twitter_Exception_API}
     */
    protected static function taiApi($appId, $path, $params = '')
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        $apiKey = Q::ifset($info, 'apiKey', null);
        if (!$apiKey) {
            throw new Q_Exception_WrongValue(array('field' => 'apiKey'));
        }
        $data     = $params && is_array($params) ? http_build_query($params) : $params;
        $endpoint = "https://api.twitterapi.io/twitter/$path";
        $response = Q_Utils::get("$endpoint?$data", Q_Config::get(
            'Twitter', 'userAgent', 'Qbix', null
        ), [], [
            'x-api-key' => $apiKey,
            'Accept'    => 'application/json',
        ], 30, false);
        $arr = Q::json_decode($response, true);
        if (!empty($arr['status']) && $arr['status'] === 'error') {
            throw new Twitter_Exception_API(array(
                'error_code'  => 400,
                'description' => Q::ifset($arr, 'msg', 'twitterapi.io error'),
            ));
        }
        return $arr;
    }

    /**
     * Build the x.com v2 API endpoint URL.
     * @method endpoint
     * @static
     * @param {string} $appId
     * @param {string} $methodName  e.g. 'users/by', 'lists', 'tweets/search/recent'
     * @param {string|null} $id     Optional path segment appended after $methodName
     * @return {string}
     */
    private static function endpoint($appId, $methodName, $id = null)
    {
        if (empty($id)) {
            return "https://api.x.com/2/$methodName";
        }
        if (is_array($id)) {
            $id = implode(',', $id);
        }
        return "https://api.x.com/2/$methodName/$id";
    }

    /**
     * Read a pre-configured bearer token from local/app.json.
     * @method bearerTokenFromConfig
     * @static
     * @param {string} $appId
     * @return {string|null}
     */
    protected static function bearerTokenFromConfig($appId)
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        return Q::ifset($info, 'bearerToken', null);
    }

    /**
     * Base64-encode the API key + secret for the OAuth2 token endpoint.
     * @method encodeConsumerPayload
     * @static
     * @param {string} $appId
     * @return {string}
     */
    protected static function encodeConsumerPayload($appId)
    {
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        $apiKey = Q::ifset($info, 'apiKey', null);
        $secret = Q::ifset($info, 'secret', null);
        return base64_encode(urlencode($apiKey) . ':' . urlencode($secret));
    }

    /**
     * Obtain an app-only bearer token from x.com via OAuth2 client credentials.
     * @method obtainBearerToken
     * @static
     * @param {string} $appId
     * @return {string|null}
     */
    static function obtainBearerToken($appId)
    {
        $data     = 'grant_type=client_credentials';
        $basic    = self::encodeConsumerPayload($appId);
        $response = Q_Utils::post('https://api.x.com/oauth2/token', $data, Q_Config::get(
            'Twitter', 'userAgent', 'Qbix', null
        ), [], [
            "Authorization: Basic $basic",
            'Host: api.x.com',
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
        ], 30, false);
        $arr = Q::json_decode($response, true);
        if (empty($arr['token_type']) || $arr['token_type'] !== 'bearer') {
            return null;
        }
        return $arr['access_token'];
    }

    /**
     * Call the Twitter v2 API (x.com).
     * @method api
     * @static
     * @param {string} $appId
     * @param {string} $methodName  API path segment, e.g. 'users/by', 'lists'
     * @param {string|null} $id     Optional path segment appended after $methodName
     * @param {array|string} $params  Query string or array
     * @return {array}  Typically { data: ..., includes: ..., meta: ..., errors: ... }
     * @throws {Twitter_Exception_API}
     */
    protected static function api($appId, $methodName, $id, $params = '')
    {
        $endpoint    = self::endpoint($appId, $methodName, $id);
        $data        = $params && is_array($params) ? http_build_query($params) : $params;
        $bearerToken = self::bearerTokenFromConfig($appId);
        if (!$bearerToken) {
            $bearerToken = self::obtainBearerToken($appId); // TODO: cache/persist it
        }
        if (!$bearerToken) {
            throw new Q_Exception_WrongValue(array('field' => 'bearerToken'));
        }
        $response = Q_Utils::get("$endpoint?$data", Q_Config::get(
            'Twitter', 'userAgent', 'Qbix', null
        ), [], [
            'Authorization'   => "Bearer $bearerToken",
            'Host'            => 'api.x.com',
            'Accept'          => 'application/json',
            'Accept-Encoding' => 'gzip',
            'Content-type'    => 'application/json',
        ], 30, false);
        $arr = Q::json_decode($response, true);
        if (!empty($arr['status']) && $arr['status'] != 200) {
            throw new Twitter_Exception_API(array(
                'error_code'  => $arr['status'],
                'description' => Q::ifset($arr, 'detail', 'Unknown error'),
            ));
        }
        return $arr;
    }
}