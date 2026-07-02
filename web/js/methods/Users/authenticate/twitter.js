Q.exports(function (Users, priv) {

	// X has no client SDK we can read a profile from (unlike Telegram's WebApp or
	// Facebook's FB.api), so we capture the imported profile from the OAuth result
	// and key it by xid, for Users.prompt.twitter to render.
	var _profilesByXid = {};

	// User-facing label. The platform KEY stays "twitter" (config, xids, routes);
	// only what the human sees says "X".
	var DISPLAY = 'X';

	/**
	 * Authenticate the current browser with X (Twitter) via OAuth 2.0 (PKCE).
	 *
	 * Invoked by Users.authenticate() as
	 * handler(platform, platformAppId, onSuccess, onCancel, options), with
	 * options.appId already set to the internal appId. It delegates to
	 * Users.OAuth.start (popup when the intent token is cached, else full-page),
	 * and on success authenticates the opener through the usual handleXid chain
	 * (which shows the prompt below unless options.prompt is false).
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

		// the token is one-shot; provision a fresh one for next time
		Users.Intent.provision('Users/authenticate', platform, appId);

		Users.OAuth.start(platform, scope, function (err, result) {
			if (err || !result) {
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
			_ensureStyles();

			var platform = context.platform || 'twitter'; // real key, for lookups
			var newXid = context.xid;
			var currentXid = Q.getObject(['loggedInUser', 'xids', platform], Users);

			// Re-label the dialog chrome (framework titles it from the platform key).
			try {
				var $ov = container.closest('.Q_overlay, .Q_dialog');
				var $title = $ov.find('.Q_title_slot, .Q_dialog_title_text, h2').first();
				if ($title.length) { $title.text(DISPLAY + ' Account'); }
			} catch (e) {}

			var profile = _profilesByXid[newXid] || context.profile || {};
			var fallbackIcon = Q.url('{{Users}}/img/platforms/twitter.png');
			var newIcon = profile.profile_image_url
				? profile.profile_image_url.replace('_normal', '_400x400')
				: fallbackIcon;
			var newName = profile.name
				|| (profile.username ? '@' + profile.username : newXid);
			var newSub = profile.username ? '@' + profile.username : '';

			var i = { platform: DISPLAY, Platform: DISPLAY };
			var caption;
			if (currentXid && currentXid !== newXid) {
				// switching from another X account whose profile we don't have
				container.append(_userBlock(
					fallbackIcon, newXid, '',
					Q.text.Users.prompt.noLongerUsing.interpolate(i)
				));
				caption = Q.text.Users.prompt.doSwitch.interpolate(i);
			} else {
				caption = Q.text.Users.prompt.doAuth.interpolate(i);
			}

			container
				.append(_userBlock(
					newIcon, newName, newSub,
					Q.text.Users.prompt.areUsing.interpolate(i)
				))
				.append(_authenticateActions(caption));

			done && done();
		}
	};

	function _userBlock(icon, name, sub, explanation) {
		var $text = $("<div class='Users_xauth_text' />")
			.append($("<div class='Users_xauth_explanation' />").html(explanation))
			.append($("<div class='Users_xauth_name' />").text(name || ''));
		if (sub) {
			$text.append($("<div class='Users_xauth_username' />").text(sub));
		}
		return $("<div class='Users_xauth' />").append(
			$("<div class='Users_xauth_row' />")
				.append($("<img class='Users_xauth_icon' />").attr('src', icon))
				.append($text)
		);
	}

	function _authenticateActions(caption) {
		return $("<div class='Users_actions Q_big_prompt Users_xauth_actions' />").append(
			$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
				.html(caption)
		);
	}

	function _ensureStyles() {
		if (document.getElementById('Users_xauth_styles')) {
			return;
		}
		var css = [
			".Users_xauth { padding: 6px 4px 4px; }",
			".Users_xauth_row { display:flex; align-items:center; gap:14px; }",
			".Users_xauth_icon { width:60px; height:60px; border-radius:50%;",
			"  object-fit:cover; flex:0 0 auto; background:#000;",
			"  border:2px solid rgba(255,255,255,0.16);",
			"  box-shadow:0 1px 4px rgba(0,0,0,0.35); }",
			".Users_xauth_text { display:flex; flex-direction:column; min-width:0; }",
			".Users_xauth_explanation { font-size:13px; letter-spacing:.02em;",
			"  color:#a6a6b0; margin-bottom:3px; }",
			".Users_xauth_name { font-size:20px; font-weight:700; line-height:1.2;",
			"  color:#f2f2f5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }",
			".Users_xauth_username { font-size:15px; color:#7aa7ff; margin-top:1px; }",
			".Users_actions.Users_xauth_actions { margin-top:18px; }",
			".Users_xauth_actions .Users_confirm { display:block; width:100%; border:0;",
			"  padding:13px 18px; border-radius:999px; background:#3B82F6; color:#fff;",
			"  font-size:16px; font-weight:700; cursor:pointer;",
			"  transition:background .15s ease, transform .03s ease; }",
			".Users_xauth_actions .Users_confirm:hover { background:#2f74e6; }",
			".Users_xauth_actions .Users_confirm:active { transform:translateY(1px); }"
		].join("\n");
		var s = document.createElement('style');
		s.id = 'Users_xauth_styles';
		s.appendChild(document.createTextNode(css));
		(document.head || document.documentElement).appendChild(s);
	}

	return twitter;
});