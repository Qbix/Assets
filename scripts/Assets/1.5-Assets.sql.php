<?php

function Assets_1_5_grandfathered()
{
	$cutoff = date('Y-m-d H:i:s');
	$rows = Assets_Customer::select()
		->where(array('insertedTime <' => $cutoff))
		->fetchDbRows();
	foreach ($rows as $row) {
		if (!empty($row->attributes) && $row->attributes !== '{}') {
			continue;
		}
		$row->setAttribute('paymentMethodUnknown', true, true);
		$row->save();
		echo "marked {$row->userId} / {$row->payments}\n";
	}
}
Assets_1_5_grandfathered();