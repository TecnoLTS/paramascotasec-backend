<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Services\SriXmlGenerator;
use App\Repositories\OrderRepository;

/**
 * Controlador para generar comprobantes electrónicos SRI
 * Por ahora solo genera el XML (sin firma ni envío)
 */
class SriController {
    
    private $xmlGenerator;
    private $orderRepository;
    
    public function __construct() {
        $this->xmlGenerator = new SriXmlGenerator();
        $this->orderRepository = new OrderRepository();
    }
    
    /**
     * Genera y guarda el XML del comprobante electrónico para una orden
     * POST /api/admin/sri/invoice/{orderId}/generate
     */
    public function generateXmlForOrder($orderId) {
        Auth::requireAdmin();
        
        try {
            // Obtener la orden
            $order = $this->orderRepository->getById($orderId);
            if (!$order) {
                Response::error('Orden no encontrada', 404, 'ORDER_NOT_FOUND');
                return;
            }
            
            // Preparar datos para el XML
            $xmlData = $this->prepareOrderDataForSri($order);
            
            // Generar XML
            $xml = $this->xmlGenerator->generateInvoiceXml($xmlData);
            
            // Guardar XML en disco
            $storageDir = __DIR__ . '/../../storage/sri/xml';
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0775, true);
            }
            
            $secuencial = str_pad($xmlData['secuencial'], 9, '0', STR_PAD_LEFT);
            $timestamp = date('YmdHis');
            $filename = "factura_{$secuencial}_{$timestamp}.xml";
            $filepath = $storageDir . '/' . $filename;
            
            file_put_contents($filepath, $xml);
            
