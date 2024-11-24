<?php

function Assets_1_1_Streams_mysql()
{
    // now, add relations
    echo "Updating relations with scalar values".PHP_EOL;
    $i = 0;
    $attributes = array('attribute/amount', 'attribute/peak');
    foreach ($attributes as $attribute) {
        $rfroms = Streams_RelatedTo::select()->where(array(
            'type' => new Db_Range("$attribute=", true, false, true)
        ))->fetchAll(PDO::FETCH_ASSOC);
        $weights = array();
        $len = strlen("$attribute=");
        foreach ($rfroms as $rf) {
            $type = $rf['type'];
            $weights[$type] = floatval(substr($type, $len));
        }
        Streams_RelatedTo::delete()->where(array(
            'publisherId !=' => '',
            'type' => $attribute
        ))->execute();
        Streams_RelatedFrom::delete()->where(array(
            'publisherId !=' => '',
            'type' => $attribute
        ))->execute();
        foreach ($weights as $type => $weight) {
            Streams_RelatedTo::update()->ignore()->set(array(
                'type' => $attribute,
                'weight' => $weight
            ))->where(compact('type'))->execute();
            Streams_RelatedFrom::update()->ignore()->set(array(
                'type' => $attribute
            ))->where(compact('type'))->execute();
            ++$i;
            echo "\033[100D";
            echo "Updated $i weights";
        }
        Streams_RelatedToTotal::insert(array(
            'toPublisherId', 'toStreamName', 'relationType', 'fromStreamType', 'relationCount'
        ))->select(
            'toPublisherId,toStreamName,SUBSTRING(relationType, 1, 16) a, fromStreamType, SUM(relationCount)',
            Streams_RelatedToTotal::table()
        )->where(array('relationType' => new Db_Range('attribute/amount=', true, false, true)))
        ->groupBy('toPublisherId, toStreamName, a, fromStreamType')
        ->execute();
        Streams_RelatedToTotal::delete()->where(
            array('relationType' => new Db_Range('attribute/amount=', true, false, true))
        )->execute();
        Streams_RelatedFromTotal::insert(array(
            'fromPublisherId', 'fromStreamName', 'relationType', 'toStreamType', 'relationCount'
        ))->select(
            'fromPublisherId,fromStreamName,SUBSTRING(relationType, 1, 16) a, toStreamType, SUM(relationCount)',
            Streams_RelatedFromTotal::table()
        )->where(array('relationType' => new Db_Range('attribute/amount=', true, false, true)))
        ->groupBy('fromPublisherId, fromStreamName, a, toStreamType')
        ->execute();
        Streams_RelatedFromTotal::delete()->where(
            array('relationType' => new Db_Range('attribute/amount=', true, false, true))
        )->execute();
        echo PHP_EOL;	   
    }
}

Assets_1_1_Streams_mysql();