<?php

namespace Views;

final class Vps
{
    public static function prepArray(array $vps, \stdClass $hosting)
    {
        return [
            'id'             => $hosting->id,
            'serviceState'   => $hosting->domainstatus,
            'osTemplate'     => $vps['osTemplate'],
            'osDisplayName'  => $vps['osDisplayName'],
            'password'       => $vps['password'],
            'hostname'       => $vps['hostname'],
            'state'          => $vps['state'],
            'uptime'         => $vps['uptime'],
            'hdd'            => $vps['hdd'],
            'cpu'            => $vps['cpu'],
            'ram'            => $vps['ram'],
            'ip'             => $vps['ip'],
            'backupLimit'    => $vps['backupLimit'],
            'bandwidthLimit' => $vps['bandwidthLimit'],
            'networkRate'    => $vps['networkRate'],
            'cpuLimited'     => $vps['cpuLimited'],
            'tun'            => $vps['tun'],
            'vnc'            => $vps['vnc'],
        ];
    }

    public static function prepBackupArray(array $backup, \stdClass $hosting)
    {
        $backup['serviceId'] = $hosting->id;

        return $backup;
    }
}