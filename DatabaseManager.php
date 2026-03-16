<?php
// DatabaseManager.php

class DatabaseManager {
    private $pdo;
    private $dbName;
    private $driver;

    public function __construct($pdo, $dbName, $driver = 'mysql') {
        $this->pdo = $pdo;
        $this->dbName = $dbName;
        $this->driver = $driver;
    }

    public function getTables() {
        if ($this->driver === 'sqlite') {
            return $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        }
        return $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getRelations() {
        if ($this->driver === 'sqlite') {
            $relations = [];
            $tables = $this->getTables();
            foreach ($tables as $t) {
                $fks = $this->pdo->query("PRAGMA foreign_key_list(`$t`)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($fks as $fk) {
                    $relations[] = [
                        'TABLE_NAME' => $t,
                        'COLUMN_NAME' => $fk['from'],
                        'REFERENCED_TABLE_NAME' => $fk['table'],
                        'REFERENCED_COLUMN_NAME' => $fk['to']
                    ];
                }
            }
            return $relations;
        }

        $sql = "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(["db" => $this->dbName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTableColumns($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function($c) { return $c['name']; }, $cols);
        }
        return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getTableColumnsWithType($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function($c) { return ['Field' => $c['name'], 'Type' => $c['type']]; }, $cols);
        }
        return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPrimaryKey($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach($cols as $c) { if($c['pk'] > 0) return $c['name']; }
            return null;
        }
        $pkQuery = $this->pdo->prepare("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        $pkQuery->execute();
        $pkResult = $pkQuery->fetch(PDO::FETCH_ASSOC);
        return $pkResult ? $pkResult['Column_name'] : null;
    }

    public function buildRawQuery($table) {
        return "SELECT * FROM `$table` LIMIT 500";
    }

    public function buildJoinQuery($table, $relations) {
        $select = "SELECT `$table`.*";
        $joins = "";
        $aliasCount = [];

        foreach ($relations as $r) {
            if ($r['TABLE_NAME'] == $table) {
                $refTable = $r['REFERENCED_TABLE_NAME'];
                $refCol = $r['REFERENCED_COLUMN_NAME'];
                $aliasCount[$refTable] = isset($aliasCount[$refTable]) ? $aliasCount[$refTable] + 1 : 1;
                $alias = $refTable . "_" . $aliasCount[$refTable];

                $cols = $this->getTableColumns($refTable);
                foreach ($cols as $c) {
                    if ($c != $refCol) $select .= ", `$alias`.`$c` AS `{$alias}_{$c}`";
                }
                $joins .= " LEFT JOIN `$refTable` AS `$alias` ON `$table`.`{$r['COLUMN_NAME']}` = `$alias`.`$refCol`";
            }
        }
        return "$select FROM `$table` $joins LIMIT 500";
    }

