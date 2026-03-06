<?php

/**
 * Validador de XML del SRI Ecuador
 * Verifica que el XML cumpla con todas las especificaciones de la Ficha Técnica v2.21
 * 
 * Uso: docker exec -it paramascotasec-backend-app php /var/www/html/scripts/validate_sri_xml.php [archivo.xml]
 */

// Si se pasa un archivo como argumento
$xmlFile = $argv[1] ?? null;

if ($xmlFile && file_exists($xmlFile)) {
    validateXmlFile($xmlFile);
} else {
    // Buscar el XML más reciente en el directorio
    $xmlDir = __DIR__ . '/../storage/sri/xml';
    $files = glob($xmlDir . '/factura_*.xml');
    
    if (empty($files)) {
        echo "❌ No se encontraron archivos XML en $xmlDir\n";
        echo "\nPara generar un XML de prueba, ejecuta:\n";
        echo "  docker exec -it paramascotasec-backend-app php /var/www/html/scripts/test_xml_generation.php\n\n";
        exit(1);
    }
    
    // Ordenar por fecha de modificación (más reciente primero)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    echo "=== VALIDADOR DE XML SRI ECUADOR ===\n\n";
    echo "Validando el XML más reciente...\n";
    echo "Archivo: " . basename($files[0]) . "\n\n";
    
    validateXmlFile($files[0]);
}

