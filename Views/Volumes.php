<?php

namespace Views;

final class Volumes
{
    public static function prepArray(array $volume, \stdClass $hosting)
    {
        return [
            'id'            => $volume['id'],
            'serviceId'     => $hosting->id,
            'created'       => $volume['created'],
            'displayVolume' => $volume['displayVolume'],
            'name'          => $volume['name'],
            'size'          => $volume['size'],
            'snapshotId'    => $volume['snapshotId'],
            'state'         => $volume['state'],
            'status'        => $volume['status'],
            'type'          => $volume['type'],
            'vmDisplayName' => $volume['vmDisplayName'],
            'vmName'        => $volume['vmName'],
            'vmState'       => $volume['vmState'],
            'tags'          => $volume['tags']
        ];
    }
}