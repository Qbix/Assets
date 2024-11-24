<?php

function Assets_after_Users_insertUser($params)
{
	// Create a stream for the user's credits
	$user = $params['user'];
	$stream = Assets_Credits::stream(null, $user->id, $user->id);

	// Have to skipAccess because stream normally doesn't let any user join,
	// not even the user for whom the credits are being stored
	$stream->join(array('userId' => $user->id, 'skipAccess' => true));
}