<?php
// app_sync.php - keeps this shop in step with the Finance Nepal app (Stock and Inventory).
//
// The items switched on there ("Show on website") are read from the app and copied into this site's own
// `products` table, so the shop, the product page, the cart and orders keep running on plain integer ids exactly
// as before. Only rows that carry an app_id belong to the app: products added here by hand (products.php, CSV)
// are never changed or removed, and an app item whose name is already taken by one of those is skipped.
//
// Needs SUPABASE_URL, SUPABASE_ANON_KEY and SUPABASE_OWNER_ID in config.local.php (see
// config.local.php.example). Without them this does nothing and the shop works as it always did.

const APP_CATEGORIES = ['luggage', 'kitchen', 'appliances'];

/** The items the app has switched on for the website, or null if it could not be read. */
function fetchAppProducts(string $baseUrl, string $anonKey, string $ownerId): ?array
{
    $ch = curl_init($baseUrl . '/rest/v1/rpc/website_products');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['p_owner' => $ownerId]),
        CURLOPT_HTTPHEADER => ['apikey: ' . $anonKey, 'Authorization: Bearer ' . $anonKey, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        error_log('app_sync: could not read the app (HTTP ' . $status . ')');
        return null;
    }
    $rows = json_decode($body, true);
    // A list of items (an empty list is a real answer: nothing is switched on), never an error object.
    if (!is_array($rows) || ($rows !== [] && !isset($rows[0]))) {
        return null;
    }
    return array_values(array_filter($rows, 'is_array'));
}

/** Rows that came from the app are marked with its id for the item. */
function ensureAppIdColumn(mysqli $conn): void
{
    $found = $conn->query("SHOW COLUMNS FROM products LIKE 'app_id'");
    if ($found && $found->num_rows === 0) {
        $conn->query('ALTER TABLE products ADD COLUMN app_id CHAR(36) NULL, ADD UNIQUE KEY uniq_app_id (app_id)');
    }
}

/**
 * Brings `products` in line with what the app has switched on: new items are added, changed ones (price, stock,
 * photo, ...) updated, and the ones switched off or deleted in the app removed. Called before the shop's list is
 * read. It never throws; if the app cannot be reached nothing is changed, so the shop shows what it had.
 */
function syncAppProducts(mysqli $conn): void
{
    $baseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $anonKey = (string)getenv('SUPABASE_ANON_KEY');
    $ownerId = (string)getenv('SUPABASE_OWNER_ID');
    if ($baseUrl === '' || $anonKey === '' || $ownerId === '' || !function_exists('curl_init')) {
        return;
    }

    try {
        $items = fetchAppProducts($baseUrl, $anonKey, $ownerId);
        if ($items === null) {
            return; // not read, so nothing may be removed because of it
        }
        ensureAppIdColumn($conn);

        $keep = [];
        $find = $conn->prepare('SELECT app_id FROM products WHERE name = ? LIMIT 1');
        $save = $conn->prepare(
            'INSERT INTO products (app_id, name, category, price, original_price, stock, image, description) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), category=VALUES(category), price=VALUES(price), original_price=VALUES(original_price), stock=VALUES(stock), image=VALUES(image), description=VALUES(description)'
        );

        foreach ($items as $item) {
            $appId = (string)($item['id'] ?? '');
            $name = trim((string)($item['name'] ?? ''));
            $category = (string)($item['category'] ?? '');
            $price = (float)($item['price'] ?? 0);
            $image = trim((string)($item['image'] ?? ''));
            if ($appId === '' || $name === '' || !in_array($category, APP_CATEGORIES, true) || $price <= 0 || $image === '') {
                continue;
            }
            $keep[] = $appId; // listed in the app: a failed save below must not get it removed

            try {
                // A product of the site's own with this name stays as it is.
                $find->bind_param('s', $name);
                $find->execute();
                $same = $find->get_result()->fetch_assoc();
                if ($same && $same['app_id'] !== $appId) {
                    continue;
                }

                $original = (float)($item['original_price'] ?? 0) > $price ? (float)$item['original_price'] : null;
                $stock = max(0, (int)($item['stock'] ?? 0));
                $description = trim((string)($item['description'] ?? ''));
                $description = $description === '' ? null : $description;
                $save->bind_param('sssddiss', $appId, $name, $category, $price, $original, $stock, $image, $description);
                $save->execute();
            } catch (Throwable $e) {
                error_log('app_sync: could not save "' . $name . '": ' . $e->getMessage());
            }
        }

        if ($keep) {
            $marks = implode(',', array_fill(0, count($keep), '?'));
            $remove = $conn->prepare("DELETE FROM products WHERE app_id IS NOT NULL AND app_id NOT IN ($marks)");
            $remove->bind_param(str_repeat('s', count($keep)), ...$keep);
            $remove->execute();
        } else {
            $conn->query('DELETE FROM products WHERE app_id IS NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('app_sync: ' . $e->getMessage());
    }
}
