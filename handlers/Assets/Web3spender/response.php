<?php

function Assets_Web3spender_response($params = array())
{
    Users::loggedInUser(true);
    $address = Q_Config::expect('Assets', 'web3', 'spender', 'address');
    Q_Response::setSlot('address', $address);
}