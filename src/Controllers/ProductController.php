<?php

namespace App\Controllers;

use App\Repositories\ProductRepository;

class ProductController {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }

    public function index() {
        try {
            $products = $this->productRepository->getAll();
            echo json_encode($products);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show($id) {
        try {
            $product = $this->productRepository->getById($id);
            if (!$product) {
                http_response_code(404);
                echo json_encode(['error' => 'Producto no encontrado']);
                return;
            }
            echo json_encode($product);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function store() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $product = $this->productRepository->create($data);
            http_response_code(201);
            echo json_encode($product);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $product = $this->productRepository->update($id, $data);
            if (!$product) {
                http_response_code(404);
                echo json_encode(['error' => 'Producto no encontrado']);
                return;
            }
            echo json_encode($product);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function destroy($id) {
        try {
            $this->productRepository->delete($id);
            echo json_encode(['message' => 'Producto eliminado']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
