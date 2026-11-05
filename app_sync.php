<?php
// app_sync.php - keeps this shop in step with the Finance Nepal app (Stock and Inventory).
//
// The items switched on there ("Show on website") are read from the app and copied into this site's own
// `products` table, so the shop, the product page, the cart and orders keep running on plain integer ids exactly
// as before. Only rows that carry an app_id belong to the app: products added here by hand (products.php, CSV)
// are never changed or removed, and an app item whose name is already taken by one of those is skipped.
//
// It needs nothing in config.local.php: the three values below are the app's PUBLIC ones (the anon key is the
// public one every visitor of the app already has; the feed it opens is read-only and returns only the items that
// are switched on). config.local.php can still override any of them, e.g. putenv('SUPABASE_OWNER_ID=...').
//
// Open /app_sync.php?check=1 in a browser to run one sync and see how it went.

const APP_CATEGORIES = ['luggage', 'kitchen', 'appliances'];

const APP_DEFAULT_URL = 'https://boiiqzkkbyzqkqsxuzyp.supabase.co';
const APP_DEFAULT_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJvaWlxemtrYnl6cWtxc3h1enlwIiwicm9sZSI6ImFub24iLCJpYXQiOjE3OTA4Mzc3MjMsImV4cCI6MjEwNjQxMzcyM30.K-NKzPpIiIIymRvSwKNFHDxgAk9LLwCVHjYi8vQWiHA';
const APP_DEFAULT_OWNER_ID = '664018d3-d517-4ac3-b2fa-0d8589d23c5c';

/** One setting: the value put in config.local.php if there is one, else the app's public default. */
function appSetting(string $name, string $default): string
{
    $value = getenv($name);
    return ($value !== false && $value !== '') ? (string)$value : $default;
}

/** The items the app has switched on for the website. On failure null, and $status says why in one word. */
function fetchAppProducts(string $baseUrl, string $anonKey, string $ownerId, string &$status): ?array
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
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        error_log('app_sync: could not read the app (HTTP ' . $code . ')');
        $status = 'app-unreachable-http-' . $code;
        return null;
    }
    $rows = json_decode($body, true);
    // A list of items (an empty list is a real answer: nothing is switched on), never an error object.
    if (!is_array($rows) || ($rows !== [] && !isset($rows[0]))) {
        $status = 'app-bad-answer';
        return null;
    }
    $status = 'ok';
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
 * Returns one word for how it went: ok, no-curl, app-unreachable-http-N, app-bad-answer or database-error.
 */
function syncAppProducts(mysqli $conn): string
{
    if (!function_exists('curl_init')) {
        return 'no-curl';
    }
    $baseUrl = rtrim(appSetting('SUPABASE_URL', APP_DEFAULT_URL), '/');
    $anonKey = appSetting('SUPABASE_ANON_KEY', APP_DEFAULT_ANON_KEY);
    $ownerId = appSetting('SUPABASE_OWNER_ID', APP_DEFAULT_OWNER_ID);

    try {
        $status = '';
        $items = fetchAppProducts($baseUrl, $anonKey, $ownerId, $status);
        if ($items === null) {
            return $status; // not read, so nothing may be removed because of it
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
        return 'ok';
    } catch (Throwable $e) {
        error_log('app_sync: ' . $e->getMessage());
        return 'database-error';
    }
}

// Opened on its own as /app_sync.php?check=1 (not when api.php includes it): runs one sync and says how it went,
// in one word and a count - no details and nothing secret.
if (isset($_GET['check']) && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    require_once __DIR__ . '/config.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $conn = connectDb();
        $result = syncAppProducts($conn);
        $count = null;
        try {
            $row = $conn->query('SELECT COUNT(*) AS n FROM products WHERE app_id IS NOT NULL');
            $count = $row ? (int)$row->fetch_assoc()['n'] : null;
        } catch (Throwable $e) {
            // the app_id column does not exist (yet): the count stays unknown
        }
        echo json_encode(['sync' => $result, 'items_from_app_in_shop' => $count]);
    } catch (Throwable $e) {
        echo json_encode(['sync' => 'shop-database-unreachable']);
    }
    exit;
}
