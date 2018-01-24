<?php

namespace Views;

final class ApiResponse
{
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    public static function prepArray($status = self::STATUS_SUCCESS, $message = '', $data = [])
    {
        return ['status' => $status, 'message' => $message, 'data' => $data];
    }

    public static function prepSuccess($message = '', $data = [])
    {
        return self::prepArray(self::STATUS_SUCCESS, $message, $data);
    }

    public static function prepFailure($message = '', $data = [])
    {
        return self::prepArray(self::STATUS_FAILURE, $message, $data);
    }
}