            Response::json([
                'success' => true,
                'message' => 'XML generado y guardado correctamente',
                'order_id' => $orderId,
                'access_key' => $xmlData['access_key'],
                'secuencial' => $secuencial,
                'filename' => $filename,
                'filepath' => $filepath,
                'status' => 'xml_generado',
                'next_step' => 'firma_digital'
            ]);
            
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'SRI_XML_GENERATION_FAILED');
        }
    }
    
    /**
     * Prepara los datos de la orden para generar el XML del SRI
     */
    private function prepareOrderDataForSri(array $order): array {
        // Obtener el siguiente secuencial (por ahora simple, después será de BD)
        $secuencial = $this->getNextSecuencial();
        
        // Fecha de emisión
        $fechaEmision = $order['created_at'] ?? date('Y-m-d H:i:s');
        
        // Datos del cliente
        $billingAddress = is_string($order['billing_address'] ?? null) 
            ? json_decode($order['billing_address'], true) 
            : ($order['billing_address'] ?? []);
        
        $shippingAddress = is_string($order['shipping_address'] ?? null)
            ? json_decode($order['shipping_address'], true)
            : ($order['shipping_address'] ?? []);
        
        $address = $billingAddress ?: $shippingAddress;
        
        $customerName = trim(($address['firstName'] ?? '') . ' ' . ($address['lastName'] ?? ''));
        if (!$customerName) {
            $customerName = 'CONSUMIDOR FINAL';
        }
        
        $documentType = strtolower($address['documentType'] ?? 'consumidor_final');
        $documentNumber = $address['documentNumber'] ?? '9999999999999';
        
        // Si es consumidor final, usar identificación estándar
        if ($documentType === 'consumidor_final' || empty($documentNumber) || $documentNumber === '9999999999999') {
            $documentType = 'consumidor_final';
            $documentNumber = '9999999999999';
            $customerName = 'CONSUMIDOR FINAL';
        }
        
        $customerAddress = ($address['street'] ?? '') . ', ' . ($address['city'] ?? '');
        $customerAddress = trim($customerAddress, ', ');
        if (!$customerAddress) {
            $customerAddress = 'Ecuador';
        }
        
        $customer = [
            'name' => $customerName,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'email' => $address['email'] ?? null,
            'phone' => $address['phone'] ?? null,
            'address' => $customerAddress
        ];
        
        // Calcular subtotales e impuestos
        $items = $order['items'] ?? [];
        $subtotalSinImpuestos = 0;
        $totalDescuento = (float)($order['discount_total'] ?? 0);
        
        $detalles = [];
        foreach ($items as $item) {
            $cantidad = (float)($item['quantity'] ?? 1);
            $precioUnitario = (float)($item['price'] ?? 0);
            $descuentoItem = 0; // El descuento ya viene aplicado en el precio
            
            $precioTotalSinImpuesto = $cantidad * $precioUnitario;
            $subtotalSinImpuestos += $precioTotalSinImpuesto;
            
            // Determinar si el producto tiene IVA
            // Por ahora asumimos 0% IVA (productos exentos)
            $tarifaIva = 0;
            $codigoPorcentajeIva = '0'; // 0=0%, 2=12%, 3=14%, 4=15%
            
            $detalles[] = [
                'codigo' => $item['product_id'],
                'descripcion' => $item['product_name'] ?? 'Producto',
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'descuento' => $descuentoItem,
                'precio_total_sin_impuesto' => $precioTotalSinImpuesto,
                'impuestos' => [
                    [
                        'codigo' => '2', // 2=IVA
                        'codigo_porcentaje' => $codigoPorcentajeIva,
                        'tarifa' => $tarifaIva,
                        'base_imponible' => $precioTotalSinImpuesto,
                        'valor' => 0 // Sin IVA por ahora
                    ]
                ]
            ];
        }
        
        // Resumen de impuestos
        $impuestos = [
            [
                'codigo' => '2', // IVA
                'codigo_porcentaje' => '0', // 0%
                'base_imponible' => $subtotalSinImpuestos,
                'valor' => 0
            ]
        ];
        
        $total = $subtotalSinImpuestos - $totalDescuento;
        
        // Formas de pago
        $paymentMethod = $order['payment_method'] ?? 'cash';
        $paymentDetails = is_string($order['payment_details'] ?? null)
            ? json_decode($order['payment_details'], true)
            : ($order['payment_details'] ?? []);
        
        $formasPago = [];
        
        if ($paymentMethod === 'mixed') {
            // Pago mixto: efectivo + electrónico
            $cashAmount = (float)($paymentDetails['cash_received'] ?? 0) - (float)($paymentDetails['change_due'] ?? 0);
            $electronicAmount = (float)($paymentDetails['electronic_amount'] ?? 0);
            
            if ($cashAmount > 0) {
                $formasPago[] = [
                    'codigo' => '01', // Sin utilización del sistema financiero
                    'total' => $cashAmount,
                    'plazo' => null,
                    'unidad_tiempo' => null
                ];
            }
            if ($electronicAmount > 0) {
                $formasPago[] = [
                    'codigo' => '20', // Otros con utilización del sistema financiero
                    'total' => $electronicAmount,
                    'plazo' => null,
                    'unidad_tiempo' => null
                ];
            }
        } else {
            $codigoFormaPago = $this->xmlGenerator->getFormaPagoCode($paymentMethod);
            $formasPago[] = [
                'codigo' => $codigoFormaPago,
                'total' => $total,
                'plazo' => null,
                'unidad_tiempo' => null
            ];
        }
        
        // Información adicional
        $infoAdicional = [];
        if (!empty($order['order_notes'])) {
            $infoAdicional['OBSERVACIONES'] = $order['order_notes'];
        }
        if (!empty($order['id'])) {
            $infoAdicional['ORDEN_ID'] = $order['id'];
        }
        
        // Generar clave de acceso
        $accessKey = $this->xmlGenerator->generateAccessKey([
            'fecha_emision' => $fechaEmision,
            'tipo_comprobante' => '01',
            'secuencial' => $secuencial
        ]);
        
        return [
            'secuencial' => $secuencial,
            'fecha_emision' => $fechaEmision,
            'customer' => $customer,
            'subtotal_sin_impuestos' => $subtotalSinImpuestos,
            'total_descuento' => $totalDescuento,
            'impuestos' => $impuestos,
            'total' => $total,
            'formas_pago' => $formasPago,
            'items' => $detalles,
            'info_adicional' => $infoAdicional,
            'access_key' => $accessKey
        ];
    }
    
    /**
     * Obtiene el siguiente secuencial
     * TODO: Implementar control de secuenciales en BD
     */
    private function getNextSecuencial(): int {
        // Por ahora retorna un número aleatorio para pruebas
        // En producción esto debe venir de una tabla de secuenciales
        return rand(1, 999999);
    }
}
