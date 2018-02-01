<?php

namespace Views;

final class Vps
{
    public static function prepArray(array $vps, \stdClass $hosting)
    {
        $vps['id']           = $hosting->id;
        $vps['productId']    = $hosting->packageid;
        $vps['serviceState'] = $hosting->domainstatus;
        $vps['accountId']    = $hosting->userid;

        return $vps;
    }

    public static function prepBackupArray(array $backup, \stdClass $hosting)
    {
        $backup['serviceId'] = $hosting->id;

        return $backup;
    }
}