    public function fetchData($query) {
        try {
            return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function fetchTracedData($targetTable, $whereCol, $whereVal, $mode, $relations) {
        if ($mode === 'join') {
            $select = "SELECT `$targetTable`.*";
            $joins = "";
            $aliasCount = [];
            foreach ($relations as $r) {
                if ($r['TABLE_NAME'] == $targetTable) {
                    $refTable = $r['REFERENCED_TABLE_NAME'];
                    $refCol = $r['REFERENCED_COLUMN_NAME'];
                    $aliasCount[$refTable] = isset($aliasCount[$refTable]) ? $aliasCount[$refTable] + 1 : 1;
                    $alias = $refTable . "_" . $aliasCount[$refTable];
                    $cols = $this->getTableColumns($refTable);
                    foreach ($cols as $c) { if ($c != $refCol) $select .= ", `$alias`.`$c` AS `{$alias}_{$c}`"; }
                    $joins .= " LEFT JOIN `$refTable` AS `$alias` ON `$targetTable`.`{$r['COLUMN_NAME']}` = `$alias`.`$refCol`";
                }
            }
            $sql = "$select FROM `$targetTable` $joins WHERE `$targetTable`.`$whereCol` = :val LIMIT 20";
        } else {
            $sql = "SELECT * FROM `$targetTable` WHERE `$whereCol` = :val LIMIT 20";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['val' => $whereVal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // PERBAIKAN BUG: SQLSTATE[42S22] Column not found
    public function traceValueAdvanced($table, $column, $value, $mode = 'raw') {
        $results = ['source_row' => [], 'row_relations' => [], 'value_relations' => [], 'global_matches' => []];
        $relations = $this->getRelations();

        $actualCols = $this->getTableColumns($table);
        $sourceRows = [];

        // HANYA cari row utama jika kolom tersebut BENAR-BENAR ada di tabel (bukan kolom hasil JOIN)
        if (in_array($column, $actualCols)) {
            $stmtSource = $this->pdo->prepare("SELECT * FROM `$table` WHERE `$column` = :val LIMIT 1");
            $stmtSource->execute(['val' => $value]);
            $sourceRows = $stmtSource->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($sourceRows)) {
                $sourceData = $this->fetchTracedData($table, $column, $value, $mode, $relations);
                $results['source_row'][] = ['type' => 'Data Utama Terpilih', 'table' => $table, 'data' => $sourceData];

                $pkCol = $this->getPrimaryKey($table);
                if ($pkCol && isset($sourceRows[0][$pkCol])) {
                    $pkValue = $sourceRows[0][$pkCol];
                    // Child Relations
                    foreach ($relations as $rel) {
                        if ($rel['REFERENCED_TABLE_NAME'] === $table && $rel['REFERENCED_COLUMN_NAME'] === $pkCol) {
                            $cTable = $rel['TABLE_NAME']; $cCol = $rel['COLUMN_NAME'];
                            if ($data = $this->fetchTracedData($cTable, $cCol, $pkValue, $mode, $relations)) {
                                $results['row_relations'][] = ['type' => "Dipakai di (Child)", 'table' => $cTable, 'data' => $data];
                            }
                        }
                    }
                    // Parent Relations
                    foreach ($relations as $rel) {
                        if ($rel['TABLE_NAME'] === $table) {
                            $pTable = $rel['REFERENCED_TABLE_NAME']; $pCol = $rel['REFERENCED_COLUMN_NAME']; $fkCol = $rel['COLUMN_NAME'];
                            if (isset($sourceRows[0][$fkCol]) && $sourceRows[0][$fkCol] !== null) {
                                if ($data = $this->fetchTracedData($pTable, $pCol, $sourceRows[0][$fkCol], $mode, $relations)) {
                                    $results['row_relations'][] = ['type' => "Bersumber dari (Parent)", 'table' => $pTable, 'data' => $data];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Value Relations
        foreach ($relations as $rel) {
            if ($rel['REFERENCED_TABLE_NAME'] === $table && $rel['REFERENCED_COLUMN_NAME'] === $column) {
                if ($data = $this->fetchTracedData($rel['TABLE_NAME'], $rel['COLUMN_NAME'], $value, $mode, $relations)) {
                    $results['value_relations'][] = ['type' => 'Child (By Value)', 'table' => $rel['TABLE_NAME'], 'data' => $data];
                }
            }
        }

        // Global Match
        $allTables = $this->getTables();
        $isValueNumeric = is_numeric($value);

        foreach ($allTables as $t) {
            try {
                $cols = $this->getTableColumnsWithType($t);
                $searchConditions = []; $params = []; $colIndex = 0;
                
                foreach ($cols as $cData) {
                    $c = $cData['Field']; $type = strtolower($cData['Type']);
                    $isNumCol = preg_match('/int|float|double|decimal|numeric|real|bit|boolean/i', $type);
                    if (!$isValueNumeric && $isNumCol) continue; 
                    
                    $searchConditions[] = "`$c` = :val_$colIndex";
                    $params["val_$colIndex"] = $value; $colIndex++;
                }
                
                if (!empty($searchConditions)) {
                    $where = implode(" OR ", $searchConditions);
                    $stmt3 = $this->pdo->prepare("SELECT * FROM `$t` WHERE $where LIMIT 5");
                    $stmt3->execute($params);
                    if ($foundGlobal = $stmt3->fetchAll(PDO::FETCH_ASSOC)) {
                        $results['global_matches'][] = ['type' => 'Ditemukan Di', 'table' => $t, 'data' => $foundGlobal];
                    }
                }
            } catch (Exception $e) { continue; }
        }
        return $results;
    }

    public function exportToSQLite($table) {
        $data = $this->fetchData($this->buildRawQuery($table));
        if (empty($data)) return false;
        $dbFile = sys_get_temp_dir() . "/{$table}_" . time() . ".sqlite";
        $sqlite = new PDO("sqlite:" . $dbFile);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = array_keys($data[0]);
        $colDefs = implode(" TEXT, ", array_map(function($c) { return "`$c` TEXT"; }, $cols));
        $sqlite->exec("CREATE TABLE `$table` ($colDefs)");
        $placeholders = implode(", ", array_fill(0, count($cols), "?"));
        $insertStmt = $sqlite->prepare("INSERT INTO `$table` VALUES ($placeholders)");
        $sqlite->beginTransaction();
        foreach ($data as $row) { $insertStmt->execute(array_values($row)); }
        $sqlite->commit();
        return $dbFile;
    }

    public function exportFullDatabaseToSQLite() {
        $dbFile = sys_get_temp_dir() . "/{$this->dbName}_FULL_" . time() . ".sqlite";
        $sqlite = new PDO("sqlite:" . $dbFile);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $data = $this->fetchData($this->buildRawQuery($table));
            if (empty($data)) continue;
            $cols = array_keys($data[0]);
            $colDefs = implode(" TEXT, ", array_map(function($c) { return "`$c` TEXT"; }, $cols));
            $sqlite->exec("CREATE TABLE IF NOT EXISTS `$table` ($colDefs)");
            $placeholders = implode(", ", array_fill(0, count($cols), "?"));
            $insertStmt = $sqlite->prepare("INSERT INTO `$table` VALUES ($placeholders)");
            $sqlite->beginTransaction();
            foreach ($data as $row) { $insertStmt->execute(array_values($row)); }
            $sqlite->commit();
        }
        return $dbFile;
    }
}
?>