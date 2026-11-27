<?php
/**
 * API Cosmétiques d'Alger — PHP 7.4+ / MySQL (InfinityFree)
 * Toutes les vérifications importantes (droits, prix, stock) sont faites ici, côté serveur.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg, $code = 400) {
    out(['error' => $msg], $code);
}
function cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}
function iso($dt) {
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC'));
}

try {
    $cfg = @include __DIR__ . '/config.php';
    if (!is_array($cfg)) {
        fail('Fichier config.php introuvable ou invalide.', 500);
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CA_SESSID');
    session_start();

    try {
        $pdo = new PDO(
            'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
            $cfg['db_user'],
            $cfg['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (Exception $e) {
        fail('Connexion à la base de données impossible. Vérifiez config.php.', 500);
    }

    $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
    $method = $_SERVER['REQUEST_METHOD'];
    $getActions = ['me', 'bootstrap'];

    $body = [];
    if (!in_array($action, $getActions, true)) {
        if ($method !== 'POST') {
            fail('Méthode non autorisée.', 405);
        }
        $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($ct, 'application/json') === false) {
            fail('Requête invalide.', 415);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            fail('Données invalides.');
        }
    }

    function current_user() {
        return isset($_SESSION['user']) ? $_SESSION['user'] : null;
    }
    function require_login() {
        $u = current_user();
        if (!$u) {
            fail('Non connecté.', 401);
        }
        return $u;
    }
    function require_admin() {
        $u = require_login();
        if ($u['role'] !== 'admin') {
            fail('Action réservée à l\'administrateur.', 403);
        }
        return $u;
    }
    function shop_name($pdo) {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['shop_name']);
        $v = $st->fetchColumn();
        return $v ? $v : 'Cosmétiques d\'Alger';
    }
    function map_product($p, $admin) {
        return [
            'id'            => (string)$p['id'],
            'name'          => $p['name'],
            'category'      => $p['category'],
            'purchasePrice' => $admin ? (int)$p['purchase_price'] : 0,
            'salePrice'     => (int)$p['sale_price'],
            'stock'         => (int)$p['stock'],
            'unit'          => $p['unit'],
            'alertSeuil'    => (int)$p['alert_threshold'],
        ];
    }

    switch ($action) {

        // ------------------------------------------------ session
        case 'me':
            out(['user' => current_user(), 'shopName' => shop_name($pdo)]);
            break;

        case 'login':
            $username = strtolower(trim((string)(isset($body['username']) ? $body['username'] : '')));
            $password = trim((string)(isset($body['password']) ? $body['password'] : ''));
            $st = $pdo->prepare('SELECT id, username, display_name, password_hash, role FROM users WHERE username = ?');
            $st->execute([$username]);
            $row = $st->fetch();
            if (!$row || !hash_equals((string)$row['password_hash'], hash('sha256', $password))) {
                usleep(600000);
                fail('Identifiant ou mot de passe incorrect.', 401);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'          => (int)$row['id'],
                'username'    => $row['username'],
                'displayName' => $row['display_name'],
                'role'        => $row['role'],
            ];
            out(['user' => $_SESSION['user']]);
            break;

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);
            break;

        // ------------------------------------------------ lecture des données
        case 'bootstrap':
            $u = require_login();
            $admin = ($u['role'] === 'admin');

            $products = [];
            foreach ($pdo->query('SELECT id, name, category, purchase_price, sale_price, stock, unit, alert_threshold FROM products ORDER BY name')->fetchAll() as $p) {
                $products[] = map_product($p, $admin);
            }

            $sales = [];
            $rows = $pdo->query('SELECT id, sale_date, seller_name, subtotal, discount, total, paid, change_given, payment_mode FROM sales ORDER BY sale_date DESC, id DESC LIMIT 300')->fetchAll();
            foreach ($rows as $r) {
                $sales[(int)$r['id']] = [
                    'id'       => (string)$r['id'],
                    'date'     => iso($r['sale_date']),
                    'items'    => [],
                    'subtotal' => (int)$r['subtotal'],
                    'remise'   => (int)$r['discount'],
                    'total'    => (int)$r['total'],
                    'paid'     => (int)$r['paid'],
                    'change'   => (int)$r['change_given'],
                    'mode'     => $r['payment_mode'],
                    'vendeur'  => $r['seller_name'],
                ];
            }
            if (count($sales) > 0) {
                $ids = array_keys($sales);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare('SELECT sale_id, product_id, product_name, qty, unit_price, cost_price, line_total FROM sale_items WHERE sale_id IN (' . $in . ') ORDER BY id');
                $st->execute($ids);
                foreach ($st->fetchAll() as $it) {
                    $sales[(int)$it['sale_id']]['items'][] = [
                        'productId' => $it['product_id'] === null ? null : (string)$it['product_id'],
                        'name'      => $it['product_name'],
                        'qty'       => (int)$it['qty'],
                        'unitPrice' => (int)$it['unit_price'],
                        'costPrice' => $admin ? (int)$it['cost_price'] : 0,
                        'lineTotal' => (int)$it['line_total'],
                    ];
                }
            }
            $sales = array_values($sales);

            $purchases = [];
            if ($admin) {
                foreach ($pdo->query('SELECT id, purchase_date, product_id, product_name, qty, unit_cost, total, supplier FROM purchases ORDER BY purchase_date DESC, id DESC LIMIT 300')->fetchAll() as $a) {
                    $purchases[] = [
                        'id'          => (string)$a['id'],
                        'date'        => iso($a['purchase_date']),
                        'productId'   => $a['product_id'] === null ? null : (string)$a['product_id'],
                        'name'        => $a['product_name'],
                        'qty'         => (int)$a['qty'],
                        'unitCost'    => (int)$a['unit_cost'],
                        'total'       => (int)$a['total'],
                        'fournisseur' => $a['supplier'],
                    ];
                }
            }

            out([
                'products'  => $products,
                'sales'     => $sales,
                'purchases' => $purchases,
                'shopName'  => shop_name($pdo),
            ]);
            break;

        // ------------------------------------------------ vente (caisse)
        case 'sale_create':
            $u = require_login();
            $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
            if (count($items) === 0) {
                fail('Le panier est vide.');
            }
            $remise = max(0, (int)(isset($body['remise']) ? $body['remise'] : 0));
            $paid   = max(0, (int)(isset($body['paid']) ? $body['paid'] : 0));
            $mode   = isset($body['mode']) ? (string)$body['mode'] : 'Espèces';
            if (!in_array($mode, ['Espèces', 'Mobile Money', 'Carte bancaire'], true)) {
                $mode = 'Espèces';
            }

            $qtys = [];
            foreach ($items as $it) {
                $pid = (int)(isset($it['productId']) ? $it['productId'] : 0);
                $q   = (int)(isset($it['qty']) ? $it['qty'] : 0);
                if ($pid <= 0 || $q <= 0) {
                    fail('Article invalide dans le panier.');
                }
                $qtys[$pid] = (isset($qtys[$pid]) ? $qtys[$pid] : 0) + $q;
            }
            $ids = array_keys($qtys);
            sort($ids);

            $pdo->beginTransaction();
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare('SELECT id, name, purchase_price, sale_price, stock FROM products WHERE id IN (' . $in . ') FOR UPDATE');
                $st->execute($ids);
                $prods = [];
                foreach ($st->fetchAll() as $p) {
                    $prods[(int)$p['id']] = $p;
                }

                $lines = [];
                $subtotal = 0;
                foreach ($ids as $pid) {
                    if (!isset($prods[$pid])) {
                        throw new RuntimeException('Produit introuvable.');
                    }
                    $p = $prods[$pid];
                    $q = $qtys[$pid];
                    if ((int)$p['stock'] < $q) {
                        throw new RuntimeException('Stock insuffisant pour ' . $p['name'] . '.');
                    }
                    $lineTotal = $q * (int)$p['sale_price'];
                    $subtotal += $lineTotal;
                    $lines[] = [
                        'pid'   => $pid,
                        'name'  => $p['name'],
                        'qty'   => $q,
                        'unit'  => (int)$p['sale_price'],
                        'cost'  => (int)$p['purchase_price'],
                        'total' => $lineTotal,
                    ];
                }
                $total = max(0, $subtotal - $remise);
                if ($paid < $total) {
                    throw new RuntimeException('Montant reçu insuffisant.');
                }

                $pdo->prepare('INSERT INTO sales (sale_date, user_id, seller_name, subtotal, discount, total, paid, change_given, payment_mode) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([gmdate('Y-m-d H:i:s'), $u['id'], $u['displayName'], $subtotal, $remise, $total, $paid, $paid - $total, $mode]);
                $saleId = (int)$pdo->lastInsertId();

                $insItem = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, cost_price, line_total) VALUES (?,?,?,?,?,?,?)');
                $updStock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
                foreach ($lines as $l) {
                    $insItem->execute([$saleId, $l['pid'], $l['name'], $l['qty'], $l['unit'], $l['cost'], $l['total']]);
                    $updStock->execute([$l['qty'], $l['pid']]);
                }
                $pdo->commit();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                fail($e->getMessage(), 422);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                fail('Erreur serveur lors de la vente.', 500);
            }
            out(['ok' => true, 'saleId' => $saleId, 'total' => $total, 'change' => $paid - $total]);
            break;

        // ------------------------------------------------ produits (admin)
        case 'product_save':
            require_admin();
            $id       = (int)(isset($body['id']) ? $body['id'] : 0);
            $name     = cut(trim((string)(isset($body['name']) ? $body['name'] : '')), 150);
            $category = cut(trim((string)(isset($body['category']) ? $body['category'] : '')), 80);
            $unit     = cut(trim((string)(isset($body['unit']) ? $body['unit'] : '')), 30);
            if ($name === '') {
                fail('Le nom du produit est requis.');
            }
            if ($category === '') { $category = 'Divers'; }
            if ($unit === '') { $unit = 'pièce'; }
            $buy   = max(0, (int)(isset($body['purchasePrice']) ? $body['purchasePrice'] : 0));
            $sell  = max(0, (int)(isset($body['salePrice']) ? $body['salePrice'] : 0));
            $stock = (int)(isset($body['stock']) ? $body['stock'] : 0);
            $alert = (int)(isset($body['alertSeuil']) ? $body['alertSeuil'] : 5);
            if ($id > 0) {
                $pdo->prepare('UPDATE products SET name=?, category=?, purchase_price=?, sale_price=?, stock=?, unit=?, alert_threshold=? WHERE id=?')
                    ->execute([$name, $category, $buy, $sell, $stock, $unit, $alert, $id]);
            } else {
                $pdo->prepare('INSERT INTO products (name, category, purchase_price, sale_price, stock, unit, alert_threshold) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $category, $buy, $sell, $stock, $unit, $alert]);
                $id = (int)$pdo->lastInsertId();
            }
            out(['ok' => true, 'id' => (string)$id]);
            break;

        case 'product_delete':
            require_admin();
            $id = (int)(isset($body['id']) ? $body['id'] : 0);
            if ($id <= 0) {
                fail('Produit invalide.');
            }
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            out(['ok' => true]);
            break;

        // ------------------------------------------------ achats (admin)
        case 'purchase_create':
            $u = require_admin();
            $qty      = (int)(isset($body['qty']) ? $body['qty'] : 0);
            $unitCost = (int)(isset($body['unitCost']) ? $body['unitCost'] : 0);
            $supplier = cut(trim((string)(isset($body['supplier']) ? $body['supplier'] : '')), 120);
            if ($supplier === '') { $supplier = '—'; }
            $date = isset($body['date']) ? (string)$body['date'] : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = gmdate('Y-m-d');
            }
            $when = $date . ' ' . gmdate('H:i:s');
            if ($qty <= 0 || $unitCost < 0) {
                fail('Vérifiez la quantité et le prix d\'achat.');
            }

            $pdo->beginTransaction();
            try {
                $productId = (int)(isset($body['productId']) ? $body['productId'] : 0);
                if ($productId > 0) {
                    $st = $pdo->prepare('SELECT id, name FROM products WHERE id = ? FOR UPDATE');
                    $st->execute([$productId]);
                    $p = $st->fetch();
                    if (!$p) {
                        throw new RuntimeException('Produit introuvable.');
                    }
                    $pdo->prepare('UPDATE products SET stock = stock + ?, purchase_price = ? WHERE id = ?')
                        ->execute([$qty, $unitCost, $productId]);
                    $pname = $p['name'];
                } else {
                    $np = (isset($body['newProduct']) && is_array($body['newProduct'])) ? $body['newProduct'] : [];
                    $pname = cut(trim((string)(isset($np['name']) ? $np['name'] : '')), 150);
                    if ($pname === '') {
                        throw new RuntimeException('Le nom du nouveau produit est requis.');
                    }
                    $cat = cut(trim((string)(isset($np['category']) ? $np['category'] : '')), 80);
                    if ($cat === '') { $cat = 'Divers'; }
                    $sp = max(0, (int)(isset($np['salePrice']) ? $np['salePrice'] : 0));
                    $pdo->prepare('INSERT INTO products (name, category, purchase_price, sale_price, stock, unit, alert_threshold) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$pname, $cat, $unitCost, $sp, $qty, 'pièce', 5]);
                    $productId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('INSERT INTO purchases (purchase_date, product_id, product_name, qty, unit_cost, total, supplier, user_id) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$when, $productId, $pname, $qty, $unitCost, $qty * $unitCost, $supplier, $u['id']]);
                $pdo->commit();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                fail($e->getMessage(), 422);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                fail('Erreur serveur lors de l\'enregistrement de l\'achat.', 500);
            }
            out(['ok' => true]);
            break;

        // ------------------------------------------------ paramètres (admin)
        case 'shop_name':
            require_admin();
            $name = cut(trim((string)(isset($body['name']) ? $body['name'] : '')), 120);
            if ($name === '') {
                $name = 'Cosmétiques d\'Alger';
            }
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['shop_name', $name]);
            out(['ok' => true, 'shopName' => $name]);
            break;

        default:
            fail('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    fail('Erreur serveur.', 500);
}
