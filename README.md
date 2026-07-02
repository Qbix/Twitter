# Twitter
Twitter plugin

# Login with X (Twitter) for Qbix — OAuth 2.0 (PKCE) over the intent system

Generic "Sign in with X" for the Users plugin, built the same way Telegram login
is: a platform adapter on top of `Users.authenticate`, driven by a `Users_Intent`.
The OAuth round trip runs in a popup against one generic handler; the opener logs
itself in afterward through the normal `Users.authenticate('twitter')` chain, so
the popup never rotates the opener's session.

## Where each file goes

Server and client pieces that *extend Users* live under the **Users** plugin
(this matches Telegram: `Users_ExternalFrom_Telegram` lives in Users too). The
bootstrap, config, and assets live in the **Twitter** plugin.

| File in this zip | Install to |
|---|---|
| `Users/handlers/Users/oauth/response.php` | `platforms/Users/handlers/Users/oauth/response.php` |
| `Users/classes/Users/OAuth.php` | `platforms/Users/classes/Users/OAuth.php` (replaces existing — adds two methods) |
| `Users/classes/Users/ExternalFrom/Twitter.php` | `platforms/Users/classes/Users/ExternalFrom/Twitter.php` |
| `Users/web/js/methods/Users/OAuth/start.js` | `platforms/Users/web/js/methods/Users/OAuth/start.js` |
| `Twitter/web/js/methods/Users/authenticate/twitter.js` | `plugins/Twitter/web/js/methods/Users/authenticate/twitter.js` |
| `Twitter/web/js/Twitter.js` | `plugins/Twitter/web/js/Twitter.js` (your Twitter plugin) |
| `Twitter/classes/Twitter.php` | `plugins/Twitter/classes/Twitter.php` (replaces existing — your file plus the user-context conveniences) |
| `Twitter/config/plugin.json` | merge into your Twitter plugin / app config |
| `_patches/Users.OAuth.replacement.js` | hand-paste into `platforms/Users/web/js/Users.js` |

The `authenticate.twitter` *body* ships inside the Twitter plugin at
`Twitter/web/js/methods/Users/authenticate/twitter.js`. `Users.authenticate`
normally loads every `authenticate.*` slot from `{{Users}}/js/methods/Users/authenticate`
via `Q.Method.define`, but the slot is registered with a `customPath` option
(`new Q.Method({}, { customPath: '{{Twitter}}/js/methods/Users/authenticate/twitter.js' })`),
which overrides that directory so the adapter can travel with Twitter rather than
under Users. `Twitter.js` registers the slot (in `beforeDefineAuthenticateMethods`,
so the loader picks it up) and provisions the intent; the slot still receives
`[Users, priv]` from the dispatcher's `argsFn`. Only `OAuth/start.js` stays under
Users, since it's a `Users.OAuth` method loaded by that class's own `Q.Method.define`.

Modeled on the Facebook adapter, not Telegram: because X uses a per-user OAuth
token (not a global bot token), `Users_ExternalFrom_Twitter` carries the token and
does the user-context API work itself — `getMe()` (GET `/2/users/me` with the
user's token), the DM in `handlePushNotification()`, and the full action complement
below. The `Twitter` class keeps its app-context read methods (`byUsernames`,
timelines — app bearer / `twitterapi.io`) and gains thin user-context conveniences
(now folded into the `Twitter/classes/Twitter.php` drop-in): `getMe($appId, $userId=null)`
and `userExternalFrom(...)` resolve the user's row (`Users_ExternalTo` by
`(userId, platform, appId)` → xid → `Users_ExternalFrom` by PK, From authoritative
for the token) and delegate to the instance, plus the pure `userIcon`/`userIconUrl`
transforms the row calls for icons.

## User-context actions (act "as the user")

Both classes carry the matching pair: the workhorse lives on
`Users_ExternalFrom_Twitter` (uses `$this->accessToken`, and `$this->xid` for
self-scoped endpoints); the `Twitter::` static is a thin convenience that resolves
the row via `userExternalFrom($appId, $userId)` — `$userId` defaulting to the
logged-in user — and delegates. Signature pattern: `Twitter::method($appId, ...args, $userId = null)`.

