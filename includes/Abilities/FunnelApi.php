<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

class FunnelApi
{
    private static function is_hp_rw_available(): bool
    {
        return class_exists('\HP_RW\Services\FunnelConfigLoader');
    }

    private static function hp_rw_not_available(): array
    {
        return [
            'success' => false,
            'error'   => 'HP-React-Widgets plugin is not active.',
        ];
    }

    public static function seoAudit(): array
    {
        error_log('[HP-Abilities] seoAudit called');
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }
        return ['success' => true];
    }

    public static function applySeoFixes(): array
    {
        error_log('[HP-Abilities] applySeoFixes called');
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }
        return ['success' => true];
    }
}

