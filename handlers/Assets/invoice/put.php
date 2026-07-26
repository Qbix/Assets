<?php

/**
 * Update invoice status. Only the publisher or admin can change status.
 * @param {array} $_REQUEST
 * @param {string} $_REQUEST.publisherId
 * @param {string} $_REQUEST.streamName
 * @param {string} $_REQUEST.status One of: pending, paid, expired, canceled
 */
function Assets_invoice_put($params = array())
{
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('publisherId', 'streamName', 'status'), $req, true);

	$user = Users::loggedInUser(true);
	$publisherId = $req['publisherId'];
	$streamName = $req['streamName'];
	$status = $req['status'];

	$allowed = array('pending', 'paid', 'expired', 'canceled');
	if (!in_array($status, $allowed)) {
		throw new Q_Exception_WrongValue(array(
			'field' => 'status',
			'range' => implode(', ', $allowed)
		));
	}

	$stream = Streams_Stream::fetch($user->id, $publisherId, $streamName);
	if (!$stream) {
		throw new Q_Exception_MissingRow(array(
			'table' => 'stream',
			'criteria' => "$publisherId / $streamName"
		));
	}

	if (!$stream->testAdminLevel('own') && $user->id !== $publisherId) {
		throw new Q_Exception_NotAuthorized();
	}

	$oldStatus = $stream->getAttribute('status');
	$stream->setAttribute('status', $status);
	$stream->changed();

	// Post a message for the status change
	if ($oldStatus !== $status) {
		Streams_Message::post($user->id, $publisherId, $streamName,
			array(
				'type' => "Assets/invoice/$status",
				'content' => '',
				'instructions' => Q::json_encode(array(
					'oldStatus' => $oldStatus,
					'newStatus' => $status
				))
			),
			true
		);
	}

	Q_Response::setSlot('stream', $stream->exportArray());
}