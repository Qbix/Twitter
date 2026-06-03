Q.exports(function (Users, priv) {

	// X has no client SDK we can read a profile from (unlike Telegram's WebApp or
	// Facebook's FB.api), so we capture the imported profile from the OAuth result
	// and key it by xid, for Users.prompt.twitter to render.
	var _profilesByXid = {};

	/**
	 * Authenticate the current browser with X (Twitter) via OAuth 2.0 (PKCE).
	 *
	 * Invoked by Users.authenticate() as
	 * handler(platform, platformAppId, onSuccess, onCancel, options), with
	 * options.appId already set to the internal appId. It delegates to
	 * Users.OAuth.start (which opens the popup synchronously when the intent token
	 * is cached, or goes full-page otherwise), and on success authenticates the
	 * opener through the usual handleXid chain (which shows the prompt below
	 * unless options.prompt is false).
	 *
	 * @method authenticate.twitter
	 * @param {String} platform "twitter"
	 * @param {String} platformAppId The external (OAuth client) app id
	 * @param {Function} onSuccess
	 * @param {Function} onCancel Receives (err, options)
	 * @param {Object} [options]
	 */
	function twitter(platform, platformAppId, onSuccess, onCancel, options) {
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
			// stash the imported profile so the prompt can show icon + username
			if (result.profile) {
				_profilesByXid[result.xid] = result.profile;
			}
			priv.handleXid(
				platform, appId, result.xid,
				onSuccess, onCancel,
				Q.extend({ profile: result.profile }, options)
			);
			// the token is one-shot; provision a fresh one for next time
			Users.Intent.provision('Users/authenticate', platform, appId);
		}, Q.extend({ appId: appId }, options));
	}

	// ============================================================
	// Prompt: confirm logging in as this X account, showing the
	// icon + username that would be imported.
	// ============================================================

	Users.prompt = Users.prompt || {};

	Users.prompt.twitter = {

		template: null, // dynamic render

		getData: function (context) {
			return context;
		},

		render: function (context, container, done) {
			var platform = context.platform || 'twitter';
			var Platform = context.Platform || 'X';

			var newXid = context.xid;
			var currentXid = Q.getObject(['loggedInUser', 'xids', platform], Users);

			var profile = _profilesByXid[newXid] || context.profile || {};
			var fallbackIcon = Q.url('{{Users}}/img/platforms/twitter.png');
			var newIcon = profile.profile_image_url || fallbackIcon;
			var newName = profile.name
				|| (profile.username ? '@' + profile.username : newXid);
			var newSub = profile.username ? '@' + profile.username : '';

			var caption;
			if (currentXid && currentXid !== newXid) {
				// switching from another X account whose profile we don't have
				container.append(_userBlock(
					fallbackIcon, newXid, '',
					Q.text.Users.prompt.noLongerUsing.interpolate({
						platform: platform, Platform: Platform
					})
				));
				caption = Q.text.Users.prompt.doSwitch.interpolate({
					platform: platform, Platform: Platform
				});
			} else {
				caption = Q.text.Users.prompt.doAuth.interpolate({
					platform: platform, Platform: Platform
				});
			}

			container
				.append(_userBlock(
					newIcon, newName, newSub,
					Q.text.Users.prompt.areUsing.interpolate({
						platform: platform, Platform: Platform
					})
				))
				.append(_authenticateActions(caption));

			done && done();
		}
	};

	function _userBlock(icon, name, sub, explanation) {
		var $text = $("<div class='Users_twitter_text' />")
			.append($("<div class='Users_explanation' />").html(explanation))
			.append($("<div class='Users_twitter_name' />").text(name || ''));
		if (sub) {
			$text.append($("<div class='Users_twitter_username' />").text(sub));
		}
		return $("<div class='Users_twitter_block' />").append(
			$("<div class='Users_twitter_row' />")
				.append($("<img class='Users_twitter_icon' />").attr('src', icon))
				.append($text)
		);
	}

	function _authenticateActions(caption) {
		return $("<div class='Users_actions Q_big_prompt' />").append(
			$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
				.html(caption)
		);
	}

	return twitter;
});