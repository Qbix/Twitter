(function (Q, $) {

/**
 * Twitter (X) plugin front end.
 *
 * Adds OAuth 2.0 login support to the Users plugin.
 * Itt registers an `authenticate.twitter` slot so Users.authenticate('twitter', ...)
 * resolves, and provisions a Users.Intent up front so the login popup
 * can be opened synchronously inside a click.
 *
 * @module Twitter
 * @class Twitter
 */

var Users = Q.Users;

// Register the slot before Users defines its authenticate sub-methods, so the
// slot picks up the proper lazy loader during Q.Method.define.
Q.Users.beforeDefineAuthenticateMethods.add(function (authenticate) {
	authenticate.twitter = new Q.Method({}, {
		customPath: '{{Twitter}}/js/methods/Users/authenticate/twitter.js'
	});
}, 'Twitter');

// Provision an intent ahead of time so authenticate.twitter can open its popup
// inside the user gesture (provisioning is async; the token is cached).
Q.onInit.add(function () {
	var appId = Q.info.app;
	if (Q.getObject(['twitter', appId], Users.apps)) {
		Users.Intent.provision('Users/authenticate', 'twitter', appId);
	}
}, 'Twitter');

})(Q, Q.jQuery);
