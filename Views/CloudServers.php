<?php

namespace Views;

final class CloudServers
{
    public static function prepArray(array $cloudServer, \stdClass $hosting)
    {
        $cloudServer['serviceId']    = $hosting->id;
        $cloudServer['serviceState'] = $hosting->domainstatus;
        $cloudServer['accountId']    = $hosting->userid;

        unset($cloudServer['locationId'], $cloudServer['location']);

        return $cloudServer;
    }

    public static function prepBackupArray(array $backup, \stdClass $hosting)
    {
        $backup['serviceId'] = $hosting->id;
        $backup['accountId'] = $hosting->userid;

        return $backup;
    }
}