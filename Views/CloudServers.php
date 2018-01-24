<?php

namespace Views;

final class CloudServers
{
    public static function prepArray(array $cloudServer, \stdClass $hosting)
    {
        return [
            'id'                  => $hosting->id,
            'serviceState'        => $hosting->domainstatus,
            'cpuNumber'           => $cloudServer['cpuNumber'],
            'cpuUsed'             => $cloudServer['cpuUsed'],
            'created'             => $cloudServer['created'],
            'displayName'         => $cloudServer['displayName'],
            'hostname'            => $cloudServer['hostname'],
            'instanceName'        => $cloudServer['instanceName'],
            'memory'              => $cloudServer['memory'],
            'name'                => $cloudServer['name'],
            'state'               => $cloudServer['state'],
            'templateId'          => $cloudServer['templateId'],
            'osTypeId'            => $cloudServer['osTypeId'],
            'isoId'               => $cloudServer['isoId'],
            'isoName'             => $cloudServer['isoName'],
            'inRescue'            => $cloudServer['inRescue'],
            'password'            => $cloudServer['password'],
            'templateDisplayText' => $cloudServer['templateDisplayText'],
            'templateName'        => $cloudServer['templateName'],
            'bwLimited'           => $cloudServer['bwLimited'],
            'nic'                 => $cloudServer['nic'],
        ];
    }

    public static function prepBackupArray(array $backup, \stdClass $hosting)
    {
        return [
            'id'           => $backup['id'],
            'serviceId'    => $hosting->id,
            'created'      => $backup['created'],
            'intervalType' => $backup['intervalType'],
            'name'         => $backup['name'],
            'physicalSize' => $backup['physicalSize'],
            'backupType'   => $backup['backupType'],
            'state'        => $backup['state'],
            'volumeId'     => $backup['volumeId'],
            'volumeName'   => $backup['volumeName'],
            'volumeType'   => $backup['volumeType'],
        ];
    }
}