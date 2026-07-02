<?php
/**
 * ALIGNED NEWS CURATION ENGINE
 * 
 * Complete signal detection, clustering, and ranking system for Twitter/X feeds.
 * Delegates to Twitter::* methods for provider-agnostic reads (twitterapi.io cheap).
 * 
 * FEATURES:
 *   ✓ Fetch from 63+ lists via Twitter class (picks provider automatically)
 *   ✓ Signal keyword detection (finds "launching", "acquired", "breakthrough", etc.)
 *   ✓ Author baseline tracking (finds hidden gems from quiet researchers)
 *   ✓ Incremental fetching (skip seen tweets, 3-10× faster Day 2+)
 *   ✓ Cluster into stories (Jaccard similarity on tokens)
 *   ✓ Rank by HN decay (newest + hottest first)
 *   ✓ Topic categorization (models, agents, hardware, funding, etc.)
 *   ✓ Render newsletter-ready stories
 *   ✓ Config-driven taxonomy and signal keywords
 *
 * USAGE:
 *   $stories = Twitter_Curation::renderAndCreate('scoble_read', ['list1', 'list2', ...]);
 *
 * SCORING FORMULA:
 *   score = (engagement × 1.0 + author × 0.5) × recency × signal_boost × outlier_boost
 * 
 * @class Twitter_Curation
 * @version 2.1
 */
abstract class Twitter_Curation {

    // =========================================================================
    // TOP-LEVEL ENTRY POINTS
    // =========================================================================

    /**
     * Full pipeline: fetch → score → filter → cluster → rank → render
     * Returns display-ready story records with headlines, analysis, topics, links.
     * 
     * If $listIds is empty/null, auto-discovers lists from user.
     * Pass either: Twitter_Curation::renderAndCreate('scoble_read', $listIds, $options)
     * Or:        Twitter_Curation::renderAndCreate('scoble_read', ['user:scobleizer'], $options)
     */
    static function renderAndCreate($appId, array $listIds = array(), $options = array()) {
        $listIds = self::resolveListIds($appId, $listIds, $options);
        $clusters = self::curate($appId, $listIds, $options);
        return self::renderStories($clusters, $options);
    }

    /**
     * Get clusters (cache-friendly).
     * Returns ranked clusters ready to render later.
     * 
     * If $listIds is empty/null, auto-discovers lists from user.
     */
    static function curate($appId, array $listIds = array(), $options = array()) {
        $listIds = self::resolveListIds($appId, $listIds, $options);
        
        $tweets = self::fetchFromLists($appId, $listIds, $options);
        
        // Pass appId through options for outlier detection
        $opts = array_merge($options, array('appId' => $appId));
        $tweets = self::scoreTweets($tweets, $opts);
        
        // Track author baselines
        foreach ($tweets as $t) {
            $authorId = Q::ifset($t, 'author_id', null);
            if ($authorId) {
                $engagement = self::scoreEngagement($t);
                self::updateAuthorBaseline($appId, $authorId, $engagement);
            }
        }
        
        $tweets = self::filterByScore($tweets, Q::ifset($options, 'minScore', 0.3));
        $clusters = self::clusterTweets($tweets, $options);

        foreach ($clusters as &$c) {
            $c['topics'] = self::categorize($c, $options, $appId);
        }
        unset($c);
        return self::rankClusters($clusters, $options);
    }

    // =========================================================================
    // LIST DISCOVERY
    // =========================================================================

    /**
     * Resolve list IDs. If passed user identifiers, fetches their lists.
     * 
     * Usage:
     *   ['123', '456', ...]                    → Use as-is
     *   ['user:scobleizer', ...]               → Fetch lists from @scobleizer
     *   ['user_id:1234567890', ...]            → Fetch lists from user ID
     *   []                                      → Empty = skip fetching
     *
     * Returns array of list IDs ready for fetchFromLists()
     */
    static function resolveListIds($appId, array $listIds = array(), $options = array()) {
        if (empty($listIds)) {
            Q::log("Twitter_Curation: No lists provided, skipping fetch");
            return array();
        }

        $resolved = array();
        $usersToFetch = array();

        foreach ($listIds as $item) {
            if (strpos($item, 'user:') === 0) {
                // Format: user:username
                $username = substr($item, 5);
                $usersToFetch['username'][] = $username;
            } elseif (strpos($item, 'user_id:') === 0) {
                // Format: user_id:12345
                $userId = substr($item, 8);
                $usersToFetch['id'][] = $userId;
            } else {
                // Assume it's a list ID
                $resolved[] = $item;
            }
        }

        // Fetch lists for users if needed
        if (!empty($usersToFetch)) {
            $userLists = self::fetchUserLists($appId, $usersToFetch, $options);
            $resolved = array_merge($resolved, $userLists);
        }

        return array_unique($resolved);
    }

