<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test abilities for MCP debugging.
 */
class Test
{
    /**
     * Simplest possible test ability.
     *
     * @param array $args Arguments.
     * @return array
     */
    public static function hello(array $args): array
    {
        return [
            'success' => true,
            'message' => 'Hello from HP Abilities! Test successful.',
            'timestamp' => current_time('mysql'),
            'received_args' => $args,
        ];
    }

    /**
     * Test tool for economics scope.
     *
     * @return array
     */
    public static function economicsTest(): array
    {
        return ['status' => 'ok', 'message' => 'Economics scope is active'];
    }
}


























