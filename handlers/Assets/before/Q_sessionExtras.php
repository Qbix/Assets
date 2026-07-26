<?php

function Assets_before_Q_sessionExtras()
{
    if ($user = Users::loggedInUser(false, false)) {
        $userId = $user->id;
        $customers = Assets_Customer::select()
            ->where(compact('userId'))
            ->fetchDbRows();
    } else {
        $customers = array();
    }
    Q_Response::setScriptData('Q.plugins.Assets.customers', Db::exportArray($customers));
}