    /**
     * Fetch all lists for given users.
     * $users format: array('username' => ['user1', 'user2'], 'id' => ['123', '456'])
     */
    static function fetchUserLists($appId, array $users, $options = array()) {
        $listIds = array();

        // Get lists by username
        if (!empty($users['username'])) {
            foreach ($users['username'] as $username) {
                try {
                    Q::log("Twitter_Curation: Fetching lists for @$username");
                    $resp = Twitter::getUserListsByUsername($appId, $username, array(
                        'max_results' => 100,
                    ));
                    $lists = Q::ifset($resp, 'data', array());
                    foreach ($lists as $list) {
                        $id = Q::ifset($list, 'id', null);
                        if ($id) $listIds[] = $id;
                    }
                } catch (Exception $e) {
                    Q::log("Twitter_Curation: Failed to fetch lists for @$username: " . $e->getMessage());
                }
            }
        }

        // Get lists by user ID
        if (!empty($users['id'])) {
            foreach ($users['id'] as $userId) {
                try {
                    Q::log("Twitter_Curation: Fetching lists for user $userId");
                    $resp = Twitter::getUserListsById($appId, $userId, array(
                        'max_results' => 100,
                    ));
                    $lists = Q::ifset($resp, 'data', array());
                    foreach ($lists as $list) {
                        $id = Q::ifset($list, 'id', null);
                        if ($id) $listIds[] = $id;
                    }
                } catch (Exception $e) {
                    Q::log("Twitter_Curation: Failed to fetch lists for user $userId: " . $e->getMessage());
                }
            }
        }

        Q::log("Twitter_Curation: Discovered " . count($listIds) . " lists");
        return $listIds;
    }

    // =========================================================================
    // FETCHING (delegates to Twitter class)
    // =========================================================================

    /**
     * Fetch from multiple lists via Twitter::getListTweets().
     * Delegates to Twitter class so it uses configured provider
     * (twitterapi.io = cheap, X.com = fallback).
     */
    static function fetchFromLists($appId, array $listIds, $options = array()) {
        $maxPages = Q::ifset($options, 'maxPagesPerList', 3);
        $maxAgeHours = Q::ifset($options, 'maxAgeHours', 24);
        $cutoff = time() - $maxAgeHours * 3600;
        $byId = array();

        foreach ($listIds as $listId) {
            $cursor = null;
            $pages = 0;
            $stop = false;
            $sinceId = self::getLastSeenId($appId, $listId);
            $maxSeen = $sinceId;

            while ($pages < $maxPages && !$stop) {
                $opts = array();
                if ($cursor) {
                    $opts['next_token'] = $cursor;  // X.com
                    $opts['cursor'] = $cursor;      // twitterapi.io
                }

                try {
                    // Twitter class picks the provider automatically
                    $resp = Twitter::getListTweets($appId, $listId, $opts);
                } catch (Exception $e) {
                    Q::log("Twitter_Curation: list $listId page $pages failed: " . $e->getMessage());
                    break;
                }

                $tweets = Q::ifset($resp, 'data', array());
                $userById = array();
                foreach (Q::ifset($resp, 'includes', 'users', array()) as $u) {
                    if (!empty($u['id'])) $userById[$u['id']] = $u;
                }

                foreach ($tweets as $t) {
                    $created = strtotime(Q::ifset($t, 'created_at', '@0'));
                    if ($created && $created < $cutoff) {
                        $stop = true;
                        break;
                    }
                    if ($sinceId && self::compareIds(Q::ifset($t, 'id', '0'), $sinceId) <= 0) {
                        $stop = true;
                        break;
                    }

                    $authorId = Q::ifset($t, 'author_id', null);
                    if ($authorId && isset($userById[$authorId])) {
                        $t['_author'] = $userById[$authorId];
                    }
                    $t['_sourceList'] = $listId;

                    $tid = Q::ifset($t, 'id', null);
                    if (!$tid) continue;
                    $byId[$tid] = $t;
                    if (!$maxSeen || self::compareIds($tid, $maxSeen) > 0) {
                        $maxSeen = $tid;
                    }
                }

                $cursor = Q::ifset($resp, 'meta', 'next_token', null)
                       || Q::ifset($resp, 'next_cursor', null);
                if (!$cursor) $stop = true;
                $pages++;
            }

            if ($maxSeen && $maxSeen !== $sinceId) {
                self::setLastSeenId($appId, $listId, $maxSeen);
            }
        }
        return array_values($byId);
    }

