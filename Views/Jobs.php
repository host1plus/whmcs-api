<?php

namespace Views;

final class Jobs
{
    public static function prepArray(array $job, \stdClass $hosting)
    {
        return [
            'id'              => $job['id'],
            'serviceId'       => $hosting->id,
            'instance'        => $job['instance'],
            'action'          => $job['action'],
            'statusCode'      => $job['statusCode'],
            'status'          => $job['status'],
            'resultCode'      => $job['resultCode'],
            'created'         => $job['created'],
            'lastUpdated'     => $job['lastUpdated'],
            'executeAfter'    => $job['executeAfter'],
            'recurring'       => $job['recurring'],
            'disabled'        => $job['disabled'],
            'recursionPeriod' => $job['recursionPeriod'],
            'metadata'        => $job['metadata'],
            'related'         => $job['related']
        ];
    }
}