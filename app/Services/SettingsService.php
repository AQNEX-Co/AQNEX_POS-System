<?php
namespace AQNEX\Services;

use AQNEX\Repositories\SettingsRepository;

class SettingsService
{
    public static function loadSettings(\mysqli $conn): array
    {
        return SettingsRepository::getSettings($conn);
    }
}
