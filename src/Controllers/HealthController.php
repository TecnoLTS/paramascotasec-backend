<?php

namespace App\Controllers;

use App\Core\Response;

class HealthController {
    public function status() {
        Response::json([
            'estado' => 'ok',
            'fecha' => date('Y-m-d H:i:s'),
            'base_de_datos' => 'conectada'
        ]);
    }
}
