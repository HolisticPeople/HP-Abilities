<?php
namespace HP_Abilities\Utils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared logic for Google Merchant Center (GMC) compliance validation.
 * Used by both real-time Yoast assessments and MCP gatekeeper tools.
 */
class GMCValidator
{
    /**
     * Forbidden keywords that often trigger GMC "Misleading Claims" or "Healthcare" violations.
     * Focusing on supplement-specific claims for now.
     */
    private static $forbidden_keywords = [
        'cure' => 'Claims to cure a disease are prohibited.',
        'treat' => 'Claims to treat a medical condition are highly regulated.',
        'heal' => 'Use "supports" or "promotes" instead of "heals".',
        'diagnose' => 'Claims to diagnose medical conditions are prohibited.',
        'prevent' => 'Claims to prevent a disease are restricted for supplements.',
        'remedy' => 'Avoid medicinal terminology.',
        'illegal' => 'Trigger word for policy checks.',
        'drug' => 'Trigger word for policy checks.',
        'marijuana' => 'Prohibited product category.',
        'cannabis' => 'Prohibited product category.',
        'pharmaceutical' => 'Avoid comparisons to prescription drugs.',
    ];

    /**
     * Audit a product for GMC compliance.
     *
     * @param \WC_Product|int $product Product object or ID.
     * @param array $data Optional fresh data to audit instead of DB state.
     * @return array Audit results with 'success', 'errors', and 'warnings'.
     */
    public static function audit($product, array $data = []): array
    {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product instanceof \WC_Product) {
            return ['success' => false, 'error' => 'Invalid product'];
        }

        $id = $product->get_id();
        $issues = [];

        // 1. Check Weight
        $weight = isset($data['weight']) ? $data['weight'] : $product->get_weight();
        if (empty($weight) || (float)$weight <= 0) {
            $issues[] = [
                'field' => 'weight',
                'level' => 'error',
                'issue' => 'missing_weight',
                'msg' => __('GMC requires a valid shipping weight.', 'hp-abilities'),
                'suggestion' => __('Add product weight in WooCommerce.', 'hp-abilities')
            ];
        }

        // 2. Check Description & Short Description for forbidden keywords
        $desc = isset($data['description']) ? $data['description'] : $product->get_description();
        $short_desc = isset($data['short_description']) ? $data['short_description'] : $product->get_short_description();
        
        $text_to_check = strip_tags($desc . ' ' . $short_desc);
        $keyword_issues = self::check_forbidden_keywords($text_to_check);
        
        foreach ($keyword_issues as $kw => $reason) {
            $issues[] = [
                'field' => 'description',
                'level' => 'warning',
                'issue' => 'forbidden_keyword',
                'found' => $kw,
                'msg' => sprintf(__('Forbidden keyword found: "%s".', 'hp-abilities'), $kw),
                'reason' => $reason,
                'suggestion' => __('Consider using "supports", "promotes", or "maintains" instead.', 'hp-abilities')
            ];
        }

        // 3. Overall status
        $has_errors = false;
        foreach ($issues as $issue) {
            if ($issue['level'] === 'error') {
                $has_errors = true;
                break;
            }
        }

        return [
            'success' => !$has_errors,
            'id' => $id,
            'sku' => $product->get_sku(),
            'issues' => $issues,
            'compliance_score' => self::calculate_score($issues)
        ];
    }

    /**
     * Check text for forbidden keywords.
     */
    public static function check_forbidden_keywords(string $text): array
    {
        $found = [];
        $text = strtolower($text);
        
        foreach (self::$forbidden_keywords as $kw => $reason) {
            // Use word boundaries to avoid partial matches (e.g. "heat" in "wheat")
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $text)) {
                $found[$kw] = $reason;
            }
        }
        
        return $found;
    }

    /**
     * Get the full list of forbidden keywords for the frontend JS.
     */
    public static function get_forbidden_keywords(): array
    {
        return self::$forbidden_keywords;
    }

    /**
     * Simple scoring logic: 100 base, -30 per error, -10 per warning.
     */
    private static function calculate_score(array $issues): int
    {
        $score = 100;
        foreach ($issues as $issue) {
            if ($issue['level'] === 'error') $score -= 30;
            else $score -= 10;
        }
        return max(0, $score);
    }
}

