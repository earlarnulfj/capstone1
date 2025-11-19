<?php
// Alias REST-like API for Unit Types using name-only input
// Supports: GET (list), POST (create)

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

function readBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    // Fallback to form POST
    if (!empty($_POST)) return $_POST;
    return [];
}

function jsonOut($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
}

function normalize_code(string $name): string {
    $n = strtolower(trim($name));
    // Remove leading 'per ' if present
    if (strpos($n, 'per ') === 0) $n = substr($n, 4);
    // slug to alphanumeric
    $slug = preg_replace('/[^a-z0-9]+/', '', $n);
    if ($slug === '') $slug = 'ut';
    // limit to 16 chars
    return substr($slug, 0, 16);
}

try {
    $db = (new Database())->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->query("SELECT id, name FROM unit_types WHERE COALESCE(is_deleted,0)=0 ORDER BY name ASC");
        $items = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $items[] = $r; }
        jsonOut(['items' => $items]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = readBody();
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '') { jsonOut(['error' => 'name is required'], 400); return; }
        if (strlen($name) > 64) { jsonOut(['error' => 'name too long'], 400); return; }

        // Unique by name (case-insensitive)
        $dupe = $db->prepare('SELECT id FROM unit_types WHERE LOWER(name) = LOWER(:n) AND COALESCE(is_deleted,0)=0 LIMIT 1');
        $dupe->execute([':n' => $name]);
        if ($dupe->fetch(PDO::FETCH_ASSOC)) { jsonOut(['error' => 'duplicate name'], 409); return; }

        // Generate code from name, ensure uniqueness on code as well
        $code = normalize_code($name);
        $check = $db->prepare('SELECT COUNT(*) FROM unit_types WHERE code = :c LIMIT 1');
        $suffix = 1;
        while (true) {
            $check->execute([':c' => $code]);
            if ((int)$check->fetchColumn() === 0) break;
            $sfx = (string)$suffix++;
            $code = substr(normalize_code($name) . $sfx, 0, 16);
        }

        // Insert
        $db->beginTransaction();
        try {
            $ins = $db->prepare('INSERT INTO unit_types (code, name) VALUES (:c, :n)');
            $ok = $ins->execute([':c' => $code, ':n' => $name]);
            if (!$ok) { throw new RuntimeException('insert failed'); }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonOut(['error' => $e->getMessage()], 500);
            return;
        }

        // Return new list with the created item highlighted
        $stmt = $db->query("SELECT id, name FROM unit_types WHERE COALESCE(is_deleted,0)=0 ORDER BY name ASC");
        $items = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $items[] = $r; }
        jsonOut(['created' => [ 'code' => $code, 'name' => $name ], 'items' => $items], 201);
        return;
    }

    jsonOut(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    jsonOut(['error' => $e->getMessage()], 500);
}