| Convenience (and EF instance method) | X API v2 call |
|---|---|
| `postTweet($appId, $text, $options, $userId)` | `POST /2/tweets` — `$options`: `reply_to`, `quote_tweet_id`, `media_ids`, `poll_options`, `poll_duration_minutes`, `reply_settings`, `community_id`, `body` (raw merge) |
| `deleteTweet($appId, $tweetId, $userId)` | `DELETE /2/tweets/:id` |
| `likeTweet` / `unlikeTweet` | `POST` / `DELETE /2/users/:xid/likes[/:tweet_id]` |
| `retweet` / `unretweet` | `POST` / `DELETE /2/users/:xid/retweets[/:source_tweet_id]` |
| `followUser` / `unfollowUser` | `POST` / `DELETE /2/users/:xid/following[/:target_user_id]` |
| `muteUser` / `unmuteUser` | `POST` / `DELETE /2/users/:xid/muting[/:target_user_id]` |
| `blockUser` / `unblockUser` | `POST` / `DELETE /2/users/:xid/blocking[/:target_user_id]` |
| `bookmarkTweet` / `unbookmarkTweet` | `POST` / `DELETE /2/users/:xid/bookmarks[/:tweet_id]` |
| `hideReply` / `unhideReply` | `PUT /2/tweets/:id/hidden` `{hidden}` |
| `getBookmarks($appId, $options, $userId)` | `GET /2/users/:xid/bookmarks` |
| `getLikedTweets($appId, $options, $userId)` | `GET /2/users/:xid/liked_tweets` |
| `sendDirectMessage($appId, $recipientXid, $text, $options, $userId)` | `POST /2/dm_conversations/with/:participant_id/messages` |
| `uploadMedia($appId, $filePath, $options, $userId)` | `POST /2/media/upload` (multipart; returns `data.id` for `media_ids`) |

Each returns X's decoded response array (or `null` on a missing row / transport
failure). On the EF, `apiRequest()` routes GET/POST through `Q_Utils` (matching the
rest of the plugin) and DELETE/PUT through `curl` (no `Q_Utils` verb helper for
those); `uploadMedia()` uses a `curl` multipart POST with `CURLFile`.

**Required OAuth2 scopes.** These methods only work if the user's token was minted
with the matching write scopes — add to `oauth2.scopes` in `plugin.json` as needed:
`tweet.write`, `like.write`, `follows.write`, `mute.write`, `block.write`,
`bookmark.write`, `tweet.moderate.write` (hide reply), `dm.write`, `media.write`.
`offline.access` is what gets you the refresh token.

**Tier caveats (as of 2025).** Some endpoints are gated by X API access tier, not
missing: creating a like (`POST .../likes`) was removed from the Free tier in Aug
2025, and bookmark endpoints need at least Basic ($200/mo). They work on paid
tiers; on Free they return X's permission error, which these methods pass through
unchanged. `uploadMedia` covers the simple single-request path (images/GIFs); large
video needs the chunked INIT/APPEND/FINALIZE flow, which is not implemented here.

## Delete

- `Users/handlers/Users/oauthed/response.php` — the old `Users_oauthed_response`.
  It only ever *connected* an already-logged-in user, never resolved an xid, and
  has bit-rotted (calls a nonexistent `processAuthorizationCodeResponse`, posts a
  refresh to an undefined `$tokenUri`). Its cookie + `window.name` + nonce
  transport is replaced by intent + `popup.closed` + a status check.

`Users_ExternalTo` and `Users_OAuth::oAuth()` both stay. `Users_OAuth` gains two
new methods and is otherwise untouched.

## Patch Users.js

Replace the `Users.OAuth = {...}` block with `_patches/Users.OAuth.replacement.js`.
It must land **inside** the `Q.exports(function (Users, priv) { ... })` wrapper so
`priv` resolves in the `Q.Method.define` call.

## Config

Merge `Twitter/config/plugin.json`. Fill in the X app credentials and set
`oauth2.redirectUri` to the absolute URL that maps to the `Users/oauth` action,
**exactly** as registered in the X developer portal. For a public PKCE client,
omit `clientSecret`. `Users/authenticate` must be a registered intent action
(included in the sample; Telegram already registers it).

## Flow

1. On init, `Twitter.js` provisions a `Users/authenticate` intent (token cached).
2. On click, `authenticate.twitter` calls `Users.OAuth.start`.
3. With the init-provisioned token cached, `start` opens the popup at
   `Users/oauth?intent={token}&platform=twitter` synchronously (still in the click
   gesture). If no token is cached it opens the popup blank in the gesture and
   fills its URL once provisioning returns. `openWindow:false` forces a full-page
   flow (e.g. mobile); there the handler completes the login itself on the way
   back (step 5a), since there's no opener to do it.
