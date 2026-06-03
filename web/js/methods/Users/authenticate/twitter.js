Q.exports(function (Users, priv) {

	/**
	 * Authenticate the current browser with X (Twitter) via OAuth 2.0 (PKCE).
	 *
	 * Invoked by Users.authenticate() as
	 * handler(platform, platformAppId, onSuccess, onCancel, options), with
	 * options.appId already set to the internal appId. It delegates to
	 * Users.OAuth.start (which opens the popup synchronously when the intent token
	 * is cached, or goes full-page otherwise), and on success authenticates the
	 * opener through the usual handleXid chain.
	 *
	 * @method authenticate.twitter
	 * @param {String} platform "twitter"
	 * @param {String} platformAppId The external (OAuth client) app id
	 * @param {Function} onSuccess
	 * @param {Function} onCancel Receives (err, options)
	 * @param {Object} [options]
	 */
	return function twitter(platform, platformAppId, onSuccess, onCancel, options) {
		options = Q.extend({}, options);
		var appId = (options && options.appId) || platformAppId;

		var scope = Q.getObject([platform, appId, 'oauth2', 'scopes'], Users.apps)
			|| ['tweet.read', 'users.read', 'offline.access'];

		Users.OAuth.start(platform, scope, function (err, result) {
			if (err || !result) {
				// the token was consumed (or half-consumed); provision a fresh one
				// so the next attempt has a usable intent ready in the gesture
				Users.Intent.provision('Users/authenticate', platform, appId);
				priv._doCancel(platform, appId, null, onSuccess, onCancel, options);
				return;
			}
			Q.setObject(['authPayload', platform], { intent: result.token }, Users);
			priv.handleXid(
				platform, appId, result.xid,
				onSuccess, onCancel,
				Q.extend({ prompt: false }, options)
			);
			// the token is one-shot; provision a fresh one for next time
			Users.Intent.provision('Users/authenticate', platform, appId);
		}, Q.extend({ appId: appId }, options));
	};

});