    private static function compareIds($a, $b) {
        $a = (string)$a;
        $b = (string)$b;
        if (strlen($a) !== strlen($b)) {
            return strlen($a) < strlen($b) ? -1 : 1;
        }
        return strcmp($a, $b) <=> 0;
    }

    // =========================================================================
    // SCORING (Phase 1)
    // =========================================================================

    /**
     * Score a single tweet.
     * score = (engagement + author) × recency × signal_keywords × outlier_boost
     */
    static function scoreTweet($tweet, $options = array()) {
        $appId = Q::ifset($options, 'appId', null);
        $we = Q::ifset($options, 'weightEngagement', 1.0);
        $wa = Q::ifset($options, 'weightAuthor', 0.5);
        
        $e = self::scoreEngagement($tweet);
        $a = self::scoreAuthor($tweet);
        $r = self::scoreRecency($tweet, $options);
        $s = self::scoreSignalKeywords($tweet, $appId);
        $o = self::outlierMultiplier($appId, $tweet);
        
        return ($e * $we + $a * $wa) * $r * $s * $o;
    }

    static function scoreEngagement($tweet) {
        $m = Q::ifset($tweet, 'public_metrics', array());
        $raw = Q::ifset($m, 'like_count', 0)
             + 2 * Q::ifset($m, 'retweet_count', 0)
             + 3 * Q::ifset($m, 'reply_count', 0)
             + 2.5 * Q::ifset($m, 'quote_count', 0)
             + 1.5 * Q::ifset($m, 'bookmark_count', 0);
        return log10(1 + $raw);
    }

    static function scoreAuthor($tweet) {
        $a = Q::ifset($tweet, '_author', array());
        $followers = Q::ifset($a, 'public_metrics', 'followers_count', 0);
        $verified = Q::ifset($a, 'verified', false) ? 0.3 : 0.0;
        return log10(1 + $followers) / 6 + $verified;
    }

    static function scoreRecency($tweet, $options = array()) {
        $createdAt = Q::ifset($tweet, 'created_at', null);
        if (!$createdAt) return 0.5;
        $age = (time() - strtotime($createdAt)) / 3600;
        if ($age < 0) $age = 0;
        $g = Q::ifset($options, 'recencyGravity', 1.5);
        return pow(2 / ($age + 2), $g);
    }

