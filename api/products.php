<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Authentication required for all endpoints
require_login();

$user = current_user();
$isAdmin = ($user && $user['role'] === 'admin');

// Parse request input (supports JSON, POST, and GET)
$jsonInput = get_json_input();
$action = sanitize_text($_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? 'list');

// Role enforcement: Cashiers are strictly restricted to the 'list' action
if (!$isAdmin && $action !== 'list') {
    json_response(false, 'Forbidden. Only administrators have permission to modify products.', null, 403);
}

// CSRF check for write actions
if (in_array($action, ['create', 'update', 'delete', 'toggle_active'], true)) {
    require_csrf();
}

$db = get_db();

switch ($action) {
    case 'list':
        try {
            $search = sanitize_text($_GET['search'] ?? $_GET['q'] ?? '');
            $category = sanitize_text($_GET['category'] ?? '');
            $includeInactive = $isAdmin && isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';

            // Build dynamic query
            $where = ['p.is_deleted = 0'];
            $params = [];

            // Cashiers always receive only active products; Admins can toggle inactive
            if (!$isAdmin || !$includeInactive) {
                $where[] = 'p.is_active = 1';
            }

            if (!empty($category) && $category !== 'all') {
                $where[] = 'p.category = :category';
                $params[':category'] = $category;
            }

            if (!empty($search)) {
                $where[] = '(p.name LIKE :search_name OR p.category LIKE :search_cat)';
                $params[':search_name'] = '%' . $search . '%';
                $params[':search_cat']  = '%' . $search . '%';
            }

            $sql = "SELECT p.id, p.name, p.price, p.category, p.is_active, p.created_at, p.updated_at
                    FROM products p
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY p.category ASC, p.name ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            // Fetch distinct non-deleted categories for filter pills and datalist
            $catStmt = $db->query("SELECT DISTINCT category FROM products WHERE is_deleted = 0 ORDER BY category ASC");
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            // Cast types appropriately
            foreach ($products as &$item) {
                $item['id'] = (int)$item['id'];
                $item['price'] = (float)$item['price'];
                $item['is_active'] = (int)$item['is_active'];
            }

            json_response(true, 'Products retrieved successfully.', [
                'products'   => $products,
                'categories' => $categories,
                'count'      => count($products)
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to fetch products: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'create':
        $data = !empty($jsonInput) ? $jsonInput : $_POST;

        $name = sanitize_text($data['name'] ?? '');
        $price = sanitize_float($data['price'] ?? null);
        $category = sanitize_text($data['category'] ?? '');
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] === 1 ? 1 : 0) : 1;

        // Server-side validation
        if (empty($name)) {
            json_response(false, 'Product name is required.', null, 422);
        }
        if (mb_strlen($name) > 150) {
            json_response(false, 'Product name must not exceed 150 characters.', null, 422);
        }
        if ($price <= 0) {
            json_response(false, 'Price must be greater than ₹0.00.', null, 422);
        }
        if ($price > 9999999.99) {
            json_response(false, 'Price exceeds maximum allowed limit.', null, 422);
        }
        if (empty($category)) {
            json_response(false, 'Category is required.', null, 422);
        }
        if (mb_strlen($category) > 50) {
            json_response(false, 'Category must not exceed 50 characters.', null, 422);
        }

        try {
            $stmt = $db->prepare("INSERT INTO products (name, price, category, is_active, is_deleted) 
                                  VALUES (:name, :price, :category, :is_active, 0)");
            $stmt->execute([
                ':name'      => $name,
                ':price'     => $price,
                ':category'  => $category,
                ':is_active' => $isActive
            ]);

            $newId = (int)$db->lastInsertId();

            json_response(true, 'Product created successfully.', [
                'id'        => $newId,
                'name'      => $name,
                'price'     => $price,
                'category'  => $category,
                'is_active' => $isActive
            ], 201);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to create product: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'update':
        $data = !empty($jsonInput) ? $jsonInput : $_POST;

        $id = sanitize_int($data['id'] ?? null);
        $name = sanitize_text($data['name'] ?? '');
        $price = sanitize_float($data['price'] ?? null);
        $category = sanitize_text($data['category'] ?? '');
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] === 1 ? 1 : 0) : 1;

        // Server-side validation
        if ($id <= 0) {
            json_response(false, 'Invalid product identifier.', null, 422);
        }
        if (empty($name)) {
            json_response(false, 'Product name is required.', null, 422);
        }
        if (mb_strlen($name) > 150) {
            json_response(false, 'Product name must not exceed 150 characters.', null, 422);
        }
        if ($price <= 0) {
            json_response(false, 'Price must be greater than ₹0.00.', null, 422);
        }
        if ($price > 9999999.99) {
            json_response(false, 'Price exceeds maximum allowed limit.', null, 422);
        }
        if (empty($category)) {
            json_response(false, 'Category is required.', null, 422);
        }
        if (mb_strlen($category) > 50) {
            json_response(false, 'Category must not exceed 50 characters.', null, 422);
        }

        try {
            // Ensure product exists and is not deleted
            $check = $db->prepare("SELECT id FROM products WHERE id = :id AND is_deleted = 0");
            $check->execute([':id' => $id]);
            if (!$check->fetch()) {
                json_response(false, 'Product not found or has been deleted.', null, 404);
            }

            $stmt = $db->prepare("UPDATE products 
                                  SET name = :name, price = :price, category = :category, is_active = :is_active 
                                  WHERE id = :id AND is_deleted = 0");
            $stmt->execute([
                ':id'        => $id,
                ':name'      => $name,
                ':price'     => $price,
                ':category'  => $category,
                ':is_active' => $isActive
            ]);

            json_response(true, 'Product updated successfully.', [
                'id'        => $id,
                'name'      => $name,
                'price'     => $price,
                'category'  => $category,
                'is_active' => $isActive
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to update product: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'toggle_active':
        $data = !empty($jsonInput) ? $jsonInput : $_POST;
        $id = sanitize_int($data['id'] ?? null);

        if ($id <= 0) {
            json_response(false, 'Invalid product identifier.', null, 422);
        }

        try {
            $check = $db->prepare("SELECT id, is_active FROM products WHERE id = :id AND is_deleted = 0");
            $check->execute([':id' => $id]);
            $product = $check->fetch();

            if (!$product) {
                json_response(false, 'Product not found.', null, 404);
            }

            $newStatus = ((int)$product['is_active'] === 1) ? 0 : 1;

            $stmt = $db->prepare("UPDATE products SET is_active = :status WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $id]);

            json_response(true, 'Product status updated.', [
                'id'        => $id,
                'is_active' => $newStatus
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to toggle status: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'delete':
        $data = !empty($jsonInput) ? $jsonInput : $_POST;
        $id = sanitize_int($data['id'] ?? null);

        if ($id <= 0) {
            json_response(false, 'Invalid product identifier.', null, 422);
        }

        try {
            // Soft delete: keep row intact with is_deleted = 1 so historical bills retain foreign key references
            $stmt = $db->prepare("UPDATE products SET is_deleted = 1, is_active = 0 WHERE id = :id AND is_deleted = 0");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                json_response(false, 'Product not found or already deleted.', null, 404);
            }

            json_response(true, 'Product deleted successfully (soft deleted).', ['id' => $id]);
        } catch (\Throwable $e) {
            json_response(false, 'Failed to delete product: ' . $e->getMessage(), null, 500);
        }
        break;

    default:
        json_response(false, 'Invalid action specified.', null, 400);
}
