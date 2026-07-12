# Twitter Plugin — LLM Coding Primer

Supplement to Q Framework and Users primers. Covers the dual-provider read API,
user-context write API, OAuth 2.0 PKCE auth, and the Curation engine.

---

## 1. Reading Data (App-Context, No User Login)

```php
$appId = 'MyApp'; // matches Users/apps/twitter/{appId}

// Look up users by username
$result = Twitter::byUsernames($appId, array('elonmusk', 'openai'));
// Returns: {data: [{id, username, name, public_metrics, ...}, ...]}

// User timeline
$result = Twitter::getUserTimeline($appId, 'openai', array('max_results' => 20));
// Returns: {data: [{id, text, created_at, public_metrics, ...}], includes: {users}, meta: {next_token}}

// Followers / following
$result = Twitter::getUserFollowers($appId, 'openai', array('max_results' => 100));
$result = Twitter::getUserFollowing($appId, 'openai');

// Search recent tweets
$result = Twitter::searchRecentTweets($appId, 'AI safety from:openai', array(
    'max_results' => 50,
    'queryType'   => 'Latest'  // twitterapi.io: 'Latest' or 'Top'
));

// Tweet details, replies, retweeters, quotes
$result = Twitter::getTweets($appId, array('123456789', '987654321'));
$result = Twitter::getTweetReplies($appId, '123456789');
$result = Twitter::getTweetRetweeters($appId, '123456789');
$result = Twitter::getTweetQuotes($appId, '123456789');

// Lists
$result = Twitter::getListTweets($appId, $listId);
$result = Twitter::getListMembers($appId, $listId);
$result = Twitter::getListFollowers($appId, $listId);
$result = Twitter::getUserLists($appId, 'scobleizer');  // x.com only

// Trends (twitterapi.io only)
$result = Twitter::getTrends($appId, 1);  // woeid 1 = worldwide

// All responses normalized to: {data: [...], includes: {users: [...]}, meta: {next_token, result_count}}
// Pagination: pass next_token (x.com) or cursor (twitterapi.io)
```

---

## 2. Writing Data (User-Context, OAuth Token)

```php
// Post a tweet
Twitter::postTweet($appId, 'Hello world!', array(
    'reply_to'      => $tweetId,          // make it a reply
    'quote_tweet_id'=> $quotedTweetId,    // quote tweet
    'media_ids'     => array($mediaId),   // attach media
    'poll_options'  => array('Yes', 'No'),
    'poll_duration_minutes' => 1440
), $userId);  // null = logged-in user

// Engagement actions
Twitter::likeTweet($appId, $tweetId, $userId);
Twitter::unlikeTweet($appId, $tweetId, $userId);
Twitter::retweet($appId, $tweetId, $userId);
Twitter::unretweet($appId, $tweetId, $userId);
Twitter::bookmarkTweet($appId, $tweetId, $userId);

// Social actions
Twitter::followUser($appId, $targetUserId, $userId);
Twitter::unfollowUser($appId, $targetUserId, $userId);
Twitter::muteUser($appId, $targetUserId, $userId);
Twitter::blockUser($appId, $targetUserId, $userId);

// Moderation
Twitter::hideReply($appId, $tweetId, $userId);
Twitter::deleteTweet($appId, $tweetId, $userId);

// Media upload + tweet
$media = Twitter::uploadMedia($appId, '/path/to/image.jpg', array(), $userId);
Twitter::postTweet($appId, 'Check this out', array(
    'media_ids' => array($media['data']['id'])
), $userId);

// Direct messages
Twitter::sendDirectMessage($appId, $recipientXid, 'Hello!', array(), $userId);

// Get authenticated user's profile
$me = Twitter::getMe($appId, $userId);

// All return null if no ExternalFrom row found for the user
```

---

## 3. Curation Engine