    /**
     * PHASE 1: Signal keyword detection.
     * Returns 1.0-1.50 multiplier based on announcement keywords found.
     * Reads from config if available, falls back to defaults.
     */
    static function scoreSignalKeywords($tweet, $appId = null) {
        if (!$appId) $appId = Q::app();
        
        $text = mb_strtolower($tweet['text'], 'UTF-8');
        $keywords = self::getSignalKeywords($appId);
        $found = 0;
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) $found++;
        }
        if ($found === 0) return 1.0;
        return 1.0 + min($found * 0.15, 0.50);
    }

    /**
     * Get signal keywords from config or defaults.
     * Reads from Users/apps/twitter/{appId}/signalKeywords.
     * Falls back to built-in defaults if not configured.
     */
    static function getSignalKeywords($appId = null) {
        if (!$appId) $appId = Q::app();
        
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        
        return Q::ifset($info, 'signalKeywords', array(
            'launching', 'launch', 'released', 'release', 'announce', 'announcement',
            'debut', 'introducing', 'unveil', 'showcase', 'reveal', 'ships', 'shipped',
            'out now', 'live', 'available', 'breakthrough', 'breakthrough!', 'first time',
            'first-ever', 'novel', 'big step', 'major step', 'sota', 'state of art',
            'benchmark', 'beat', 'exceeded', 'outperform', 'milestone', 'record',
            'raised', 'funding', 'series', 'seed', 'acquired', 'acquires', 'acquisition',
            'merger', 'invest', 'invested', 'valuation', 'unicorn', 'exit', 'ipo',
            'going public', 'stealth', 'coming out', 'founded', 'startup', 'joining',
            'joins', 'hired', 'appointing', 'appoints', 'cto', 'ceo', 'new leader',
            'regulation', 'regulatory', 'executive order', 'ban', 'banned', 'policy',
            'compliance', 'approved', 'arxiv', 'paper', 'preprint', 'research',
            'study shows', 'findings', 'dataset', 'llm', 'model', 'gpt', 'claude',
            'gemini', 'multimodal', 'agentic', 'agent', 'mcp', 'tool use', 'chip',
            'gpu', 'tpu', 'silicon', 'wafer', 'foundry', 'groundbreaking', 'impressive',
            'mind-blowing', 'insane', 'amazing', 'wow', 'holy'
        ));
    }

    /**
     * PHASE 1: Author outlier detection.
     * Returns 1.0-2.0 boost based on how unusual this post is for the author.
     */
    static function outlierMultiplier($appId, $tweet) {
        if (!$appId) return 1.0;
        $authorId = Q::ifset($tweet, 'author_id', null);
        if (!$authorId) return 1.0;
        
        $base = self::authorBaseline($appId, $authorId);
        $avg = Q::ifset($base, 'avgEngagement', 0);
        if (!$avg) return 1.0;
        
        $now = self::scoreEngagement($tweet);
        $ratio = $now / $avg;
        if ($ratio <= 1.0) return 1.0;
        return 1.0 + log10(1 + ($ratio - 1));
    }

    static function scoreTweets(array $tweets, $options = array()) {
        foreach ($tweets as &$t) {
            $t['_score'] = self::scoreTweet($t, $options);
        }
        unset($t);
        return $tweets;
    }

    // =========================================================================
    // FILTERING
    // =========================================================================

    static function filterByScore(array $tweets, $minScore = 0.3) {
        $out = array();
        foreach ($tweets as $t) {
            if (Q::ifset($t, '_score', 0) >= $minScore) $out[] = $t;
        }
        return $out;
    }

    static function filterByEngagement(array $tweets, $minTotal = 10) {
        $out = array();
        foreach ($tweets as $t) {
            $m = Q::ifset($t, 'public_metrics', array());
            $total = Q::ifset($m, 'like_count', 0)
                   + Q::ifset($m, 'retweet_count', 0)
                   + Q::ifset($m, 'reply_count', 0);
            if ($total >= $minTotal) $out[] = $t;
        }
        return $out;
    }

    static function filterByRecency(array $tweets, $maxAgeHours = 24) {
        $cutoff = time() - $maxAgeHours * 3600;
        $out = array();
        foreach ($tweets as $t) {
            $created = strtotime(Q::ifset($t, 'created_at', '@0'));
            if ($created && $created >= $cutoff) $out[] = $t;
        }
        return $out;
    }

    // =========================================================================
    // CLUSTERING
    // =========================================================================

    static function clusterTweets(array $tweets, $options = array()) {
        $threshold = Q::ifset($options, 'similarityThreshold', 0.3);
        $clusters = array();

        foreach ($tweets as $t) {
            $tokens = self::tokenize(Q::ifset($t, 'text', ''));
            if (empty($tokens)) continue;

            $bestI = -1;
            $bestSim = 0;
            foreach ($clusters as $i => $c) {
                $sim = self::jaccard($tokens, $c['_tokenUnion']);
                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $bestI = $i;
                }
            }

            if ($bestSim >= $threshold) {
                $clusters[$bestI]['tweets'][] = $t;
                $clusters[$bestI]['_tokenUnion'] = array_values(array_unique(
                    array_merge($clusters[$bestI]['_tokenUnion'], $tokens)
                ));
            } else {
                $clusters[] = array(
                    'id' => 'c_' . count($clusters),
                    'tweets' => array($t),
                    '_tokenUnion' => $tokens,
                );
            }
        }

        foreach ($clusters as &$c) {
            usort($c['tweets'], function ($a, $b) {
                return Q::ifset($b, '_score', 0) <=> Q::ifset($a, '_score', 0);
            });
            $c['topTweet'] = $c['tweets'][0];
            $c['score'] = array_sum(array_map(function ($t) {
                return Q::ifset($t, '_score', 0);
            }, $c['tweets']));
            $c['terms'] = array_slice($c['_tokenUnion'], 0, 10);

            $sources = array();
            $authors = array();
            foreach ($c['tweets'] as $t) {
                $sl = Q::ifset($t, '_sourceList', null);
                if ($sl && !in_array($sl, $sources)) $sources[] = $sl;
                $u = Q::ifset($t, '_author', 'username', null);
                if ($u && !in_array($u, $authors)) $authors[] = $u;
            }
            $c['sources'] = $sources;
            $c['authors'] = $authors;

            unset($c['_tokenUnion']);
        }
        unset($c);
        return $clusters;
    }

    static function tokenize($text) {
        if (!$text) return array();
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('!https?://\S+!u', ' ', $text);
        $text = preg_replace('/[@#]/u', '', $text);
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = self::stopwords();
        $out = array();
        foreach ($tokens as $tok) {
            if (mb_strlen($tok, 'UTF-8') < 3) continue;
            if (isset($stop[$tok])) continue;
            if (is_numeric($tok)) continue;
            $out[] = $tok;
        }
        return array_values(array_unique($out));
    }

    static function jaccard(array $a, array $b) {
        if (empty($a) || empty($b)) return 0.0;
        $sa = array_flip($a);
        $sb = array_flip($b);
        $intersection = count(array_intersect_key($sa, $sb));
        $union = count($sa) + count($sb) - $intersection;
        return $union > 0 ? $intersection / $union : 0.0;
    }

    static function stopwords() {
        return array_flip(array(
            'the', 'and', 'for', 'that', 'with', 'this', 'from', 'have', 'will', 'your',
            'about', 'what', 'when', 'where', 'which', 'their', 'they', 'them', 'these',
            'those', 'then', 'than', 'just', 'like', 'more', 'some', 'here', 'there',
            'been', 'being', 'also', 'into', 'only', 'very', 'much', 'many', 'most',
            'over', 'such', 'because', 'while', 'would', 'could', 'should', 'might',
            'rt', 'via', 'http', 'https', 'com', 'www', 'tweet', 'tweets', 'post', 'posts',
            'one', 'two', 'say', 'says', 'said', 'make', 'made', 'get', 'got', 'new'
        ));
    }

    // =========================================================================
    // RANKING
    // =========================================================================

    static function rankClusters(array $clusters, $options = array()) {
        $g = Q::ifset($options, 'rankGravity', 1.8);
        foreach ($clusters as &$c) {
            $newest = 0;
            foreach ($c['tweets'] as $t) {
                $ts = strtotime(Q::ifset($t, 'created_at', '@0'));
                if ($ts > $newest) $newest = $ts;
            }
            $age = $newest > 0 ? max(0, (time() - $newest) / 3600) : 24;
            $s = max(0, Q::ifset($c, 'score', 0) - 1);
            $c['rank'] = $s / pow($age + 2, $g);
        }
        unset($c);
        usort($clusters, function ($a, $b) {
            return $b['rank'] <=> $a['rank'];
        });
        return $clusters;
    }

    // =========================================================================
    // CATEGORIZATION
    // =========================================================================

    static function categorize($cluster, $options = array(), $appId = null) {
        $tax = Q::ifset($options, 'topics', null);
        if (!is_array($tax)) {
            if (!$appId) $appId = Q::app();
            $tax = self::getTaxonomy($appId);
        }
        $termSet = array_flip(Q::ifset($cluster, 'terms', array()));
        $top = Q::ifset($cluster, 'topTweet', array());
        foreach (self::tokenize(Q::ifset($top, 'text', '')) as $tok) {
            $termSet[$tok] = true;
        }
        $hits = array();
        foreach ($tax as $topic => $keywords) {
            $n = 0;
            foreach ($keywords as $kw) {
                if (isset($termSet[mb_strtolower($kw, 'UTF-8')])) $n++;
            }
            if ($n > 0) $hits[$topic] = $n;
        }
        arsort($hits);
        return array_keys($hits);
    }

    /**
     * Get taxonomy from config or defaults.
     * Reads from Users/apps/twitter/{appId}/taxonomy.
     * Falls back to built-in defaults if not configured.
     */
    static function getTaxonomy($appId = null) {
        if (!$appId) $appId = Q::app();
        
        list($appId, $info) = Users::appInfo("twitter", $appId, true);
        
        return Q::ifset($info, 'taxonomy', array(
            'Models' => array('model', 'models', 'llama', 'gpt', 'claude', 'gemini', 'grok', 'mistral', 'release'),
            'Agents' => array('agent', 'agents', 'agentic', 'autonomous', 'tool', 'tools', 'mcp'),
            'Hardware' => array('chip', 'chips', 'gpu', 'tpu', 'silicon', 'nvidia', 'amd', 'blackwell'),
            'Safety' => array('safety', 'alignment', 'rlhf', 'jailbreak', 'redteam', 'sandbox'),
            'Funding' => array('funding', 'raised', 'valuation', 'series', 'investor', 'seed', 'ipo'),
            'Regulation' => array('regulation', 'regulatory', 'executive', 'order', 'law', 'policy'),
            'Infra' => array('infrastructure', 'cloud', 'aws', 'azure', 'gcp', 'datacenter'),
            'Multimodal' => array('multimodal', 'vision', 'image', 'images', 'video', 'audio'),
            'Open' => array('open', 'weights', 'huggingface', 'license', 'apache'),
            'Enterprise' => array('enterprise', 'copilot', 'saas', 'workflow', 'productivity'),
        ));
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    static function generateHeadline($cluster, $options = array()) {
        $gen = Q::ifset($options, 'headlineGenerator', null);
        if (is_callable($gen)) {
            return (string) call_user_func($gen, $cluster, $options);
        }
        $top = Q::ifset($cluster, 'topTweet', array());
        $text = Q::ifset($top, 'text', '');
        $text = preg_replace('!https?://\S+!u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (preg_match('/^(.{20,140}?[.!?])\s/u', $text, $m)) {
            return trim($m[1]);
        }
        return mb_substr($text, 0, 140, 'UTF-8');
    }

    static function generateAnalysis($cluster, $options = array()) {
        $gen = Q::ifset($options, 'analysisGenerator', null);
        if (is_callable($gen)) {
            return (string) call_user_func($gen, $cluster, $options);
        }
        $parts = array();
        foreach (array_slice(Q::ifset($cluster, 'tweets', array()), 0, 3) as $t) {
            $text = Q::ifset($t, 'text', '');
            $text = preg_replace('!https?://\S+!u', '', $text);
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text !== '') $parts[] = $text;
        }
        return implode(' · ', $parts);
    }

    static function renderStory($cluster, $options = array()) {
        $top = Q::ifset($cluster, 'topTweet', array());
        $topUsername = Q::ifset($top, '_author', 'username', null);
        $topId = Q::ifset($top, 'id', null);
        $topUrl = ($topUsername && $topId) ? "https://x.com/$topUsername/status/$topId" : null;

        $attribution = Q::ifset($options, 'attributionPrefix', 'From curated lists');

        return array(
            'id' => Q::ifset($cluster, 'id', null),
            'headline' => self::generateHeadline($cluster, $options),
            'analysis' => self::generateAnalysis($cluster, $options),
            'attribution' => $attribution,
            'topics' => Q::ifset($cluster, 'topics', array()),
            'sources' => Q::ifset($cluster, 'sources', array()),
            'authors' => Q::ifset($cluster, 'authors', array()),
            'topTweet' => array(
                'id' => $topId,
                'url' => $topUrl,
                'username' => $topUsername,
                'text' => Q::ifset($top, 'text', null),
                'createdAt' => Q::ifset($top, 'created_at', null),
            ),
            'tweetCount' => count(Q::ifset($cluster, 'tweets', array())),
            'score' => Q::ifset($cluster, 'score', 0),
            'rank' => Q::ifset($cluster, 'rank', 0),
            'timestamp' => Q::ifset($top, 'created_at', null),
        );
    }

    static function renderStories(array $rankedClusters, $options = array()) {
        $out = array();
        foreach ($rankedClusters as $c) {
            $out[] = self::renderStory($c, $options);
        }
        return $out;
    }

    // =========================================================================
    // DIGEST
    // =========================================================================

    static function buildDigest(array $rankedClusters, $options = array()) {
        $limit = Q::ifset($options, 'limit', 10);
        $topics = Q::ifset($options, 'topicsFilter', null);
        $minTweets = Q::ifset($options, 'minTweets', 1);
        $title = Q::ifset($options, 'title', 'Daily Briefing');
        $subtitle = Q::ifset($options, 'subtitle', date('F j, Y'));

        $stories = array();
        $selected = array();
        foreach ($rankedClusters as $c) {
            if (count(Q::ifset($c, 'tweets', array())) < $minTweets) continue;
            if (is_array($topics) && !empty($topics)) {
                $cT = Q::ifset($c, 'topics', array());
                if (empty(array_intersect($cT, $topics))) continue;
            }
            $stories[] = self::renderStory($c, $options);
            $selected[] = $c;
            if (count($stories) >= $limit) break;
        }

        $briefing = null;
        if (!empty(Q::ifset($options, 'includeBriefing', false)) && !empty($selected)) {
            $gen = Q::ifset($options, 'briefingGenerator', null);
            if (is_callable($gen)) {
                $briefing = (string) call_user_func($gen, $selected, $options);
            } else {
                $heads = array();
                foreach (array_slice($selected, 0, 3) as $c) {
                    $heads[] = self::generateHeadline($c, $options);
                }
                $briefing = implode(' · ', $heads);
            }
        }

        return array(
            'title' => $title,
            'subtitle' => $subtitle,
            'generatedAt' => date('c'),
            'briefing' => $briefing,
            'stories' => $stories,
            'storyCount' => count($stories),
        );
    }

    // =========================================================================
    // PERSISTENT STATE (Streams-backed)
    // =========================================================================

    static function getLastSeenId($appId, $listId) {
        try {
            $stream = Streams::fetchOne($appId, $appId, "curation/state/$listId", array(
                'withContent' => false,
                'checkAccess' => false,
            ));
            if (!$stream) return null;
            return $stream->getAttribute('lastSeenId');
        } catch (Exception $e) {
            return null;
        }
    }

    static function setLastSeenId($appId, $listId, $tweetId) {
        try {
            $streamName = "curation/state/$listId";
            $stream = Streams::fetchOne($appId, $appId, $streamName, array(
                'checkAccess' => false,
            ));

            if (!$stream) {
                $stream = Streams::create($appId, $appId, $streamName, array(
                    'title' => "Curation state for list $listId",
                    'content' => "Tracks lastSeenId for incremental fetching",
                ), array('publish' => false));
            }

            $stream->setAttribute('lastSeenId', (string)$tweetId);
            $stream->save();
        } catch (Exception $e) {
            Q::log("Warning: could not persist lastSeenId for $appId/$listId: " . $e->getMessage());
        }
    }

    static function authorBaseline($appId, $authorId) {
        try {
            $stream = Streams::fetchOne($appId, $appId, "curation/author/$authorId", array(
                'withContent' => false,
                'checkAccess' => false,
            ));
            if (!$stream) {
                return array('avgEngagement' => 0.0, 'postCount' => 0, 'lastUpdated' => null);
            }
            return array(
                'avgEngagement' => floatval($stream->getAttribute('avgEngagement') ?: 0),
                'postCount' => intval($stream->getAttribute('postCount') ?: 0),
                'lastUpdated' => $stream->getAttribute('lastUpdated'),
            );
        } catch (Exception $e) {
            return array('avgEngagement' => 0.0, 'postCount' => 0, 'lastUpdated' => null);
        }
    }

    static function updateAuthorBaseline($appId, $authorId, $engagement) {
        try {
            $streamName = "curation/author/$authorId";
            $stream = Streams::fetchOne($appId, $appId, $streamName, array(
                'checkAccess' => false,
            ));

            $base = self::authorBaseline($appId, $authorId);
            $n = $base['postCount'];
            $newAvg = ($base['avgEngagement'] * $n + $engagement) / ($n + 1);

            if (!$stream) {
                $stream = Streams::create($appId, $appId, $streamName, array(
                    'title' => "Author baseline for @$authorId",
                    'content' => "Tracks per-author engagement for outlier detection",
                ), array('publish' => false));
            }

            $stream->setAttribute('avgEngagement', $newAvg);
            $stream->setAttribute('postCount', $n + 1);
            $stream->setAttribute('lastUpdated', date('c'));
            $stream->save();
        } catch (Exception $e) {
            Q::log("Warning: could not update author baseline for $authorId: " . $e->getMessage());
        }
    }
}