<?php

function Twitter_before_Q_responseExtras() {
	Q_Response::addScript('{{Twitter}}/js/Twitter.js');
}
