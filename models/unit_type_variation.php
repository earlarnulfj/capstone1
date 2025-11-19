<?php
class UnitTypeVariation {
    private $conn;
    private $table_name = 'unit_type_variations';

    public function __construct(PDO $db) { $this->conn = $db; }

    public function create(int $unit_type_id, string $attribute, string $value, ?string $description = null, $metadata = null) {
        if ($unit_type_id <= 0 || $attribute === '' || $value === '') { return false; }
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("INSERT INTO {$this->table_name} (unit_type_id, attribute, value, description, metadata) VALUES (:unit_type_id, :attribute, :value, :description, :metadata)");
            $stmt->bindParam(':unit_type_id', $unit_type_id, PDO::PARAM_INT);
            $stmt->bindParam(':attribute', $attribute);
            $stmt->bindParam(':value', $value);
            if ($description === null) { $stmt->bindValue(':description', null, PDO::PARAM_NULL); }
            else { $stmt->bindParam(':description', $description); }
            if ($metadata === null) { $stmt->bindValue(':metadata', null, PDO::PARAM_NULL); }
            else { $meta = is_string($metadata) ? $metadata : json_encode($metadata); $stmt->bindParam(':metadata', $meta); }
            $ok = $stmt->execute();
            if (!$ok) { $this->conn->rollBack(); return false; }
            $this->conn->commit();
            $lid = (int)$this->conn->lastInsertId();
            if ($lid === 0) {
                try { $lid = (int)$this->conn->query('SELECT LAST_INSERT_ID()')->fetchColumn(); } catch (Throwable $e) { /* ignore */ }
            }
            return $lid > 0 ? $lid : true;
        } catch (Throwable $e) { $this->conn->rollBack(); return false; }
    }

    public function readByUnitType(int $unit_type_id, bool $includeDeleted = false) {
        $sql = "SELECT * FROM {$this->table_name} WHERE unit_type_id = :unit_type_id" . ($includeDeleted ? '' : " AND is_deleted = 0") . " ORDER BY attribute, value";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':unit_type_id', $unit_type_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function readById(int $id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function update(int $id, array $fields) {
        if ($id <= 0 || empty($fields)) { return false; }
        $allowed = ['attribute','value','description','metadata'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = :$k";
            if ($k === 'metadata' && $v !== null && !is_string($v)) { $v = json_encode($v); }
            $params[":$k"] = $v;
        }
        if (empty($set)) { return false; }
        $sql = "UPDATE {$this->table_name} SET " . implode(',', $set) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare($sql);
            foreach ($params as $k => $v) {
                if ($v === null && ($k === ':description' || $k === ':metadata')) { $stmt->bindValue($k, null, PDO::PARAM_NULL); }
                else { $stmt->bindValue($k, $v); }
            }
            $ok = $stmt->execute();
            if (!$ok) { $this->conn->rollBack(); return false; }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) { $this->conn->rollBack(); return false; }
    }

    public function softDelete(int $id) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET is_deleted = 1, deleted_at = NOW() WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $ok = $stmt->execute();
            if (!$ok) { $this->conn->rollBack(); return false; }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) { $this->conn->rollBack(); return false; }
    }

    public function restore(int $id) {
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET is_deleted = 0, deleted_at = NULL WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function deleteHard(int $id) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table_name} WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $ok = $stmt->execute();
            if (!$ok) { $this->conn->rollBack(); return false; }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) { $this->conn->rollBack(); return false; }
    }

    public function getByUnique(int $unit_type_id, string $attribute, string $value) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE unit_type_id = :unit_type_id AND attribute = :attribute AND value = :value LIMIT 1");
        $stmt->bindParam(':unit_type_id', $unit_type_id, PDO::PARAM_INT);
        $stmt->bindParam(':attribute', $attribute);
        $stmt->bindParam(':value', $value);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function renameValue(int $unit_type_id, string $attribute, string $currentValue, string $newValue) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET value = :newValue, updated_at = CURRENT_TIMESTAMP WHERE unit_type_id = :unit_type_id AND attribute = :attribute AND value = :currentValue");
            $stmt->bindParam(':newValue', $newValue);
            $stmt->bindParam(':unit_type_id', $unit_type_id, PDO::PARAM_INT);
            $stmt->bindParam(':attribute', $attribute);
            $stmt->bindParam(':currentValue', $currentValue);
            $ok = $stmt->execute();
            if (!$ok) { $this->conn->rollBack(); return false; }
            $this->conn->commit();
            return $stmt->rowCount();
        } catch (Throwable $e) { $this->conn->rollBack(); return false; }
    }
}
?>