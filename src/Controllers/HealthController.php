<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Database;

class HealthController {
    public function status() {
        try {
            $db = Database::getInstance();
            $db->query('SELECT 1');
            Response::json([
                'estado' => 'ok',
                'fecha' => date('Y-m-d H:i:s'),
                'base_de_datos' => 'conectada'
            ]);
        } catch (\Throwable $e) {
            Response::error('Base de datos no disponible', 503, 'HEALTH_DB_UNAVAILABLE');
        }
    }
}
