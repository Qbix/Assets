<?php

function Assets_1_0_174_Streams_mysql()
{
    // Skip on SQLite — uses MySQL-specific CONCAT() and main. prefix.
    // On fresh installs there is no data to migrate anyway.
    $dsn = Q_Config::get('Db', 'connections', 'Streams', 'dsn', '');
    if (stripos($dsn, 'sqlite') !== false) {
        echo "Skipping Assets/credits migration on SQLite (no data to migrate)\n";
        return;
    }
    $communityId = Users::communityId();
    echo "Inserting Assets/credits access rows...".PHP_EOL;
    $table = Streams_Access::table();
    Streams_Access::insert(array(
        'publisherId', 'streamName', 'ofUserId',
        'ofContactLabel', 'ofParticipantRole',
        'readLevel', 'writeLevel', 'adminlevel'
    ))->select(array(
        new Db_Expression("'$communityId'"), 
        new Db_Expression("CONCAT('Assets/credits/', publisherId)"),
        'publisherId',
        new Db_Expression("''"), new Db_Expression("''"),
        new Db_Expression(40), new Db_Expression(-1), new Db_Expression(20)
    ), Streams_Stream::table())->where(array(
        'name' => 'Assets/user/credits'
    ))->onDuplicateKeyUpdate(array(
        "$table.publisherId" => new Db_Expression("$table.publisherId")
    ))->execute();
    echo "Renaming Assets/credits streams...".PHP_EOL;
    Streams::updateStreamNames(array(
        'Assets/user/credits' => new Db_Expression('CONCAT("Assets/credits/", {{publisherId}})')
    ), array(
        'newPublisherId' => Users::communityId()
    ));
}

Assets_1_0_174_Streams_mysql();
