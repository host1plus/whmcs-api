<?php

function h1papi_config()
{
    return [
        'name'        => 'Host1Plus API Module',
        'description' => '',
        'version'     => '0.9',
        'author'      => 'edgaras@host1plus.com',
        'fields'      => [
            'option1' => [
                'FriendlyName' => 'API Endpoint',
                'Type'         => 'text',
                'Description'  => '',
                'Default'      => ''
            ],
            'option2' => [
                'FriendlyName' => 'API Key',
                'Type'         => 'text',
                'Description'  => '',
                'Default'      => ''
            ],
            'option3' => [
                'FriendlyName' => 'Cloud ISO Upload Limit',
                'Type'         => 'text',
                'Description'  => 'Default value for Cloud ISO Upload Limit in GB. 0 or empty for disabled',
                'Default'      => '0'
            ]
        ]
    ];
}