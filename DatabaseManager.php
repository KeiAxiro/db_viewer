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

    public function getPrimaryKey($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
            foreach($cols as $c) { if($c['pk'] > 0) return $c['name']; }
            return null;
        }
        $pkQuery = $this->pdo->prepare("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        $pkQuery->execute();
        $pkResult = $pkQuery->fetch(PDO::FETCH_ASSOC);
        return $pkResult ? $pkResult['Column_name'] : null;
    }

    public function getRelations() {
        $relations = [];

        // 1. Ambil Relasi Resmi (Jika ada)
        if ($this->driver === 'sqlite') {
            $tables = $this->getTables();
            foreach ($tables as $t) {
                try {
                    $stmt = $this->pdo->query("PRAGMA foreign_key_list('$t')");
                    if ($stmt) {
                        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($fks as $fk) {
                            $targetCol = (isset($fk['to']) && $fk['to']) ? $fk['to'] : $this->getPrimaryKey($fk['table']);
                            $relations[] = [
                                'TABLE_NAME' => $t,
                                'COLUMN_NAME' => $fk['from'],
                                'REFERENCED_TABLE_NAME' => $fk['table'],
                                'REFERENCED_COLUMN_NAME' => $targetCol ?: 'id'
                            ];
                        }
                    }
                } catch (Exception $e) { continue; }
            }
        } else {
            $sql = "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(["db" => $this->dbName]);
            $relations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 2. SMART MAPPING V2 (Super Agresif untuk SQLite)
        $allTables = $this->getTables();
        foreach ($allTables as $sourceTable) {
            $cols = $this->getTableColumns($sourceTable);
            $pkSource = $this->getPrimaryKey($sourceTable) ?: '';

            foreach ($cols as $col) {
                if (strcasecmp($col, $pkSource) === 0 || strtolower($col) === 'id') continue;

                $prefix = "";
                $colLower = strtolower($col);

                if (preg_match('/^(.+?)[_-]?(id|no|code)$/i', $col, $matches)) {
                    $prefix = strtolower($matches[1]);
                } else {
                    $prefix = $colLower;
                }

                if ($prefix !== "") {
                    foreach ($allTables as $targetTable) {
                        if (strcasecmp($targetTable, $sourceTable) === 0) continue; 
                        
                        $targetLower = strtolower($targetTable);
                        if ($targetLower === $prefix || 
                            $targetLower === $prefix . "s" || 
                            $targetLower === $prefix . "es" ||
                            $prefix === $targetLower . "s") 
                        {
                            $exists = false;
                            foreach ($relations as $rel) {
                                if (strcasecmp($rel['TABLE_NAME'], $sourceTable) === 0 && strcasecmp($rel['COLUMN_NAME'], $col) === 0) {
                                    $exists = true; break;
                                }
                            }

                            if (!$exists) {
                                $targetPk = $this->getPrimaryKey($targetTable);
                                $relations[] = [
                                    'TABLE_NAME' => $sourceTable,
                                    'COLUMN_NAME' => $col,
                                    'REFERENCED_TABLE_NAME' => $targetTable,
                                    'REFERENCED_COLUMN_NAME' => $targetPk ?: 'id'
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $relations;
    }

    public function getTableColumns($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function($c) { return $c['name']; }, $cols);
        }
        return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getTableColumnsWithType($table) {
        if ($this->driver === 'sqlite') {
            $cols = $this->pdo->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function($c) { return ['Field' => $c['name'], 'Type' => $c['type']]; }, $cols);
        }
        return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buildRawQuery($table) {
        return "SELECT * FROM `$table` LIMIT 500";
    }

    public function buildJoinQuery($table, $relations) {
        $select = "SELECT `$table`.*";
        $joins = "";
        $aliasCount = [];
        $found = false;

        foreach ($relations as $r) {
            if (strcasecmp(trim($r['TABLE_NAME']), trim($table)) === 0) {
                $refTable = trim($r['REFERENCED_TABLE_NAME']);
                $refCol = trim($r['REFERENCED_COLUMN_NAME']);
                $localCol = trim($r['COLUMN_NAME']);

                $cols = $this->getTableColumns($refTable);
                
                $actualRefCol = null;
                foreach ($cols as $c) {
                    if (strcasecmp(trim($c), $refCol) === 0) {
                        $actualRefCol = $c; 
                        break;
                    }
                }

                if (!$actualRefCol) continue; 

                $refCol = $actualRefCol; 
                $found = true;
                
                $aliasCount[$refTable] = isset($aliasCount[$refTable]) ? $aliasCount[$refTable] + 1 : 1;
                $alias = $refTable . "_" . $aliasCount[$refTable];

                foreach ($cols as $c) {
                    if (strcasecmp($c, $refCol) !== 0) {
                        $select .= ", `$alias`.`$c` AS `{$alias}_{$c}`";
                    }
                }
                $joins .= " LEFT JOIN `$refTable` AS `$alias` ON `$table`.`$localCol` = `$alias`.`$refCol`";
            }
        }
        
        return $found ? "$select FROM `$table` $joins LIMIT 500" : null;
    }

    public function fetchData($query) {
        if (!$query || trim($query) === "") return [];
        try {
            return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function fetchTracedData($targetTable, $whereCol, $whereVal, $mode, $relations) {
        if ($mode === 'join') {
            $joinQuery = $this->buildJoinQuery($targetTable, $relations);
            if ($joinQuery !== null) {
                // Limit dinaikkan agar lebih banyak baris yang terlihat di Deep Inspector
                $sql = str_replace("LIMIT 500", "WHERE `$targetTable`.`$whereCol` = :val LIMIT 250", $joinQuery);
            } else {
                $sql = "SELECT * FROM `$targetTable` WHERE `$whereCol` = :val LIMIT 250";
            }
        } else {
            $sql = "SELECT * FROM `$targetTable` WHERE `$whereCol` = :val LIMIT 250";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['val' => $whereVal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // FUNGSI BARU: Menarik relasi dari BANYAK id sekaligus (Agregasi)
    private function fetchTracedDataMultiple($targetTable, $whereCol, $whereVals, $mode, $relations) {
        if (empty($whereVals)) return [];
        $inQuery = implode(',', array_fill(0, count($whereVals), '?'));
        
        if ($mode === 'join') {
            $joinQuery = $this->buildJoinQuery($targetTable, $relations);
            if ($joinQuery !== null) {
                $sql = str_replace("LIMIT 500", "WHERE `$targetTable`.`$whereCol` IN ($inQuery) LIMIT 250", $joinQuery);
            } else {
                $sql = "SELECT * FROM `$targetTable` WHERE `$whereCol` IN ($inQuery) LIMIT 250";
            }
        } else {
            $sql = "SELECT * FROM `$targetTable` WHERE `$whereCol` IN ($inQuery) LIMIT 250";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($whereVals));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function traceValueAdvanced($table, $column, $value, $mode = 'raw') {
        $results = ['source_row' => [], 'row_relations' => [], 'value_relations' => [], 'global_matches' => []];
        $relations = $this->getRelations();
        $actualCols = $this->getTableColumns($table);

        if (in_array($column, $actualCols)) {
            // Ambil semua baris yang cocok dengan yang diklik (hingga 250 baris)
            $stmtSource = $this->pdo->prepare("SELECT * FROM `$table` WHERE `$column` = :val LIMIT 250");
            $stmtSource->execute(['val' => $value]);
            $sourceRows = $stmtSource->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($sourceRows)) {
                $sourceData = $this->fetchTracedData($table, $column, $value, $mode, $relations);
                $results['source_row'][] = ['type' => 'Data Utama Terpilih', 'table' => $table, 'data' => $sourceData];

                $pkCol = $this->getPrimaryKey($table) ?: '';
                
                // 1. Kumpulkan semua nilai Primary Key dari baris yang ditemukan
                $pkValues = [];
                if ($pkCol !== '') {
                    foreach ($sourceRows as $r) {
                        if (isset($r[$pkCol]) && $r[$pkCol] !== null) {
                            $pkValues[] = $r[$pkCol];
                        }
                    }
                    $pkValues = array_unique($pkValues);
                }

                // 2. Child Relations (Agregasi)
                if (!empty($pkValues)) {
                    foreach ($relations as $rel) {
                        if (strcasecmp($rel['REFERENCED_TABLE_NAME'], $table) === 0 && strcasecmp($rel['REFERENCED_COLUMN_NAME'], $pkCol) === 0) {
                            $cTable = $rel['TABLE_NAME']; 
                            $cCol = $rel['COLUMN_NAME'];
                            if ($data = $this->fetchTracedDataMultiple($cTable, $cCol, $pkValues, $mode, $relations)) {
                                $results['row_relations'][] = ['type' => "Dipakai di (Child)", 'table' => $cTable, 'data' => $data];
                            }
                        }
                    }
                }

                // 3. Parent Relations (Agregasi)
                foreach ($relations as $rel) {
                    if (strcasecmp($rel['TABLE_NAME'], $table) === 0) {
                        $pTable = $rel['REFERENCED_TABLE_NAME']; 
                        $pCol = $rel['REFERENCED_COLUMN_NAME']; 
                        $fkCol = $rel['COLUMN_NAME'];
                        
                        // Kumpulkan semua nilai Foreign Key
                        $fkValues = [];
                        foreach ($sourceRows as $r) {
                            if (isset($r[$fkCol]) && $r[$fkCol] !== null) {
                                $fkValues[] = $r[$fkCol];
                            }
                        }
                        $fkValues = array_unique($fkValues);
                        
                        if (!empty($fkValues)) {
                            if ($data = $this->fetchTracedDataMultiple($pTable, $pCol, $fkValues, $mode, $relations)) {
                                $results['row_relations'][] = ['type' => "Bersumber dari (Parent)", 'table' => $pTable, 'data' => $data];
                            }
                        }
                    }
                }
            }
        }

        // Child By Value (tetap sama)
        foreach ($relations as $rel) {
            if (strcasecmp($rel['REFERENCED_TABLE_NAME'], $table) === 0 && strcasecmp($rel['REFERENCED_COLUMN_NAME'], $column) === 0) {
                if ($data = $this->fetchTracedData($rel['TABLE_NAME'], $rel['COLUMN_NAME'], $value, $mode, $relations)) {
                    $results['value_relations'][] = ['type' => 'Child (By Value)', 'table' => $rel['TABLE_NAME'], 'data' => $data];
                }
            }
        }

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
                    $stmt3 = $this->pdo->prepare("SELECT * FROM `$t` WHERE $where LIMIT 15");
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
        
        $tempDir = __DIR__ . '/temp_db';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
        
        $dbFile = $tempDir . "/{$table}_" . time() . ".sqlite";
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
        $tempDir = __DIR__ . '/temp_db';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
        
        $dbFile = $tempDir . "/{$this->dbName}_FULL_" . time() . ".sqlite";
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