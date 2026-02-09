<?php

namespace App\Controllers;

use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;

class SettingsController {
    private function parseBool($value, $default = false) {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) return false;
        return $default;
    }

    private function getNumericSetting(SettingsRepository $settings, $key, $default) {
        $value = $settings->get($key);
        return is_numeric($value) ? floatval($value) : $default;
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function requireAdmin($user) {
        if (($user['role'] ?? 'customer') === 'admin') {
            return;
        }

        $repo = new UserRepository();
        $dbUser = $repo->getById($user['sub'] ?? '');
        if (($dbUser['role'] ?? 'customer') === 'admin') {
            return;
        }

        Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
        exit;
    }

    public function getVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();
        $rate = $settings->get('vat_rate');
        Response::json(['rate' => $rate !== null ? floatval($rate) : 0]);
    }

    public function updateVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['rate']) || !is_numeric($data['rate'])) {
            Response::error('IVA inválido', 400, 'SETTINGS_VAT_INVALID');
            return;
        }
        $rate = max(0, floatval($data['rate']));
        $settings = new SettingsRepository();
        $settings->set('vat_rate', (string)$rate);
        Response::json(['rate' => $rate]);
    }

    public function getShipping() {
        $settings = new SettingsRepository();
        $delivery = $settings->get('shipping_delivery');
        $pickup = $settings->get('shipping_pickup');
        $taxRate = $settings->get('shipping_tax_rate');
        $deliveryValue = is_numeric($delivery) ? floatval($delivery) : 5.0;
        $pickupValue = is_numeric($pickup) ? floatval($pickup) : 0.0;
        $taxValue = is_numeric($taxRate) ? floatval($taxRate) : 0.0;
        if ($delivery === null) {
            $settings->set('shipping_delivery', (string)$deliveryValue);
        }
        if ($pickup === null) {
            $settings->set('shipping_pickup', (string)$pickupValue);
        }
        if ($taxRate === null) {
            $settings->set('shipping_tax_rate', (string)$taxValue);
        }
        Response::json([
            'delivery' => $deliveryValue,
            'pickup' => $pickupValue,
            'tax_rate' => $taxValue
        ]);
    }

    public function updateShipping() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['delivery']) || !is_numeric($data['delivery']) || !isset($data['pickup']) || !is_numeric($data['pickup']) || !isset($data['tax_rate']) || !is_numeric($data['tax_rate'])) {
            Response::error('Costos de envío inválidos', 400, 'SETTINGS_SHIPPING_INVALID');
            return;
        }
        $delivery = max(0, floatval($data['delivery']));
        $pickup = max(0, floatval($data['pickup']));
        $taxRate = max(0, floatval($data['tax_rate']));
        $settings = new SettingsRepository();
        $settings->set('shipping_delivery', (string)$delivery);
        $settings->set('shipping_pickup', (string)$pickup);
        $settings->set('shipping_tax_rate', (string)$taxRate);
        Response::json([
            'delivery' => $delivery,
            'pickup' => $pickup,
            'tax_rate' => $taxRate
        ]);
    }

    public function getProductPage() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();
        $deliveryEstimate = $settings->get('product_page_delivery_estimate') ?? '14 de enero - 18 de enero';
        $viewerCount = $settings->get('product_page_viewer_count');
        $freeShipping = $settings->get('product_page_free_shipping');
        $supportHours = $settings->get('product_page_support_hours') ?? '8:30 AM a 10:00 PM';
        $returnDays = $settings->get('product_page_return_days');

        Response::json([
            'deliveryEstimate' => $deliveryEstimate,
            'viewerCount' => is_numeric($viewerCount) ? intval($viewerCount) : 38,
            'freeShippingThreshold' => is_numeric($freeShipping) ? floatval($freeShipping) : 75,
            'supportHours' => $supportHours,
            'returnDays' => is_numeric($returnDays) ? intval($returnDays) : 100
        ]);
    }

    public function updateProductPage() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $settings = new SettingsRepository();

        $deliveryEstimate = trim((string)($data['deliveryEstimate'] ?? ''));
        $supportHours = trim((string)($data['supportHours'] ?? ''));

        $viewerCount = isset($data['viewerCount']) && is_numeric($data['viewerCount'])
            ? max(0, intval($data['viewerCount']))
            : null;
        $freeShipping = isset($data['freeShippingThreshold']) && is_numeric($data['freeShippingThreshold'])
            ? max(0, floatval($data['freeShippingThreshold']))
            : null;
        $returnDays = isset($data['returnDays']) && is_numeric($data['returnDays'])
            ? max(0, intval($data['returnDays']))
            : null;

        if ($deliveryEstimate !== '') {
            $settings->set('product_page_delivery_estimate', $deliveryEstimate);
        }
        if ($supportHours !== '') {
            $settings->set('product_page_support_hours', $supportHours);
        }
        if ($viewerCount !== null) {
            $settings->set('product_page_viewer_count', (string)$viewerCount);
        }
        if ($freeShipping !== null) {
            $settings->set('product_page_free_shipping', (string)$freeShipping);
        }
        if ($returnDays !== null) {
            $settings->set('product_page_return_days', (string)$returnDays);
        }

        Response::json([
            'deliveryEstimate' => $deliveryEstimate ?: ($settings->get('product_page_delivery_estimate') ?? '14 de enero - 18 de enero'),
            'viewerCount' => $viewerCount ?? (is_numeric($settings->get('product_page_viewer_count')) ? intval($settings->get('product_page_viewer_count')) : 38),
            'freeShippingThreshold' => $freeShipping ?? (is_numeric($settings->get('product_page_free_shipping')) ? floatval($settings->get('product_page_free_shipping')) : 75),
            'supportHours' => $supportHours ?: ($settings->get('product_page_support_hours') ?? '8:30 AM a 10:00 PM'),
            'returnDays' => $returnDays ?? (is_numeric($settings->get('product_page_return_days')) ? intval($settings->get('product_page_return_days')) : 100)
        ]);
    }

    public function getPricingMargins() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $base = $this->getNumericSetting($settings, 'pricing_margin_base', 30);
        $min = $this->getNumericSetting($settings, 'pricing_margin_min', 15);
        $target = $this->getNumericSetting($settings, 'pricing_margin_target', 35);
        $promo = $this->getNumericSetting($settings, 'pricing_margin_promo_buffer', 5);

        if ($settings->get('pricing_margin_base') === null) {
            $settings->set('pricing_margin_base', (string)$base);
        }
        if ($settings->get('pricing_margin_min') === null) {
            $settings->set('pricing_margin_min', (string)$min);
        }
        if ($settings->get('pricing_margin_target') === null) {
            $settings->set('pricing_margin_target', (string)$target);
        }
        if ($settings->get('pricing_margin_promo_buffer') === null) {
            $settings->set('pricing_margin_promo_buffer', (string)$promo);
        }

        $min = max(0, $min);
        $base = max($min, $base);
        $target = max($base, $target);
        $promo = max(0, $promo);

        Response::json([
            'baseMargin' => $base,
            'minMargin' => $min,
            'targetMargin' => $target,
            'promoBuffer' => $promo
        ]);
    }

    public function updatePricingMargins() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!isset($data['baseMargin']) || !is_numeric($data['baseMargin'])) {
            Response::error('Margen base inválido', 400, 'SETTINGS_MARGIN_BASE_INVALID');
            return;
        }
        if (!isset($data['minMargin']) || !is_numeric($data['minMargin'])) {
            Response::error('Margen mínimo inválido', 400, 'SETTINGS_MARGIN_MIN_INVALID');
            return;
        }
        if (!isset($data['targetMargin']) || !is_numeric($data['targetMargin'])) {
            Response::error('Margen objetivo inválido', 400, 'SETTINGS_MARGIN_TARGET_INVALID');
            return;
        }

        $base = max(0, floatval($data['baseMargin']));
        $min = max(0, floatval($data['minMargin']));
        $target = max(0, floatval($data['targetMargin']));
        $promo = isset($data['promoBuffer']) && is_numeric($data['promoBuffer']) ? max(0, floatval($data['promoBuffer'])) : 5;

        if ($base < $min) $base = $min;
        if ($target < $base) $target = $base;

        $settings = new SettingsRepository();
        $settings->set('pricing_margin_base', (string)$base);
        $settings->set('pricing_margin_min', (string)$min);
        $settings->set('pricing_margin_target', (string)$target);
        $settings->set('pricing_margin_promo_buffer', (string)$promo);

        Response::json([
            'baseMargin' => $base,
            'minMargin' => $min,
            'targetMargin' => $target,
            'promoBuffer' => $promo
        ]);
    }

    public function getPricingCalc() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $rounding = $this->getNumericSetting($settings, 'pricing_calc_rounding', 0.05);
        $strategy = $settings->get('pricing_calc_strategy') ?? 'cost_plus';
        $includeVat = $this->parseBool($settings->get('pricing_calc_include_vat'), true);
        $shippingBuffer = $this->getNumericSetting($settings, 'pricing_calc_shipping_buffer', 0);

        if ($settings->get('pricing_calc_rounding') === null) {
            $settings->set('pricing_calc_rounding', (string)$rounding);
        }
        if ($settings->get('pricing_calc_strategy') === null) {
            $settings->set('pricing_calc_strategy', (string)$strategy);
        }
        if ($settings->get('pricing_calc_include_vat') === null) {
            $settings->set('pricing_calc_include_vat', $includeVat ? '1' : '0');
        }
        if ($settings->get('pricing_calc_shipping_buffer') === null) {
            $settings->set('pricing_calc_shipping_buffer', (string)$shippingBuffer);
        }

        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }

        Response::json([
            'rounding' => max(0, $rounding),
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVat,
            'shippingBuffer' => max(0, $shippingBuffer)
        ]);
    }

    public function updatePricingCalc() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!isset($data['rounding']) || !is_numeric($data['rounding'])) {
            Response::error('Redondeo inválido', 400, 'SETTINGS_ROUNDING_INVALID');
            return;
        }
        if (!isset($data['strategy']) || !is_string($data['strategy'])) {
            Response::error('Estrategia inválida', 400, 'SETTINGS_STRATEGY_INVALID');
            return;
        }

        $rounding = max(0, floatval($data['rounding']));
        $strategy = trim((string)$data['strategy']);
        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }
        $includeVat = isset($data['includeVatInPvp']) ? $this->parseBool($data['includeVatInPvp'], true) : true;
        $shippingBuffer = isset($data['shippingBuffer']) && is_numeric($data['shippingBuffer']) ? max(0, floatval($data['shippingBuffer'])) : 0;

        $settings = new SettingsRepository();
        $settings->set('pricing_calc_rounding', (string)$rounding);
        $settings->set('pricing_calc_strategy', (string)$strategy);
        $settings->set('pricing_calc_include_vat', $includeVat ? '1' : '0');
        $settings->set('pricing_calc_shipping_buffer', (string)$shippingBuffer);

        Response::json([
            'rounding' => $rounding,
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVat,
            'shippingBuffer' => $shippingBuffer
        ]);
    }

    public function getPricingRules() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $bulkThreshold = $this->getNumericSetting($settings, 'pricing_rule_bulk_threshold', 10);
        $bulkDiscount = $this->getNumericSetting($settings, 'pricing_rule_bulk_discount', 5);
        $clearanceThreshold = $this->getNumericSetting($settings, 'pricing_rule_clearance_threshold', 25);
        $clearanceDiscount = $this->getNumericSetting($settings, 'pricing_rule_clearance_discount', 15);

        if ($settings->get('pricing_rule_bulk_threshold') === null) {
            $settings->set('pricing_rule_bulk_threshold', (string)$bulkThreshold);
        }
        if ($settings->get('pricing_rule_bulk_discount') === null) {
            $settings->set('pricing_rule_bulk_discount', (string)$bulkDiscount);
        }
        if ($settings->get('pricing_rule_clearance_threshold') === null) {
            $settings->set('pricing_rule_clearance_threshold', (string)$clearanceThreshold);
        }
        if ($settings->get('pricing_rule_clearance_discount') === null) {
            $settings->set('pricing_rule_clearance_discount', (string)$clearanceDiscount);
        }

        Response::json([
            'bulkThreshold' => max(1, round($bulkThreshold)),
            'bulkDiscount' => max(0, min(90, $bulkDiscount)),
            'clearanceThreshold' => max(1, round($clearanceThreshold)),
            'clearanceDiscount' => max(0, min(90, $clearanceDiscount))
        ]);
    }

    public function updatePricingRules() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $bulkThreshold = isset($data['bulkThreshold']) && is_numeric($data['bulkThreshold']) ? max(1, intval($data['bulkThreshold'])) : null;
        $bulkDiscount = isset($data['bulkDiscount']) && is_numeric($data['bulkDiscount']) ? max(0, min(90, floatval($data['bulkDiscount']))) : null;
        $clearanceThreshold = isset($data['clearanceThreshold']) && is_numeric($data['clearanceThreshold']) ? max(1, intval($data['clearanceThreshold'])) : null;
        $clearanceDiscount = isset($data['clearanceDiscount']) && is_numeric($data['clearanceDiscount']) ? max(0, min(90, floatval($data['clearanceDiscount']))) : null;

        if ($bulkThreshold === null || $bulkDiscount === null || $clearanceThreshold === null || $clearanceDiscount === null) {
            Response::error('Reglas de precio inválidas', 400, 'SETTINGS_PRICING_RULES_INVALID');
            return;
        }

        $settings = new SettingsRepository();
        $settings->set('pricing_rule_bulk_threshold', (string)$bulkThreshold);
        $settings->set('pricing_rule_bulk_discount', (string)$bulkDiscount);
        $settings->set('pricing_rule_clearance_threshold', (string)$clearanceThreshold);
        $settings->set('pricing_rule_clearance_discount', (string)$clearanceDiscount);

        Response::json([
            'bulkThreshold' => $bulkThreshold,
            'bulkDiscount' => $bulkDiscount,
            'clearanceThreshold' => $clearanceThreshold,
            'clearanceDiscount' => $clearanceDiscount
        ]);
    }
}
