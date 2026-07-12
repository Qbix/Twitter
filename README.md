# Twitter Plugin

X/Twitter API integration, OAuth 2.0 PKCE authentication, provider-agnostic read/write API, and a full news curation engine for the Qbix platform. The plugin supports two API providers — the official X API (x.com v2, bearer token) and twitterapi.io (cheaper third-party proxy) — normalizing all responses to the Twitter v2 envelope format so callers are fully provider-agnostic.

## Core Concepts

### Architecture

The Twitter plugin follows the same split as Telegram: the platform adapter class `Users_ExternalFrom_Twitter` lives under the Users plugin namespace (since it extends `Users_ExternalFrom`), while the bootstrap, config, assets, and the `Twitter` read/write API class live in the Twitter plugin. Because X uses a per-user OAuth token rather than a global bot token, `Users_ExternalFrom_Twitter` carries the access token and does the user-context API work itself (getMe, DMs, posting, likes). The `Twitter` class handles app-context reads (timelines, search — bearer token or twitterapi.io key) and provides thin static conveniences for user-context actions that resolve a `Users_ExternalFrom_Twitter` row and delegate.

Token resolution follows a two-hop path: `Twitter::userExternalFrom($appId, $userId)` looks up `Users_ExternalTo` by `(userId, platform='twitter', appId)` to get the xid, then fetches `Users_ExternalFrom` by its primary key — the From row is authoritative for the access token.

### Dual-Provider Architecture

Every read method on the `Twitter` class checks `Users/apps/twitter/{appId}/provider` to determine which backend to use. If `provider` = `"twitterapi.io"`, calls go to `api.twitterapi.io` with an `x-api-key` header. Otherwise, calls go to `api.x.com/2/` with a Bearer token (configured or obtained via OAuth2 client credentials). All responses are normalized to the Twitter v2 envelope: `{data: [...], includes: {users: [...]}, meta: {next_token, result_count}}`. The `normalizeUser()` and `normalizeTweet()` methods translate twitterapi.io's camelCase fields to Twitter v2 snake_case, preserving the raw response under a `_raw` key.

### Authentication (OAuth 2.0 PKCE)

Authentication is driven by the `Users_Intent` system, matching the Telegram pattern. The OAuth round trip runs in a popup against one generic `Users/oauth` handler; the opener logs itself in afterward through the normal `Users.authenticate('twitter')` chain, so the popup never rotates the opener's session.

The flow has two server phases and a client completion step:

**Phase 1** (popup opens `Users/oauth?intent={token}&platform=twitter`, no `code` param): The handler stashes `platform`, `appId`, and the PKCE code verifier on the intent, then redirects the popup to X's authorization URL with `state = intent_token`.

**Phase 2** (X redirects back to `Users/oauth?code=...&state=...`): The handler exchanges the authorization code for tokens via `Users_OAuth::exchange()`, calls `Users_ExternalFrom_Twitter::fetchMe()` to resolve the xid, stages the tokens on a server-side `Users_ExternalFrom` row (tokens never travel on the intent, whose `exportArray` would expose them — only the public xid goes on the intent), completes the intent, and renders a page that closes the popup. On a full-page flow (mobile, no popup opener), phase 2 also calls `Users::authenticate()` itself before redirecting, since no opener will do it.

**Client completion** (opener detects popup closed): The opener checks the intent's completion status, retrieves the xid, sets `Users.authPayload.twitter = {intent: token}`, and calls `Users.authenticate('twitter')`. On the server, `Users_ExternalFrom_Twitter::authenticate()` reads the intent, extracts the xid, builds a fresh `Users_ExternalFrom_Twitter` with userId unset, and returns it. `Users::authenticate()` then owns the database insert, stamps the correct userId, and fires the From→To mirror.

The `authenticate.twitter` client adapter ships inside the Twitter plugin at `Twitter/web/js/methods/Users/authenticate/twitter.js`, registered with a `customPath` option so the Users plugin's `Q.Method.define` loader picks it up from the Twitter plugin directory rather than the default Users path.

### App-Context Read API

These methods use the app's bearer token (no user login needed). All support cursor-based pagination via `next_token` (x.com) or `cursor` (twitterapi.io):

**Users:** `Twitter::byUsernames($appId, $usernames)` — batch lookup. `Twitter::getUserTimeline($appId, $username)` — recent posts. `Twitter::getUserFollowers($appId, $username)`. `Twitter::getUserFollowing($appId, $username)`. `Twitter::getUserMentions($appId, $username)`.

**Tweets:** `Twitter::getTweets($appId, $ids)` — by ID. `Twitter::searchRecentTweets($appId, $query)` — full search with expansions. `Twitter::getTweetReplies($appId, $tweetId)`. `Twitter::getTweetRetweeters($appId, $tweetId)`. `Twitter::getTweetQuotes($appId, $tweetId)`.

**Lists:** `Twitter::getList($appId, $listId)` — metadata. `Twitter::getListMembers($appId, $listId)`. `Twitter::getListFollowers($appId, $listId)`. `Twitter::getListTweets($appId, $listId)` — timeline. `Twitter::getUserLists($appId, $username)` — owned lists (x.com only).

**Discovery:** `Twitter::getTrends($appId, $woeid)` — trending topics (twitterapi.io only).

### User-Context Write API

