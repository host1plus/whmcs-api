<?php

namespace Views;

final class Jobs
{
    public static function prepArray(array $job, \stdClass $hosting)
    {
        $job['userId']    = $hosting->userid;
        $job['serviceId'] = $hosting->id;

        unset($job['module'], $job['instance']);

        return $job;
    }
}