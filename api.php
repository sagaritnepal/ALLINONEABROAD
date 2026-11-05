<?php
require_once __DIR__ . '/config.php';

session_start();

header('Content-Type: application/json; charset=utf-8');

function sendJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        $input = $_POST;
    }
    if (empty($input)) {
        $input = $_GET;
    }

    if (!is_array($input)) {
        throw new RuntimeException('Invalid request body.');
    }

    $action = $input['action'] ?? '';

    if ($action === 'health') {
        sendJson(['success' => true, 'message' => 'Backend is reachable.']);
        exit;
    }

    if ($action === 'me') {
        if (!empty($_SESSION['user'])) {
            sendJson(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            sendJson(['success' => false]);
        }
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        sendJson(['success' => true]);
        exit;
    }

    $conn = connectDb();

    if ($action === 'popup_banner') {
        $row = $conn->query('SELECT image, link_url, enabled FROM popup_banner WHERE id = 1')->fetch_assoc();
        if (!$row || !$row['enabled'] || !$row['image']) {
            sendJson(['success' => true, 'enabled' => false]);
        } else {
            sendJson(['success' => true, 'enabled' => true, 'image' => $row['image'], 'link' => $row['link_url']]);
        }
        exit;
    }

    if ($action === 'banner_messages') {
        $result = $conn->query('SELECT message FROM banner_messages WHERE active = 1 ORDER BY sort_order ASC, id ASC');
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row['message'];
        }
        sendJson(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($action === 'products') {
        // Items switched on in the Finance Nepal app (Stock and Inventory) are brought in first.
        require_once __DIR__ . '/app_sync.php';
        syncAppProducts($conn);

        $result = $conn->query('SELECT id, name, category, price, original_price, stock, image, images, description, badge, rating, reviews, piece_type FROM products ORDER BY id ASC');
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'cat' => $row['category'],
                'price' => (float)$row['price'],
                'orig' => $row['original_price'] !== null ? (float)$row['original_price'] : null,
                'img' => $row['image'],
                'images' => $row['images'],
                'sub' => $row['description'],
                'badge' => $row['badge'],
                'stock' => (int)$row['stock'],
                'stars' => (int)$row['rating'],
                'reviews' => (int)$row['reviews'],
                'pieceType' => $row['piece_type'],
            ];
        }
        sendJson(['success' => true, 'products' => $products]);
        exit;
    }

    if ($action === 'register') {
        $name = trim((string)($input['name'] ?? ''));
        $email = trim(strtolower((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            throw new RuntimeException('Name, email, and password are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            throw new RuntimeException('An account with this email already exists.');
        }
        $stmt->close();

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $email, $hashed);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to create account.');
        }

        sendJson([
            'success' => true,
            'message' => 'Account created successfully.',
            'user' => ['id' => $stmt->insert_id, 'name' => $name, 'email' => $email]
        ]);
        exit;
    }

    if ($action === 'login') {
        $email = trim(strtolower((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Email and password are required.');
        }

        $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid email or password.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']];

        sendJson([
            'success' => true,
            'message' => 'Login successful.',
            'user' => $_SESSION['user']
        ]);
        exit;
    }

    if ($action === 'order') {
        $name = trim((string)($input['name'] ?? ''));
        $email = trim(strtolower((string)($input['email'] ?? '')));
        $phone = trim((string)($input['phone'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $state = trim((string)($input['state'] ?? ''));
        $pincode = trim((string)($input['pincode'] ?? ''));
        $paymentMethod = trim((string)($input['paymentMethod'] ?? ''));
        $items = $input['items'] ?? [];
        $total = (float)($input['total'] ?? 0);

        if ($name === '' || $email === '' || $phone === '' || $address === '' || $city === '' || $state === '' || $pincode === '' || $paymentMethod === '') {
            throw new RuntimeException('Please complete all shipping and payment details.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            throw new RuntimeException('Please enter a valid phone number.');
        }

        if (empty($items) || !is_array($items)) {
            throw new RuntimeException('Your cart is empty.');
        }

        $itemsJson = json_encode($items, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('INSERT INTO orders (customer_name, email, phone, shipping_address, city, state, pincode, payment_method, items, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssssssssd', $name, $email, $phone, $address, $city, $state, $pincode, $paymentMethod, $itemsJson, $total);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to place order.');
        }

        sendJson([
            'success' => true,
            'message' => 'Order placed successfully.',
            'orderId' => $stmt->insert_id
        ]);
        exit;
    }

    throw new RuntimeException('Unsupported action.');
} catch (Throwable $e) {
    http_response_code(400);
    sendJson(['success' => false, 'message' => $e->getMessage()]);
}
