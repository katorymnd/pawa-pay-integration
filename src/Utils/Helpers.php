<?php

namespace Katorymnd\PawaPayIntegration\Utils;

class Helpers
{
    // Generate a valid UUID version 4 without any prefix
    public static function generateUniqueId()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, // version 4 UUID
            mt_rand(0, 0x3fff) | 0x8000, // variant
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}