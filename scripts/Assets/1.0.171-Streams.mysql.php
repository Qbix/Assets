<?php

function Assets_1_0_171_Streams_mysql() {
	try {
		$rows = Assets_Customer::select()->where(['payments' => 'stripe'])->fetchDbRows();
		foreach ($rows as $row) {
			if (empty($row->hash)) {
				$row->hash = Assets_Customer::getHash();
				$row->save();
			}
		}
	} catch (Exception $e) {
		// just ignore the error, config is probably not set
	}
}

Assets_1_0_171_Streams_mysql();