<?php

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Core\Auth;
use App\Core\Response;

class ProductController {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }

    private function normalizePublishedField(array &$data): void {
        $hasPublished = array_key_exists('published', $data);
        $hasIsPublished = array_key_exists('isPublished', $data);
        if (!$hasPublished && !$hasIsPublished) {
            return;
        }

        $rawValue = $hasPublished ? $data['published'] : $data['isPublished'];
        if (is_bool($rawValue)) {
            $data['published'] = $rawValue;
            unset($data['isPublished']);
            return;
        }

        if (is_string($rawValue) || is_numeric($rawValue)) {
            $normalized = filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                $data['published'] = $normalized;
                unset($data['isPublished']);
                return;
            }
        }

        Response::error('Estado de publicación inválido', 400, 'PRODUCT_PUBLISHED_INVALID');
        exit;
    }

    private function includeUnpublishedFromRequest(): bool {
        $scope = strtolower(trim((string)($_GET['scope'] ?? '')));
        if ($scope !== 'admin') {
            return false;
        }

        $user = Auth::optionalUser();
        if (!$user) {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        $role = strtolower((string)($user['role'] ?? 'customer'));
        $subject = strtolower((string)($user['sub'] ?? ''));
        $isAdmin = $role === 'admin' || ($subject === 'service' && in_array($role, ['admin', 'service'], true));
        if (!$isAdmin) {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        return true;
    }

    private function normalizePurchaseInvoiceField(array &$data): void {
        if (!array_key_exists('purchaseInvoice', $data)) {
            return;
        }

        if (!is_array($data['purchaseInvoice'])) {
            Response::error('La factura de compra debe enviarse como objeto.', 400, 'PURCHASE_INVOICE_INVALID');
            exit;
        }

        $invoice = $data['purchaseInvoice'];
        $normalized = [
            'invoiceNumber' => trim((string)($invoice['invoiceNumber'] ?? $invoice['invoice_number'] ?? '')),
            'supplierName' => trim((string)($invoice['supplierName'] ?? $invoice['supplier_name'] ?? '')),
            'supplierDocument' => trim((string)($invoice['supplierDocument'] ?? $invoice['supplier_document'] ?? '')),
            'issuedAt' => trim((string)($invoice['issuedAt'] ?? $invoice['issued_at'] ?? '')),
            'notes' => trim((string)($invoice['notes'] ?? '')),
        ];

        $hasValue = false;
        foreach ($normalized as $value) {
            if ($value !== '') {
                $hasValue = true;
                break;
            }
        }

        $data['purchaseInvoice'] = $hasValue ? $normalized : null;
    }

    private function validatePurchaseInvoice(?array $purchaseInvoice, bool $required): void {
        if (!$required) {
            return;
        }
        if (!$purchaseInvoice) {
            Response::error('Debes registrar la factura de compra para ingresar stock.', 400, 'PURCHASE_INVOICE_REQUIRED');
            exit;
        }
        if (($purchaseInvoice['invoiceNumber'] ?? '') === '') {
            Response::error('El número de factura de compra es obligatorio.', 400, 'PURCHASE_INVOICE_NUMBER_REQUIRED');
            exit;
        }
        if (($purchaseInvoice['supplierName'] ?? '') === '') {
            Response::error('El proveedor de la factura de compra es obligatorio.', 400, 'PURCHASE_INVOICE_SUPPLIER_REQUIRED');
            exit;
        }
        $issuedAt = (string)($purchaseInvoice['issuedAt'] ?? '');
        if ($issuedAt === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt) !== 1) {
            Response::error('La fecha de la factura de compra es obligatoria y debe usar formato YYYY-MM-DD.', 400, 'PURCHASE_INVOICE_DATE_INVALID');
            exit;
        }
    }

    public function index() {
        try {
            $includeUnpublished = $this->includeUnpublishedFromRequest();
            $products = $this->productRepository->getAll([
                'includeUnpublished' => $includeUnpublished,
                'includeProcurement' => $includeUnpublished,
            ]);
            Response::json($products);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCTS_LIST_FAILED');
        }
    }

    public function show($id) {
        try {
            $includeUnpublished = $this->includeUnpublishedFromRequest();
            $product = $this->productRepository->getById($id, [
                'includeUnpublished' => $includeUnpublished,
                'includeProcurement' => $includeUnpublished,
            ]);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_FETCH_FAILED');
        }
    }

    public function store() {
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $this->normalizePublishedField($data);
            $this->normalizePurchaseInvoiceField($data);
            $productType = strtolower($data['productType'] ?? $data['product_type'] ?? '');
            $attributes = $data['attributes'] ?? [];
            $allowedTypes = ['comida', 'ropa', 'accesorios'];
            if (!$productType) {
                Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                return;
            }
            if (!in_array($productType, $allowedTypes, true)) {
                Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                return;
            }
            $requiredFields = ['name', 'category', 'price', 'quantity', 'brand'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || (is_string($data[$field]) && trim((string)$data[$field]) === '')) {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (!is_numeric($data['price']) || floatval($data['price']) < 0) {
                Response::error('Precio inválido', 400, 'PRODUCT_PRICE_INVALID');
                return;
            }
            if (!is_numeric($data['quantity']) || intval($data['quantity']) < 0) {
                Response::error('Cantidad inválida', 400, 'PRODUCT_QUANTITY_INVALID');
                return;
            }
            if (isset($data['cost']) && (!is_numeric($data['cost']) || floatval($data['cost']) < 0)) {
                Response::error('Costo inválido', 400, 'PRODUCT_COST_INVALID');
                return;
            }
            $quantity = isset($data['quantity']) && is_numeric($data['quantity']) ? intval($data['quantity']) : 0;
            $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $quantity > 0);
            if (!isset($data['description']) || trim((string)$data['description']) === '') {
                Response::error('Descripción requerida', 400, 'PRODUCT_DESCRIPTION_REQUIRED');
                return;
            }
            $required = ['sku', 'tag', 'species'];
            foreach ($required as $key) {
                if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                    Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                    return;
                }
            }
            $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
            if ($productType === 'comida' && $quantity > 0 && $expirationDateRaw === '') {
                Response::error('La fecha de vencimiento es obligatoria para productos de comida.', 400, 'PRODUCT_EXPIRY_DATE_REQUIRED');
                return;
            }
            if ($expirationDateRaw !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) !== 1) {
                    Response::error('Fecha de vencimiento inválida. Usa formato YYYY-MM-DD.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                    return;
                }
                $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                if (!($expirationDate instanceof \DateTimeImmutable)) {
                    Response::error('Fecha de vencimiento inválida.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                    return;
                }
                $attributes['expirationDate'] = $expirationDate->format('Y-m-d');

                $alertRaw = $attributes['expirationAlertDays'] ?? $attributes['expiryAlertDays'] ?? null;
                if ($alertRaw === null || $alertRaw === '') {
                    $attributes['expirationAlertDays'] = '30';
                } elseif (!is_numeric($alertRaw) || intval($alertRaw) < 0) {
                    Response::error('Días de alerta de vencimiento inválidos.', 400, 'PRODUCT_EXPIRY_ALERT_DAYS_INVALID');
                    return;
                } else {
                    $attributes['expirationAlertDays'] = (string)min(3650, max(0, intval($alertRaw)));
                }
            } else {
                unset($attributes['expirationDate'], $attributes['expiryDate'], $attributes['expirationAlertDays'], $attributes['expiryAlertDays']);
            }
            if (empty($attributes['supplier']) && !empty($data['purchaseInvoice']['supplierName'])) {
                $attributes['supplier'] = trim((string)$data['purchaseInvoice']['supplierName']);
            }
            $data['attributes'] = $attributes;
            $product = $this->productRepository->create($data);
            Response::json($product, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_CREATE_FAILED');
        }
    }

    public function update($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $this->normalizePublishedField($data);
            $this->normalizePurchaseInvoiceField($data);
            $productType = isset($data['productType']) ? strtolower((string)$data['productType']) : (isset($data['product_type']) ? strtolower((string)$data['product_type']) : null);
            if ($productType !== null) {
                $allowedTypes = ['comida', 'ropa', 'accesorios'];
                if ($productType === '') {
                    Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                    return;
                }
                if (!in_array($productType, $allowedTypes, true)) {
                    Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                    return;
                }
            }
            $numericRules = [
                'price' => 'PRODUCT_PRICE_INVALID',
                'quantity' => 'PRODUCT_QUANTITY_INVALID',
                'cost' => 'PRODUCT_COST_INVALID'
            ];
            foreach ($numericRules as $field => $errorCode) {
                if (isset($data[$field])) {
                    if (!is_numeric($data[$field]) || floatval($data[$field]) < 0) {
                        Response::error('Valor inválido', 400, $errorCode, ['field' => $field]);
                        return;
                    }
                }
            }
            $requiredFields = ['name', 'category', 'brand', 'description'];
            foreach ($requiredFields as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field]) && trim((string)$data[$field]) === '') {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (isset($data['productType']) || isset($data['product_type']) || isset($data['attributes'])) {
                $currentProduct = $this->productRepository->getById($id, ['includeUnpublished' => true]);
                if (!$currentProduct) {
                    Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                    return;
                }

                $attributes = $data['attributes'] ?? ($currentProduct['attributes'] ?? []);
                if (!is_array($attributes)) {
                    $attributes = [];
                }
                $required = ['sku', 'tag', 'species'];
                foreach ($required as $key) {
                    if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                        Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                        return;
                    }
                }
                $effectiveType = $productType ?? strtolower((string)($currentProduct['productType'] ?? ''));
                $effectiveQuantity = isset($data['quantity']) && is_numeric($data['quantity'])
                    ? intval($data['quantity'])
                    : intval($currentProduct['quantity'] ?? 0);
                $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
                if ($effectiveType === 'comida' && $effectiveQuantity > 0 && $expirationDateRaw === '') {
                    Response::error('La fecha de vencimiento es obligatoria para productos de comida.', 400, 'PRODUCT_EXPIRY_DATE_REQUIRED');
                    return;
                }
                if ($expirationDateRaw !== '') {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) !== 1) {
                        Response::error('Fecha de vencimiento inválida. Usa formato YYYY-MM-DD.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                        return;
                    }
                    $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                    if (!($expirationDate instanceof \DateTimeImmutable)) {
                        Response::error('Fecha de vencimiento inválida.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                        return;
                    }
                    $attributes['expirationDate'] = $expirationDate->format('Y-m-d');

                    $alertRaw = $attributes['expirationAlertDays'] ?? $attributes['expiryAlertDays'] ?? null;
                    if ($alertRaw === null || $alertRaw === '') {
                        $attributes['expirationAlertDays'] = '30';
                    } elseif (!is_numeric($alertRaw) || intval($alertRaw) < 0) {
                        Response::error('Días de alerta de vencimiento inválidos.', 400, 'PRODUCT_EXPIRY_ALERT_DAYS_INVALID');
                        return;
                    } else {
                        $attributes['expirationAlertDays'] = (string)min(3650, max(0, intval($alertRaw)));
                    }
                } else {
                    unset($attributes['expirationDate'], $attributes['expiryDate'], $attributes['expirationAlertDays'], $attributes['expiryAlertDays']);
                }
                $currentQuantity = intval($currentProduct['quantity'] ?? 0);
                $stockIncrease = max(0, $effectiveQuantity - $currentQuantity);
                $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $stockIncrease > 0);
                if (empty($attributes['supplier']) && !empty($data['purchaseInvoice']['supplierName'])) {
                    $attributes['supplier'] = trim((string)$data['purchaseInvoice']['supplierName']);
                }
                $data['attributes'] = $attributes;
            } else {
                $currentProduct = $this->productRepository->getById($id, ['includeUnpublished' => true]);
                if (!$currentProduct) {
                    Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                    return;
                }
                $currentQuantity = intval($currentProduct['quantity'] ?? 0);
                $effectiveQuantity = isset($data['quantity']) && is_numeric($data['quantity'])
                    ? intval($data['quantity'])
                    : $currentQuantity;
                $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $effectiveQuantity > $currentQuantity);
            }
            $product = $this->productRepository->update($id, $data);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_UPDATE_FAILED');
        }
    }

    public function destroy($id) {
        try {
            $this->productRepository->delete($id);
            Response::json(['deleted' => true], 200, null, 'Producto eliminado');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_DELETE_FAILED');
        }
    }
}
