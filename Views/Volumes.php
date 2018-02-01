<?php

namespace Views;

final class Volumes
{
    public static function prepArray(array $volume, \stdClass $hosting)
    {
        $volume['serviceId'] = $hosting->id;
        $volume['accountId'] = $hosting->userid;

        return $volume;
    }
}