function validateXmlFile($filepath) {
    $errors = [];
    $warnings = [];
    $info = [];
    
    // 1. Verificar que el archivo existe y se puede leer
    if (!file_exists($filepath)) {
        echo "❌ ERROR: El archivo no existe: $filepath\n";
        exit(1);
    }
    
    $xmlContent = file_get_contents($filepath);
    if ($xmlContent === false) {
        echo "❌ ERROR: No se puede leer el archivo: $filepath\n";
        exit(1);
    }
    
    // 2. Verificar que es XML válido
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    
    if ($xml === false) {
        echo "❌ ERROR: El XML no está bien formado\n";
        foreach (libxml_get_errors() as $error) {
            echo "  - Línea {$error->line}: {$error->message}";
        }
        libxml_clear_errors();
        exit(1);
    }
    
    echo "✅ XML bien formado\n";
    
    // 3. Verificar estructura raíz
    if ($xml->getName() !== 'factura') {
        $errors[] = "Elemento raíz debe ser 'factura', encontrado: " . $xml->getName();
    } else {
        echo "✅ Elemento raíz correcto: factura\n";
    }
    
    // Atributos de factura
    $version = (string)$xml['version'];
    $id = (string)$xml['id'];
    
    if ($version !== '1.0.0' && $version !== '1.1.0' && $version !== '2.0.0' && $version !== '2.1.0') {
        $warnings[] = "Versión '$version' no reconocida (esperado: 1.0.0, 1.1.0, 2.0.0 o 2.1.0)";
    } else {
        echo "✅ Versión: $version\n";
    }
    
    if ($id !== 'comprobante') {
        $warnings[] = "Atributo id='$id' (esperado: 'comprobante')";
    }
    
    // 4. Validar infoTributaria (obligatorio)
    echo "\n--- VALIDANDO INFO TRIBUTARIA ---\n";
    if (!isset($xml->infoTributaria)) {
        $errors[] = "Falta sección <infoTributaria>";
    } else {
        $infoTrib = $xml->infoTributaria;
        
        // Campos obligatorios
        $requiredFields = [
            'ambiente' => 'Ambiente (1=Pruebas, 2=Producción)',
            'tipoEmision' => 'Tipo de emisión',
            'razonSocial' => 'Razón social del emisor',
            'ruc' => 'RUC del emisor',
            'claveAcceso' => 'Clave de acceso',
            'codDoc' => 'Código de documento',
            'estab' => 'Código establecimiento',
            'ptoEmi' => 'Punto de emisión',
            'secuencial' => 'Número secuencial',
            'dirMatriz' => 'Dirección matriz'
        ];
        
        foreach ($requiredFields as $field => $description) {
            if (!isset($infoTrib->$field) || trim((string)$infoTrib->$field) === '') {
                $errors[] = "Campo obligatorio faltante en infoTributaria: $field ($description)";
            }
        }
        
        // Validar ambiente
        $ambiente = (string)$infoTrib->ambiente;
        if (!in_array($ambiente, ['1', '2'])) {
            $errors[] = "Ambiente debe ser 1 (Pruebas) o 2 (Producción), encontrado: '$ambiente'";
        } else {
            $ambienteText = $ambiente === '1' ? 'Pruebas' : 'Producción';
            echo "✅ Ambiente: $ambiente ($ambienteText)\n";
        }
        
        // Validar RUC (13 dígitos)
        $ruc = (string)$infoTrib->ruc;
        if (!preg_match('/^\d{13}$/', $ruc)) {
            $errors[] = "RUC debe tener 13 dígitos, encontrado: '$ruc'";
        } else {
            echo "✅ RUC: $ruc\n";
        }
        
        // Validar clave de acceso (49 dígitos)
        $claveAcceso = (string)$infoTrib->claveAcceso;
        if (!preg_match('/^\d{49}$/', $claveAcceso)) {
            $errors[] = "Clave de acceso debe tener 49 dígitos, encontrado: " . strlen($claveAcceso) . " dígitos";
        } else {
            echo "✅ Clave de acceso: $claveAcceso\n";
            
            // Validar dígito verificador (módulo 11)
            $claveBase = substr($claveAcceso, 0, 48);
            $digitoVerificador = substr($claveAcceso, 48, 1);
            $digitoCalculado = calcularModulo11($claveBase);
            
            if ($digitoVerificador !== (string)$digitoCalculado) {
                $errors[] = "Dígito verificador incorrecto. Esperado: $digitoCalculado, Encontrado: $digitoVerificador";
            } else {
                echo "✅ Dígito verificador correcto: $digitoVerificador\n";
            }
            
            // Desglosar clave de acceso
            $fecha = substr($claveAcceso, 0, 8);
            $tipoComprobante = substr($claveAcceso, 8, 2);
            $rucClave = substr($claveAcceso, 10, 13);
            $ambienteClave = substr($claveAcceso, 23, 1);
            $serie = substr($claveAcceso, 24, 6);
            $secuencialClave = substr($claveAcceso, 30, 9);
            $codigoNumerico = substr($claveAcceso, 39, 8);
            $tipoEmisionClave = substr($claveAcceso, 47, 1);
            
            $info[] = "Clave de acceso desglosada:";
            $info[] = "  - Fecha: $fecha (" . substr($fecha, 0, 2) . "/" . substr($fecha, 2, 2) . "/" . substr($fecha, 4, 4) . ")";
            $info[] = "  - Tipo comprobante: $tipoComprobante";
            $info[] = "  - RUC: $rucClave";
            $info[] = "  - Ambiente: $ambienteClave";
            $info[] = "  - Serie: $serie (Estab: " . substr($serie, 0, 3) . ", Pto: " . substr($serie, 3, 3) . ")";
            $info[] = "  - Secuencial: $secuencialClave";
            $info[] = "  - Código numérico: $codigoNumerico";
            $info[] = "  - Tipo emisión: $tipoEmisionClave";
            
            // Verificar consistencia
            if ($rucClave !== $ruc) {
                $warnings[] = "RUC en clave de acceso ($rucClave) no coincide con RUC emisor ($ruc)";
            }
            if ($ambienteClave !== $ambiente) {
                $warnings[] = "Ambiente en clave de acceso ($ambienteClave) no coincide con ambiente ($ambiente)";
            }
        }
        
        // Validar código de documento
        $codDoc = (string)$infoTrib->codDoc;
        $tiposDoc = [
            '01' => 'Factura',
            '04' => 'Nota de Crédito',
            '05' => 'Nota de Débito',
            '06' => 'Guía de Remisión',
            '07' => 'Comprobante de Retención'
        ];
        
        if (!isset($tiposDoc[$codDoc])) {
            $errors[] = "Código de documento inválido: '$codDoc'";
        } else {
            echo "✅ Tipo de documento: $codDoc ({$tiposDoc[$codDoc]})\n";
        }
        
        // Validar establecimiento y punto de emisión (3 dígitos)
        $estab = (string)$infoTrib->estab;
        $ptoEmi = (string)$infoTrib->ptoEmi;
        
        if (!preg_match('/^\d{3}$/', $estab)) {
            $errors[] = "Código establecimiento debe tener 3 dígitos, encontrado: '$estab'";
        } else {
            echo "✅ Establecimiento: $estab\n";
        }
        
        if (!preg_match('/^\d{3}$/', $ptoEmi)) {
            $errors[] = "Punto de emisión debe tener 3 dígitos, encontrado: '$ptoEmi'";
        } else {
            echo "✅ Punto de emisión: $ptoEmi\n";
        }
        
        // Validar secuencial (9 dígitos)
        $secuencial = (string)$infoTrib->secuencial;
        if (!preg_match('/^\d{9}$/', $secuencial)) {
            $errors[] = "Secuencial debe tener 9 dígitos, encontrado: '$secuencial'";
        } else {
            echo "✅ Secuencial: $secuencial\n";
        }
    }
    
    // 5. Validar infoFactura (obligatorio para facturas)
    echo "\n--- VALIDANDO INFO FACTURA ---\n";
    if (!isset($xml->infoFactura)) {
        $errors[] = "Falta sección <infoFactura>";
    } else {
        $infoFact = $xml->infoFactura;
        
        // Campos obligatorios
        $fecha = (string)$infoFact->fechaEmision;
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
            $errors[] = "Fecha de emisión debe estar en formato DD/MM/YYYY, encontrado: '$fecha'";
        } else {
            echo "✅ Fecha de emisión: $fecha\n";
        }
        
        // Validar tipo de identificación comprador
        $tipoIdComprador = (string)$infoFact->tipoIdentificacionComprador;
        $tiposId = ['04' => 'RUC', '05' => 'Cédula', '06' => 'Pasaporte', '07' => 'Consumidor Final', '08' => 'Identificación exterior'];
        
        if (!isset($tiposId[$tipoIdComprador])) {
            $warnings[] = "Tipo de identificación comprador inválido: '$tipoIdComprador'";
        } else {
            echo "✅ Tipo ID comprador: $tipoIdComprador ({$tiposId[$tipoIdComprador]})\n";
        }
        
        // Validar totales
        $totalSinImpuestos = (float)$infoFact->totalSinImpuestos;
        $totalDescuento = (float)$infoFact->totalDescuento;
        $importeTotal = (float)$infoFact->importeTotal;
        
        echo "✅ Total sin impuestos: $" . number_format($totalSinImpuestos, 2) . "\n";
        echo "✅ Total descuento: $" . number_format($totalDescuento, 2) . "\n";
        echo "✅ Importe total: $" . number_format($importeTotal, 2) . "\n";
        
        // Validar totalConImpuestos
        if (!isset($infoFact->totalConImpuestos)) {
            $errors[] = "Falta sección <totalConImpuestos>";
        } else {
            $sumaImpuestos = 0;
            foreach ($infoFact->totalConImpuestos->totalImpuesto as $impuesto) {
                $sumaImpuestos += (float)$impuesto->valor;
            }
            echo "✅ Total impuestos: $" . number_format($sumaImpuestos, 2) . "\n";
            
            // Verificar que los totales cuadren
            $totalEsperado = $totalSinImpuestos + $sumaImpuestos - $totalDescuento;
            if (abs($totalEsperado - $importeTotal) > 0.01) {
                $errors[] = "Los totales no cuadran: (Sin imp: $totalSinImpuestos + Imp: $sumaImpuestos - Desc: $totalDescuento) = $totalEsperado ≠ $importeTotal";
            } else {
                echo "✅ Totales cuadran correctamente\n";
            }
        }
        
        // Validar formas de pago
        if (!isset($infoFact->pagos)) {
            $errors[] = "Falta sección <pagos>";
        } else {
            $cantidadPagos = count($infoFact->pagos->pago);
            echo "✅ Formas de pago: $cantidadPagos\n";
            
            $totalPagos = 0;
            foreach ($infoFact->pagos->pago as $pago) {
                $totalPagos += (float)$pago->total;
                $formaPago = (string)$pago->formaPago;
                $info[] = "  - Forma de pago: $formaPago, Total: $" . number_format((float)$pago->total, 2);
            }
            
            if (abs($totalPagos - $importeTotal) > 0.01) {
                $errors[] = "Total de pagos ($totalPagos) no coincide con importe total ($importeTotal)";
            }
        }
    }
    
    // 6. Validar detalles (productos)
    echo "\n--- VALIDANDO DETALLES ---\n";
    if (!isset($xml->detalles)) {
        $errors[] = "Falta sección <detalles>";
    } else {
        $cantidadDetalles = count($xml->detalles->detalle);
        echo "✅ Cantidad de productos: $cantidadDetalles\n";
        
        $subtotalCalculado = 0;
        foreach ($xml->detalles->detalle as $i => $detalle) {
            $cantidad = (float)$detalle->cantidad;
            $precioUnitario = (float)$detalle->precioUnitario;
            $descuento = (float)$detalle->descuento;
            $precioTotal = (float)$detalle->precioTotalSinImpuesto;
            
            $precioTotalEsperado = ($cantidad * $precioUnitario) - $descuento;
            
            if (abs($precioTotalEsperado - $precioTotal) > 0.01) {
                $warnings[] = "Detalle " . ($i + 1) . ": Precio total no cuadra (esperado: $precioTotalEsperado, encontrado: $precioTotal)";
            }
            
            $subtotalCalculado += $precioTotal;
        }
        
        echo "✅ Subtotal calculado desde detalles: $" . number_format($subtotalCalculado, 2) . "\n";
    }
    
    // Resumen final
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "RESULTADO DE LA VALIDACIÓN\n";
    echo str_repeat("=", 60) . "\n\n";
    
    if (count($errors) === 0 && count($warnings) === 0) {
        echo "✅✅✅ XML VÁLIDO - CUMPLE CON TODAS LAS ESPECIFICACIONES DEL SRI ✅✅✅\n\n";
        echo "El XML está listo para:\n";
        echo "  1. Firma digital con certificado .p12\n";
        echo "  2. Envío al SRI (RecepcionComprobantesOffline)\n";
        echo "  3. Consulta de autorización (AutorizacionComprobantesOffline)\n\n";
    } elseif (count($errors) === 0) {
        echo "⚠️  XML VÁLIDO CON ADVERTENCIAS\n\n";
        echo "Advertencias (" . count($warnings) . "):\n";
        foreach ($warnings as $warning) {
            echo "  ⚠️  $warning\n";
        }
        echo "\n";
    } else {
        echo "❌ XML INVÁLIDO - ENCONTRADOS " . count($errors) . " ERRORES\n\n";
        echo "Errores:\n";
        foreach ($errors as $error) {
            echo "  ❌ $error\n";
        }
        
        if (count($warnings) > 0) {
            echo "\nAdvertencias:\n";
            foreach ($warnings as $warning) {
                echo "  ⚠️  $warning\n";
            }
        }
        
        echo "\n";
        exit(1);
    }
    
    if (count($info) > 0) {
        echo "\nInformación adicional:\n";
        foreach ($info as $line) {
            echo "$line\n";
        }
    }
    
    echo "\n";
}

/**
 * Calcula el dígito verificador usando el algoritmo módulo 11
 */
function calcularModulo11($clave) {
    $factor = 2;
    $suma = 0;
    
    for ($i = strlen($clave) - 1; $i >= 0; $i--) {
        $suma += intval($clave[$i]) * $factor;
        $factor = ($factor == 7) ? 2 : $factor + 1;
    }
    
    $modulo = 11 - ($suma % 11);
    
    if ($modulo == 11) return 0;
    if ($modulo == 10) return 1;
    return $modulo;
}