These methods use the logged-in user's OAuth token, resolved via `Twitter::userExternalFrom()` which does the ExternalTo→ExternalFrom two-hop lookup. Each static convenience on the `Twitter` class delegates to an instance method on `Users_ExternalFrom_Twitter` that uses `$this->accessToken` and `$this->xid` for self-scoped endpoints. Signature pattern: `Twitter::method($appId, ...args, $userId = null)`.

Actions: `postTweet` (with reply_to, quote_tweet_id, media_ids, poll_options), `deleteTweet`, `likeTweet`/`unlikeTweet`, `retweet`/`unretweet`, `followUser`/`unfollowUser`, `muteUser`/`unmuteUser`, `blockUser`/`unblockUser`, `bookmarkTweet`/`unbookmarkTweet`, `hideReply`/`unhideReply`, `getBookmarks`, `getLikedTweets`, `sendDirectMessage`, `uploadMedia` (single-request path for images/GIFs; large video chunked upload not implemented), `getMe`.

On the `ExternalFrom` instance, `apiRequest()` routes GET/POST through `Q_Utils` and DELETE/PUT through curl (no `Q_Utils` verb helper for those). All return X's decoded response array or null on missing row/transport failure.

**Required OAuth2 scopes.** Write methods only work if the user's token was minted with the matching scopes: `tweet.write`, `like.write`, `follows.write`, `mute.write`, `block.write`, `bookmark.write`, `tweet.moderate.write` (hide reply), `dm.write`, `media.write`. The `offline.access` scope is required for refresh tokens.

**Tier caveats.** Some endpoints are gated by X API access tier: creating a like was removed from the Free tier, and bookmark endpoints need at least Basic. They work on paid tiers; on Free they return X's permission error, which these methods pass through unchanged.

### Curation Engine

`Twitter_Curation` implements a complete signal-detection and news-ranking pipeline:

1. **Fetch** — `fetchFromLists()` pulls tweets from multiple Twitter Lists via `Twitter::getListTweets()`, with incremental fetching (skips previously seen tweets using `lastSeenId` stored in Streams). Supports auto-discovery of lists from a username via `resolveListIds()`.

2. **Score** — Each tweet gets a composite score: `(engagement × 1.0 + author × 0.5) × recency × signal_boost × outlier_boost`. Engagement is log10-weighted (likes + 2×retweets + 3×replies + 2.5×quotes + 1.5×bookmarks). Author score uses follower count and verification. Recency follows HN-style gravity decay. Signal keywords (configurable, 70+ defaults like "launching", "acquired", "breakthrough") add a 1.0–1.5× multiplier. Outlier detection compares per-author engagement to their rolling baseline stored in Streams.

3. **Filter** — Tweets below `minScore` (default 0.3) are dropped. Additional filters by minimum engagement and maximum age.

4. **Cluster** — Jaccard similarity on tokenized text groups related tweets into stories. Tokens are lowercased, stopword-filtered, and URL-stripped.

5. **Categorize** — Each cluster is tagged with topics from a configurable taxonomy (Models, Agents, Hardware, Safety, Funding, Regulation, Infra, Multimodal, Open, Enterprise).

6. **Rank** — Clusters are ranked by `score / (age + 2)^gravity`, producing a HN-style decay that surfaces breaking news.

7. **Render** — `renderStories()` produces display-ready records with headline, analysis, topics, sources, authors, and tweet links. Custom `headlineGenerator` and `analysisGenerator` callbacks (e.g. LLM-powered) can be passed in options.

State persistence: `lastSeenId` per list and per-author engagement baselines are stored in Qbix streams (`curation/state/{listId}` and `curation/author/{authorId}`).

### Push Notifications

`Users_ExternalFrom_Twitter::handlePushNotification()` delivers Qbix notifications as X direct messages via `POST /2/dm_conversations/with/{xid}/messages` using the user's own OAuth token. Requires `dm.write` scope. Handles rejection (forbidden/not authorized) and rate limiting. Note that this sends with the row's own token, so when the target is the row's own user, it's a self-DM; app-to-user notifications would need the app account's token, which is a separate resolution path. The general-purpose `sendDirectMessage($recipientXid, $text, $options)` method is for messaging arbitrary recipients as the user.

## Configuration

```json
{
    "Users": {
        "apps": {
            "twitter": {
                "MyApp": {
                    "appId": "MyApp",
                    "clientId": "X_OAUTH2_CLIENT_ID",
                    "clientSecret": "X_OAUTH2_CLIENT_SECRET",
                    "oauth2": {
                        "authorizationUrl": "https://twitter.com/i/oauth2/authorize",
                        "tokenUrl": "https://api.twitter.com/2/oauth2/token",
                        "revokeUrl": "https://api.twitter.com/2/oauth2/revoke",
                        "scopes": ["tweet.read", "users.read", "offline.access"],
                        "redirectUri": "https://your.host/Users/oauth",
                        "pkce": true
                    },
                    "bearerToken": "APP_ONLY_BEARER",
                    "provider": "twitterapi.io",
                    "apiKey": "TWITTERAPIIO_KEY"
                }
            }
        }
    }
}
```

Provider selection: omit `provider` for x.com (default); set `"twitterapi.io"` for the cheaper proxy. The `apiKey` field is for twitterapi.io; `bearerToken` is for x.com (or obtained automatically via client credentials). For a public PKCE client, omit `clientSecret`. The `redirectUri` must match exactly what is registered in the X developer portal.