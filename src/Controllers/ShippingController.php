<?php

namespace App\Controllers;

use App\Core\Response;

class ShippingController {
    public function index() {
        // In a real app, this would come from a 'shipping_providers' table
        Response::json([
            'providers' => [
                ['id' => 1, 'name' => 'Servientrega', 'status' => 'Activo'],
                ['id' => 2, 'name' => 'DHL Express', 'status' => 'Activo']
            ],
            'pickups' => [] // No pickups scheduled
        ]);
    }
}
