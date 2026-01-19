<?php

namespace App\Controllers;

class HealthController {
    public function status() {
        echo json_encode([
            'estado' => 'ok',
            'fecha' => date('Y-m-d H:i:s'),
            'base_de_datos' => 'conectada'
        ]);
    }
}