```php
// Full pipeline: fetch → score → filter → cluster → rank → render
$stories = Twitter_Curation::renderAndCreate($appId, array(
    '1234567890',           // list IDs
    '9876543210',
    'user:scobleizer'       // auto-discover lists from @scobleizer
), array(
    'maxPagesPerList' => 3,
    'maxAgeHours'     => 24,
    'minScore'        => 0.3,
    'recencyGravity'  => 1.5,
    'similarityThreshold' => 0.3,
    'rankGravity'     => 1.8
));
// Returns array of story records:
// {id, headline, analysis, topics[], authors[], sources[],
//  topTweet: {id, url, username, text}, tweetCount, score, rank, timestamp}

// Just get ranked clusters (cache-friendly)
$clusters = Twitter_Curation::curate($appId, $listIds, $options);

// Build a digest (newsletter-ready)
$digest = Twitter_Curation::buildDigest($clusters, array(
    'limit'        => 10,
    'topicsFilter' => array('Models', 'Agents'),
    'minTweets'    => 2,
    'title'        => 'AI Daily Briefing'
));
// Returns: {title, subtitle, generatedAt, stories[], storyCount, briefing}

// Custom headline/analysis generators (e.g. LLM-powered):
$stories = Twitter_Curation::renderAndCreate($appId, $listIds, array(
    'headlineGenerator' => function($cluster, $opts) { return $llm->summarize(...); },
    'analysisGenerator' => function($cluster, $opts) { return $llm->analyze(...); }
));

// Scoring formula:
// score = (engagement × 1.0 + author × 0.5) × recency × signal_boost × outlier_boost
// engagement = log10(1 + likes + 2×RTs + 3×replies + 2.5×quotes + 1.5×bookmarks)
// author = log10(1 + followers)/6 + (verified ? 0.3 : 0)
// recency = (2/(ageHours+2))^gravity
// signal_boost = 1.0 + min(keywordHits × 0.15, 0.50)
// outlier = 1.0 + log10(1 + (currentEngagement/avgEngagement - 1))
```

---

## 4. Authentication (OAuth 2.0 PKCE via Intent)

```php
// Client-side: trigger OAuth popup
// Q.Users.authenticate('twitter');
// Twitter.js provisions a Users/authenticate intent on init (token cached)
// authenticate.twitter calls Users.OAuth.start, which opens popup at:
//   Users/oauth?intent={token}&platform=twitter

// OAuth flow (two server phases + client completion):
// Phase 1: handler stashes platform + PKCE verifier on intent, redirects to X
// Phase 2: X redirects back with code, handler exchanges code for tokens,
//          calls fetchMe() to get xid, stages tokens on ExternalFrom row
//          (tokens never go on the intent), completes intent with xid, closes popup
// Client: opener detects popup.closed, checks intent status, gets xid,
//         calls Users.authenticate('twitter') with {intent: token}

// Server-side authenticate (called by Users::authenticate):
$ef = Users_ExternalFrom_Twitter::authenticate($appId);
// Reads intent → extracts xid → builds fresh ExternalFrom (userId unset)
// Returns it for Users::authenticate() to stamp userId and save
// Tokens live on the ExternalFrom row, not the intent

// Full-page flow (mobile, no popup): phase 2 calls Users::authenticate()
// itself before redirecting, since no opener will do it

// Fetch authenticated user's profile
$me = $ef->getMe();  // GET /2/users/me → {id, name, username, profile_image_url}
// me() memoizes: reads persisted profile from extra, falls back to live API call

// Resolve a user's ExternalFrom row (for user-context calls)
$ef = Twitter::userExternalFrom($appId, $userId);
// Two-hop: ExternalTo(userId, 'twitter', appId) → xid → ExternalFrom(platform, appId, xid)
// ExternalFrom is authoritative for the access token

// Icon URLs from profile (pure transform, no API call)
$icons = Twitter::userIcon($profile);  // size => url map (all sizes → same URL)
$url = Twitter::userIconUrl($profile); // upgrades _normal → _400x400
```

---

## 5. Provider Configuration

```php
// x.com (default) — uses Bearer token
// Config: bearerToken (pre-configured) or obtained via client credentials
// Rate limits: standard X API v2 tiers

// twitterapi.io — cheaper third-party proxy
// Config: provider = "twitterapi.io", apiKey = "YOUR_KEY"
// Auth: x-api-key header, no OAuth needed for reads
// Some endpoints differ (getUserLists, getTrends only on twitterapi.io)

// Both providers normalize to the same Twitter v2 response format
// Callers never need to know which provider is active
```

---

## 6. Common Mistakes

| Wrong | Right |
|-------|-------|
| Calling write methods without user's OAuth token | Write methods need a `Users_ExternalFrom_Twitter` row with `accessToken`; returns null otherwise |
| Missing write scopes in OAuth config | Add `tweet.write`, `like.write`, `follows.write`, etc. to `oauth2.scopes` as needed; `offline.access` for refresh tokens |
| Expecting likes/bookmarks on Free tier | Some write endpoints (likes, bookmarks) require paid X API tiers; methods pass X's permission error through unchanged |
| Using twitterapi.io for `getUserLists` | Only available on x.com; twitterapi.io has no equivalent |
| Using x.com for `getTrends` | Only available on twitterapi.io; x.com v2 has no trends endpoint |
| Hardcoding x.com API URLs | Use `Twitter::` methods — they route to the configured provider automatically |
| Passing raw `cursor` for x.com pagination | x.com uses `next_token`; twitterapi.io uses `cursor`; the methods accept both |
| Putting tokens on the intent | Tokens live only on the `Users_ExternalFrom` row; the intent carries only the public xid |
| Mismatched `redirectUri` | Must match exactly what's registered in the X developer portal |