4. Phase 1 (`Users_oauth_response`, no `code`): stashes `platform`/`appId`/
   `finalRedirect` + the PKCE verifier on the intent, redirects to X with
   `state = token`.
5. X redirects back to `Users/oauth?code=&state=`. Phase 2 exchanges the code
   (`Users_OAuth::exchange`), resolves the xid (`Users_ExternalFrom_Twitter::fetchMe`),
   stages tokens in a server-only `Users_ExternalFrom` row, clears the verifier,
   `complete()`s the intent with the public xid, and renders a page that closes
   the popup (or redirects to `finalRedirect` for full-page/webview).
5a. On the full-page path only (`finalRedirect` set), phase 2 also calls
   `Users::authenticate` itself before redirecting, because that request runs in
   the user's own session and no opener will do it. The popup path skips this so
   it never rotates the shared session.
6. The opener notices `popup.closed`, makes one `Q.req('Users/oauth', ['completed','ok','xid'])`
   with `check=1` (the handler fills those slots), and on `ok` sets
   `Users.authPayload.twitter = {intent: token}` and calls
   `priv.handleXid('twitter', appId, xid, ...)` with `prompt:false`.
7. `handleXid` → `__doAuthenticate` picks up `authPayload.twitter` (no
   `buildAuthFields` needed) and `_doAuthenticate` POSTs `Users/authenticate`
   with `intent` in the fields. `Users_ExternalFrom_Twitter::authenticate` reads
   the intent → xid → staged row, **deletes** the staged row, and returns a fresh
   `ExternalFrom` with `userId` unset. `Users::authenticate` then owns the insert
   + the From→To mirror, stamping the correct `userId`. Cancel/error → intent
   never completes → `ok:false` → `_doCancel`, no POST.

The real `xid` (not `null`) must be passed to `handleXid`: its fast path is
`Users.loggedInUser.xids[key] == xid`, and `undefined == null` is `true` in JS,
so a `null` xid would make a logged-in user's *connect* attempt short-circuit to
`_doSuccess` without authenticating. The status check returns the xid (public,
and gated to the originating session) for exactly this reason.

### Why stage-then-delete

`Users::authenticate`'s session block only re-saves the `ExternalFrom` row when a
token field differs, so a pre-staged row left in place would keep its `userId=''`.
Returning a fresh, `userId`-unset `ExternalFrom` (and deleting the staged row)
reproduces the Facebook/Telegram path, where `Users::authenticate` inserts the
row itself with the right `userId` and the `afterSaveExecute` From→To mirror fires
once, correctly. Staging uses a query-level `insert()->onDuplicateKeyUpdate()`
(token fields only, never `userId`) so the mirror does **not** fire early and a
returning user's row isn't clobbered. Tokens live only in this server-side row,
never on the intent (the intent's `exportArray` would expose them); only the
public xid and the transient, immediately-cleared verifier go on the intent.

## Touchpoints worth a quick check against your tree

- **Columns vs `extra`.** The staged row uses real columns (`responseType='code'`,
  `accessToken`, `expires`). `extra` holds the `refreshToken` plus a trimmed
  profile subset (`username`, `name`, `profile_image_url`) for seeding the
  account — comfortably under `varchar(1023)`. `me()` reads that persisted subset
  (memoized on the instance) and only does a live `GET /2/users/me` if it's
  missing; the persisted copy can go stale, which is fine since it's refreshable.
  This zip also moves `Users_OAuth`'s refresh-token persistence off transient
  `set`/`get` onto `setExtra`/`getExtra`.
- **X hosts.** The sample uses the documented set —
  `https://twitter.com/i/oauth2/authorize`, `https://api.twitter.com/2/oauth2/token`,
  `https://api.twitter.com/2` — which is what X's own docs use (`x.com`/`api.x.com`
  resolve to the same). Just confirm the registered `redirectUri` matches the
  portal exactly.
- **DM push** (`handlePushNotification`) posts to `/2/dm_conversations/with/{xid}/messages`
  and needs `dm.write` granted at consent; treat as best-effort. It's left
  untouched (own `Q_Utils::post`, with the self-DM caveat); the general-purpose
  `sendDirectMessage($recipientXid, $text, $options)` added alongside it is the one
  to use for messaging arbitrary recipients as the user.