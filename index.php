<?php
// index.php
require_once 'config.php';
require_once 'DatabaseManager.php';

// Fungsi Helper Rahasia: Mengubah array PHP menjadi Tabel di SQLite Sementara
function convertArrayToSQLite($pdo, $tableName, $data)
{
    if (empty($data)) {
        $pdo->exec("CREATE TABLE `$tableName` (`id` INTEGER PRIMARY KEY)");
        return;
    }
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $tableName);

    $allKeys = [];
    foreach ($data as $row) {
        if (is_array($row)) {
            foreach (array_keys($row) as $k)
                $allKeys[$k] = true;
        }
    }
    $cols = array_keys($allKeys);
    if (empty($cols))
        $cols = ['Value'];

    $colDefs = implode(", ", array_map(function ($c) {
        $cClean = preg_replace('/[^a-zA-Z0-9_]/', '_', $c);
        return "`$cClean` TEXT";
    }, $cols));

    $pdo->exec("DROP TABLE IF EXISTS `$tableName`");
    $pdo->exec("CREATE TABLE `$tableName` ($colDefs)");

    $placeholders = implode(", ", array_fill(0, count($cols), "?"));
    $stmt = $pdo->prepare("INSERT INTO `$tableName` VALUES ($placeholders)");

    $pdo->beginTransaction();
    foreach ($data as $item) {
        $rowVals = [];
        if (is_scalar($item)) {
            $rowVals[] = $item;
        } else {
            foreach ($cols as $c) {
                $val = $item[$c] ?? null;
                $rowVals[] = is_scalar($val) ? $val : ($val === null ? null : json_encode($val));
            }
        }
        $stmt->execute($rowVals);
    }
    $pdo->commit();
}

function getNativeColumns($pdo, $table, $driver)
{
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`$table`)");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }
    } catch (Exception $e) {
        return [];
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'save_theme') {
    $_SESSION['theme_color'] = $_POST['color'];
    $_SESSION['theme_color_hover'] = $_POST['hover'];
    echo json_encode(['success' => true]);
    exit;
}

$themeColor = $_SESSION['theme_color'] ?? '#3b82f6';
$themeHover = $_SESSION['theme_color_hover'] ?? '#2563eb';
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3)
    $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
list($r, $g, $b) = sscanf($hex, "%02x%02x%02x");
$themeSubtle = "rgba($r, $g, $b, 0.15)";

if (isset($_GET['action']) && $_GET['action'] === 'get_dbs') {
    header('Content-Type: application/json');
    try {
        $host = $_POST['host'] ?? 'localhost';
        $user = $_POST['user'] ?? 'root';
        $pass = $_POST['pass'] ?? '';
        $tempPdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
        $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbs = $tempPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'dbs' => $dbs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'disconnect') {
    unset($_SESSION['db_connection']);
    header("Location: index.php");
    exit;
}

$tempDir = __DIR__ . '/temp_db';
if (!is_dir($tempDir))
    @mkdir($tempDir, 0777, true);
if ($handle = opendir($tempDir)) {
    while (false !== ($file = readdir($handle))) {
        $filepath = $tempDir . '/' . $file;
        if (is_file($filepath) && time() - filemtime($filepath) > 43200)
            @unlink($filepath);
    }
    closedir($handle);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['connect_mysql'])) {
        $_SESSION['db_connection'] = [
            'driver' => 'mysql',
            'host' => $_POST['host'] ?? 'localhost',
            'user' => $_POST['user'] ?? 'root',
            'pass' => $_POST['pass'] ?? '',
            'dbname' => $_POST['dbname'] ?? 'db_voidbraver'
        ];
        header("Location: index.php");
        exit;
    } elseif (isset($_POST['connect_sqlite']) && isset($_FILES['sqlite_file'])) {
        $file = $_FILES['sqlite_file'];
        $allowed_exts = ['sqlite', 'sqlite3', 'db'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_exts)) {
            $conn_error = "Upload Gagal: Format file tidak didukung! Harap unggah file .sqlite, .sqlite3, atau .db.";
        } elseif ($file['error'] === UPLOAD_ERR_OK) {
            $dest = $tempDir . '/upload_' . time() . '_' . basename($file['name']);
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                $conn_error = "Gagal menyimpan file upload. Pastikan folder 'temp_db' memiliki izin Write (Chmod 777).";
            } else {
                $_SESSION['db_connection'] = ['driver' => 'sqlite', 'file' => $dest, 'dbname' => basename($file['name'])];
                header("Location: index.php");
                exit;
            }
        } else {
            $conn_error = "Upload Gagal: Error Code {$file['error']}";
        }
    } elseif (isset($_POST['connect_file']) && isset($_FILES['data_file'])) {
        $file = $_FILES['data_file'];
        $allowed_exts = ['json', 'csv'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_exts)) {
            $conn_error = "Upload Gagal: Format file tidak didukung! Harap unggah file .json atau .csv.";
        } elseif ($file['error'] === UPLOAD_ERR_OK) {
            $destSqlite = $tempDir . '/converted_' . time() . '_' . rand(1000, 9999) . '.sqlite';
            try {
                $pdoTemp = new PDO("sqlite:" . $destSqlite);
                $pdoTemp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                if ($file_ext === 'json') {
                    $content = file_get_contents($file['tmp_name']);
                    $json = json_decode($content, true);
                    $isPhpMyAdmin = false;
                    if (is_array($json)) {
                        foreach ($json as $item) {
                            if (is_array($item) && isset($item['type']) && $item['type'] === 'table') {
                                $isPhpMyAdmin = true;
                                break;
                            }
                        }
                    }
                    if ($isPhpMyAdmin) {
                        foreach ($json as $item) {
                            if (is_array($item) && isset($item['type']) && $item['type'] === 'table' && isset($item['name'])) {
                                convertArrayToSQLite($pdoTemp, $item['name'], $item['data'] ?? []);
                            }
                        }
                    } else {
                        if (!is_array($json) || (array_keys($json) !== range(0, count($json) - 1)))
                            $json = [$json];
                        convertArrayToSQLite($pdoTemp, basename($file['name'], '.json'), $json);
                    }
                } else if ($file_ext === 'csv') {
                    $data = [];
                    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
                        $header = fgetcsv($handle, 10000, ",");
                        if ($header) {
                            $header = array_map('trim', $header);
                            while (($row = fgetcsv($handle, 10000, ",")) !== FALSE) {
                                $tempRow = [];
                                foreach ($header as $i => $col) {
                                    $colName = $col !== '' ? $col : "Column_$i";
                                    $tempRow[$colName] = $row[$i] ?? '';
                                }
                                $data[] = $tempRow;
                            }
                        }
                        fclose($handle);
                        convertArrayToSQLite($pdoTemp, basename($file['name'], '.csv'), $data);
                    }
                }
                $_SESSION['db_connection'] = ['driver' => 'sqlite', 'file' => $destSqlite, 'dbname' => basename($file['name']) . ' (Parsed)'];
                header("Location: index.php");
                exit;
            } catch (Exception $e) {
                $conn_error = "Konversi Gagal: " . $e->getMessage();
                @unlink($destSqlite);
            }
        }
    }
}

if ($pdo === null):
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Voidbraver DB - Connect</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

            :root {
                --theme-color:
                    <?= $themeColor ?>
                ;
                --theme-color-hover:
                    <?= $themeHover ?>
                ;
                --theme-bg-subtle:
                    <?= $themeSubtle ?>
                ;
            }

            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                background-color: #0f172a;
                color: #e2e8f0;
            }

            .text-theme {
                color: var(--theme-color) !important;
            }

            .bg-theme {
                background-color: var(--theme-color) !important;
                color: white !important;
            }

            .border-theme {
                border-color: var(--theme-color) !important;
            }

            .hover-bg-theme:hover {
                background-color: var(--theme-color-hover) !important;
                color: white !important;
            }

            .glass-card {
                background: rgba(30, 41, 59, 0.8);
                backdrop-filter: blur(20px);
                border: 1px solid #334155;
            }

            select option {
                background-color: #0f172a;
                color: #e2e8f0;
                padding: 10px;
            }

            ::selection {
                background-color: var(--theme-color);
                color: #ffffff;
            }

            ::-moz-selection {
                background-color: var(--theme-color);
                color: #ffffff;
            }
        </style>
    </head>

    <body
        class="min-h-screen w-full flex items-center justify-center p-4 lg:p-8 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] overflow-y-auto selection:bg-theme selection:text-white">
        <div class="glass-card max-w-5xl w-full rounded-2xl shadow-2xl overflow-hidden flex flex-col lg:flex-row h-auto">
            <div class="flex-1 p-6 lg:p-10 border-b lg:border-b-0 lg:border-r border-slate-700 relative">
                <h2 class="text-2xl font-bold text-theme mb-2"><i class="fa-solid fa-server mr-2"></i> MySQL Server</h2>
                <p class="text-sm text-slate-400 mb-6">Hubungkan ke database lokal atau remote.</p>
                <?php if (isset($conn_error)): ?>
                    <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg text-xs mb-4"><i
                            class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($conn_error) ?></div>
                <?php endif; ?>
                <form method="POST" class="space-y-4" id="mysqlForm">
                    <input type="hidden" name="connect_mysql" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-xs font-bold text-slate-400 mb-1">Host</label><input type="text"
                                name="host" id="host" value="localhost"
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors">
                        </div>
                        <div><label class="block text-xs font-bold text-slate-400 mb-1">User</label><input type="text"
                                name="user" id="user" value="root"
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors">
                        </div>
                    </div>
                    <div><label class="block text-xs font-bold text-slate-400 mb-1">Password</label><input type="password"
                            name="pass" id="pass" placeholder="(Kosongkan jika XAMPP)"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors">
                    </div>
                    <div>
                        <div class="flex justify-between items-end mb-1">
                            <label class="block text-xs font-bold text-theme">Pilih Database</label>
                            <button type="button" onclick="loadDatabases()" id="btnLoadDb"
                                class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-md transition-colors"><i
                                    class="fa-solid fa-rotate mr-1"></i> Load List</button>
                        </div>
                        <input type="text" name="dbname" id="dbInput" value="db_voidbraver" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none text-theme font-bold tracking-wide focus:border-theme transition-colors">
                        <select id="dbSelect"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none text-theme font-bold cursor-pointer hidden focus:border-theme transition-colors"></select>
                    </div>
                    <button type="submit"
                        class="w-full bg-theme hover-bg-theme font-bold py-3 px-4 rounded-xl transition-colors mt-4 shadow-lg shadow-black/20">Connect
                        MySQL <i class="fa-solid fa-arrow-right ml-1"></i></button>
                </form>
            </div>
            <div class="flex-1 p-6 lg:p-10 bg-slate-800/50 flex flex-col justify-center">
                <h2 class="text-2xl font-bold text-emerald-500 mb-2"><i class="fa-solid fa-file-code mr-2"></i> SQLite File
                </h2>
                <p class="text-sm text-slate-400 mb-6">Unggah file .sqlite atau .db untuk diinspeksi.</p>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col flex-1" id="sqliteForm">
                    <input type="hidden" name="connect_sqlite" value="1">
                    <div
                        class="flex-1 border-2 border-dashed border-slate-600 rounded-xl flex flex-col items-center justify-center p-8 text-center hover:border-emerald-500 hover:bg-emerald-500/5 transition-colors group cursor-pointer relative min-h-[250px]">
                        <input type="file" name="sqlite_file" accept=".sqlite,.db,.sqlite3" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="if(this.files[0]) this.form.submit();">
                        <i id="uploadIcon"
                            class="fa-solid fa-cloud-arrow-up text-5xl text-slate-500 group-hover:text-emerald-500 mb-4 transition-colors"></i>
                        <p class="text-base font-bold text-slate-300 group-hover:text-emerald-400">Klik / Drop file SQLite
                        </p>
                    </div>
                </form>
            </div>
            <div
                class="flex-1 p-6 lg:p-10 border-t lg:border-t-0 lg:border-l border-slate-700 bg-slate-800/30 flex flex-col justify-center">
                <h2 class="text-2xl font-bold text-amber-400 mb-2"><i class="fa-solid fa-file-csv mr-2"></i> JSON / CSV</h2>
                <p class="text-sm text-slate-400 mb-6">Inspeksi data JSON/CSV dengan mode Super.</p>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col flex-1" id="fileForm">
                    <input type="hidden" name="connect_file" value="1">
                    <div
                        class="flex-1 border-2 border-dashed border-slate-600 rounded-xl flex flex-col items-center justify-center p-8 text-center hover:border-amber-400 hover:bg-amber-400/5 transition-colors group cursor-pointer relative min-h-[250px]">
                        <input type="file" name="data_file" accept=".json,.csv" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="if(this.files[0]) this.form.submit();">
                        <i id="uploadIconFile"
                            class="fa-solid fa-file-code text-5xl text-slate-500 group-hover:text-amber-400 mb-4 transition-colors"></i>
                        <p class="text-base font-bold text-slate-300 group-hover:text-amber-400">Drop file JSON/CSV</p>
                    </div>
                </form>
            </div>
        </div>
        <script>
            async function loadDatabases() {
                const btn = document.getElementById('btnLoadDb'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
                const formData = new FormData(); formData.append('host', document.getElementById('host').value); formData.append('user', document.getElementById('user').value); formData.append('pass', document.getElementById('pass').value);
                try {
                    const res = await fetch('?action=get_dbs', { method: 'POST', body: formData }); const data = await res.json();
                    if (data.success) {
                        const select = document.getElementById('dbSelect'); select.innerHTML = ''; let foundVoid = false;
                        data.dbs.forEach(db => {
                            const opt = document.createElement('option'); opt.value = db; opt.innerText = db;
                            if (db === 'db_voidbraver') { opt.selected = true; foundVoid = true; } select.appendChild(opt);
                        });
                        if (!foundVoid && data.dbs.length > 0) select.selectedIndex = 0;
                        select.classList.remove('hidden'); document.getElementById('dbInput').classList.add('hidden');
                        document.getElementById('dbInput').name = ''; select.name = 'dbname';
                    } else alert("Koneksi gagal: " + data.message);
                } catch (e) { alert("Error: " + e.message); }
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Load List';
            }
        </script>
    </body>

    </html>
    <?php
    exit;
endif;

// ==========================================
// DASHBOARD UTAMA
// ==========================================
$sessionConn = $_SESSION['db_connection'] ?? null;
$driver = $sessionConn['driver'] ?? 'mysql';

$dbManager = new DatabaseManager($pdo, $dbname, $driver);

$tables = $dbManager->getTables();
$relations = $dbManager->getRelations();
$currentTable = $_GET['table'] ?? (!empty($tables) ? $tables[0] : null);
$mode = $_GET['mode'] ?? 'join';

$nativeCols = $currentTable ? getNativeColumns($pdo, $currentTable, $driver) : [];

$primaryKeyColPHP = $currentTable ? $dbManager->getPrimaryKey($currentTable) : 'id';
if (!$primaryKeyColPHP && !empty($nativeCols)) {
    $primaryKeyColPHP = in_array('id', $nativeCols) ? 'id' : $nativeCols[0];
}

if (isset($_GET['action']) && $_GET['action'] === 'crud') {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    try {
        $op = $_POST['operation'];
        $table = $_POST['table'];
        $pkCol = $dbManager->getPrimaryKey($table);
        if (!$pkCol) {
            $tCols = getNativeColumns($pdo, $table, $driver);
            $pkCol = !empty($tCols) ? (in_array('id', $tCols) ? 'id' : $tCols[0]) : 'id';
        }

        if ($op === 'delete') {
            $dbManager->deleteRecord($table, $pkCol, $_POST['pkVal']);
            echo json_encode(['success' => true]);
        } elseif ($op === 'save') {
            $data = json_decode($_POST['data'], true);
            $pkVal = $_POST['pkVal'] ?? null;
            if ($pkVal) {
                $dbManager->updateRecord($table, $pkCol, $pkVal, $data);
            } else {
                $dbManager->insertRecord($table, $data);
            }
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'trace') {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    try {
        $traceMode = $_GET['trace_mode'] ?? 'raw';
        $res = $dbManager->traceValueAdvanced($_GET['table'], $_GET['col'], $_GET['val'], $traceMode);
        echo json_encode(['error' => false, 'data' => $res]);
    } catch (Exception $e) {
        echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- API EKSPOR FULL DB SQLite ---
if (isset($_GET['action']) && ($_GET['action'] === 'sqlite' || $_GET['action'] === 'sqlite_all')) {
    $file = ($_GET['action'] === 'sqlite_all') ? $dbManager->exportFullDatabaseToSQLite() : $dbManager->exportToSQLite($_GET['table']);
    if ($file && file_exists($file)) {
        $filename = ($_GET['action'] === 'sqlite_all') ? "{$dbname}_FULL_Backup.sqlite" : "{$_GET['table']}_backup.sqlite";
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        unlink($file);
        exit;
    }
    die("Gagal mengekspor data.");
}

// --- API EKSPOR JSON ALL ---
if (isset($_GET['action']) && $_GET['action'] === 'export_json_all') {
    if (ob_get_length())
        ob_clean();
    $out = [];
    foreach ($tables as $t) {
        $out[] = [
            'type' => 'table',
            'name' => $t,
            'data' => $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $dbname . '_FULL_Backup.json"');
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

// --- API EKSPOR CURRENT TABLE CSV ---
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (ob_get_length())
        ob_clean();
    $t = $_GET['table'];
    $data = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $t . '.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($out, array_keys($data[0]));
        foreach ($data as $row)
            fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$db_error = null;
$query_status = "ok";
$rows = [];
$query = "";

if ($currentTable) {
    if ($mode === 'join') {
        $joinedQuery = $dbManager->buildJoinQuery($currentTable, $relations);
        if ($joinedQuery === null) {
            $query = $dbManager->buildRawQuery($currentTable);
            $mode = 'raw';
            $query_status = "no_relation";
        } else {
            $query = $joinedQuery;
        }
    } else {
        $query = $dbManager->buildRawQuery($currentTable);
    }

    if ($query !== "") {
        $result = $dbManager->fetchData($query);
        if (isset($result['error']))
            $db_error = $result['error'];
        else
            $rows = $result;
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($dbname) ?> - DB Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

        :root {
            --theme-color:
                <?= $themeColor ?>
            ;
            --theme-color-hover:
                <?= $themeHover ?>
            ;
            --theme-bg-subtle:
                <?= $themeSubtle ?>
            ;
            --bg-base: #0f172a;
            --bg-panel: #1e293b;
            --border-color: #334155;
            --app-font-scale: 16px;
            --cell-py: 0.75rem;
            --cell-px: 1.25rem;
        }

        html {
            font-size: var(--app-font-scale);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-base);
            color: #e2e8f0;
        }

        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .text-theme {
            color: var(--theme-color) !important;
        }

        .bg-theme {
            background-color: var(--theme-color) !important;
            color: white !important;
        }

        .border-theme {
            border-color: var(--theme-color) !important;
        }

        .hover-text-theme:hover {
            color: var(--theme-color) !important;
        }

        .hover-bg-theme:hover {
            background-color: var(--theme-color-hover) !important;
            color: white !important;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--theme-color);
        }

        th {
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .cell-pad {
            padding: var(--cell-py) var(--cell-px) !important;
        }

        @keyframes spin-anim {
            0% {
                transform: rotate(-180deg) scale(0.5);
                opacity: 0;
            }

            100% {
                transform: rotate(0deg) scale(1);
                opacity: 1;
            }
        }

        .spin-anim {
            animation: spin-anim 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        td {
            transition: background-color 0.15s ease, color 0.15s ease;
            position: relative;
        }

        @keyframes popInTooltip {
            from {
                opacity: 0;
                transform: translate(-50%, 5px);
            }

            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        body.mode-inspect td.properties-trigger:hover::after,
        body.mode-inspect td.properties-trigger:hover::before,
        body.mode-editor td.properties-trigger:hover::after,
        body.mode-editor td.properties-trigger:hover::before {
            position: absolute;
            left: 50%;
            z-index: 50;
            pointer-events: none;
            animation: popInTooltip 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        body.mode-inspect td.properties-trigger:hover::after,
        body.mode-editor td.properties-trigger:hover::after {
            bottom: calc(100% + 4px);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 900;
            font-family: 'Font Awesome 6 Free', sans-serif;
            white-space: nowrap;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        body.mode-inspect td.properties-trigger:hover::before,
        body.mode-editor td.properties-trigger:hover::before {
            bottom: 100%;
            border-width: 4px;
            border-style: solid;
        }

        /* --- STATE 1: READONLY (DEFAULT) --- */
        /* Text bisa diblok. Cursor biasa. Gak ada tooltip */
        body:not(.mode-inspect):not(.mode-editor) td.properties-trigger {
            cursor: text;
        }

        /* --- STATE 2: MODE INSPECT --- */
        body.mode-inspect td.properties-trigger:hover {
            cursor: pointer;
            color: var(--theme-color);
            background: var(--theme-bg-subtle);
        }

        body.mode-inspect td.properties-trigger:hover::after {
            content: "\f002  Inspect";
            background: var(--theme-color);
        }

        body.mode-inspect td.properties-trigger:hover::before {
            border-color: var(--theme-color) transparent transparent transparent;
        }

        /* --- STATE 3: MODE EDITOR --- */
        body.mode-editor td.properties-trigger:hover {
            cursor: cell;
            color: #10b981;
            background: rgba(16, 185, 129, 0.15);
        }

        body.mode-editor td.properties-trigger:hover::after {
            content: "\f044  Edit";
            background: #10b981;
            color: white;
        }

        body.mode-editor td.properties-trigger:hover::before {
            border-color: #10b981 transparent transparent transparent;
        }

        .glass-panel {
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
        }

        .sidebar-link.active {
            background: var(--theme-bg-subtle);
            border-right: 3px solid var(--theme-color);
            color: var(--theme-color);
            font-weight: 600;
        }

        .sidebar-collapsed {
            width: 5rem !important;
        }

        .sidebar-collapsed .hide-on-collapse {
            display: none !important;
        }

        .sidebar-collapsed .show-on-collapse {
            display: flex !important;
        }

        .sidebar-collapsed .center-on-collapse {
            justify-content: center !important;
            padding-left: 0;
            padding-right: 0;
        }

        .properties-scroll::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }

        .properties-scroll::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }

        .properties-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--theme-color);
        }

        ::selection {
            background-color: var(--theme-color);
            color: #ffffff;
        }

        ::-moz-selection {
            background-color: var(--theme-color);
            color: #ffffff;
        }
    </style>
    <style id="dynamicPinStyle"></style>

    <script>
        const savedFontSize = localStorage.getItem('voidDbFontSize') || '16';
        const savedDensity = JSON.parse(localStorage.getItem('voidDbDensity')) || { py: '0.75rem', px: '1.25rem' };
        document.documentElement.style.setProperty('--app-font-scale', savedFontSize + 'px');
        document.documentElement.style.setProperty('--cell-py', savedDensity.py);
        document.documentElement.style.setProperty('--cell-px', savedDensity.px);

        // Preload Class untuk mencegah blink
        let preMode = localStorage.getItem('voidDbAppMode') || 'readonly';
        if (localStorage.getItem('voidDbEditorMode') === 'on') { preMode = 'editor'; localStorage.removeItem('voidDbEditorMode'); localStorage.setItem('voidDbAppMode', 'editor'); }
        if (preMode === 'inspect') document.documentElement.classList.add('mode-inspect-preload');
        if (preMode === 'editor') document.documentElement.classList.add('mode-editor-preload');
    </script>
</head>

<body class="flex h-screen overflow-hidden antialiased selection:bg-theme selection:text-white">

    <div id="settingsModal"
        class="fixed inset-0 z-[120] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div
            class="bg-[#1e293b] border border-slate-700 w-full max-w-lg rounded-xl shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
            <div class="p-5 border-b border-slate-700/50 flex justify-between items-center bg-slate-800/50 shrink-0">
                <h3 class="text-lg font-bold text-white flex items-center gap-2"><i
                        class="fa-solid fa-gear text-slate-400"></i> Konfigurasi Sistem</h3>
                <button onclick="toggleSettings()" class="text-slate-400 hover:text-red-400"><i
                        class="fa-solid fa-xmark fa-lg"></i></button>
            </div>
            <div class="p-6 space-y-8 overflow-y-auto">
                <?php if ($driver === 'mysql' && $sessionConn): ?>
                    <div>
                        <label class="text-xs text-slate-400 block mb-2 uppercase font-bold tracking-wider"><i
                                class="fa-solid fa-user mr-1"></i> Informasi Profil</label>
                        <div
                            class="bg-slate-900/50 border border-slate-700 rounded-lg p-3 text-sm text-slate-300 font-mono shadow-inner">
                            <p><span class="text-slate-500 mr-2">Host:</span> <?= htmlspecialchars($sessionConn['host']) ?>
                            </p>
                            <p><span class="text-slate-500 mr-2">User:</span> <?= htmlspecialchars($sessionConn['user']) ?>
                            </p>
                            <p><span class="text-slate-500 mr-2">Tipe:</span> MySQL DB</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i
                            class="fa-solid fa-palette mr-1"></i> Aksen Warna Tema</label>
                    <div class="flex flex-wrap gap-3 items-center">
                        <button onclick="setTheme('#3b82f6')"
                            class="w-8 h-8 rounded-full bg-blue-500 shadow-md"></button>
                        <button onclick="setTheme('#10b981')"
                            class="w-8 h-8 rounded-full bg-emerald-500 shadow-md"></button>
                        <button onclick="setTheme('#8b5cf6')"
                            class="w-8 h-8 rounded-full bg-violet-500 shadow-md"></button>
                        <button onclick="setTheme('#f43f5e')"
                            class="w-8 h-8 rounded-full bg-rose-500 shadow-md"></button>
                        <button onclick="setTheme('#f59e0b')"
                            class="w-8 h-8 rounded-full bg-amber-500 shadow-md"></button>
                        <button onclick="setTheme('#06b6d4')"
                            class="w-8 h-8 rounded-full bg-cyan-500 shadow-md"></button>
                        <button onclick="setTheme('#b5009a')"
                            class="w-8 h-8 rounded-full bg-pink-500 shadow-md"></button>
                        <div class="h-6 w-px bg-slate-700 mx-1"></div>
                        <div class="flex items-center gap-2 bg-slate-800 p-1.5 rounded-lg border border-slate-700">
                            <label for="colorPicker" class="text-xs font-bold text-slate-400 cursor-pointer pl-1"><i
                                    class="fa-solid fa-eye-dropper"></i> Custom</label>
                            <input type="color" id="colorPicker" value="<?= $themeColor ?>"
                                class="w-6 h-6 rounded cursor-pointer border-0 p-0 bg-transparent"
                                onchange="setTheme(this.value)">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i
                            class="fa-solid fa-universal-access mr-1"></i> Aksesibilitas & Tampilan</label>
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs text-slate-500 mb-2">Ukuran Huruf (Global Zoom)</p>
                            <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner w-full">
                                <button onclick="setA11y('font', 14)" id="font14"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Kecil</button>
                                <button onclick="setA11y('font', 16)" id="font16"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Normal</button>
                                <button onclick="setA11y('font', 18)" id="font18"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Besar</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 mb-2">Kerapatan Tabel (Padding)</p>
                            <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner w-full">
                                <button onclick="setA11y('density', 'tight')" id="denseTight"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Rapat</button>
                                <button onclick="setA11y('density', 'normal')" id="denseNormal"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Normal</button>
                                <button onclick="setA11y('density', 'loose')" id="denseLoose"
                                    class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Longgar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="border-t border-gray-200 dark:border-gray-800 py-2 text-sm pb-4">
                <div
                    class="max-w-6xl mx-auto px-4 flex items-center justify-center gap-2 text-gray-500 dark:text-gray-400">
                    <span>Dibuat oleh</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">Keidjaru Axiro</span>
                    <span class="opacity-40">•</span>
                    <a href="https://github.com/KeiAxiro" target="_blank"
                        class="flex items-center gap-1 hover:text-indigo-500 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 fill-current">
                            <path
                                d="M12 .5C5.73.5.99 5.24.99 11.5c0 4.86 3.15 8.98 7.52 10.44.55.1.75-.24.75-.53 0-.26-.01-.96-.02-1.88-3.06.66-3.71-1.48-3.71-1.48-.5-1.27-1.23-1.6-1.23-1.6-1-.69.08-.68.08-.68 1.11.08 1.7 1.14 1.7 1.14.99 1.7 2.6 1.21 3.24.93.1-.72.39-1.21.71-1.49-2.44-.28-5-1.22-5-5.43 0-1.2.43-2.18 1.14-2.95-.11-.28-.5-1.43.11-2.98 0 0 .94-.3 3.08 1.13A10.7 10.7 0 0112 6.8c.95 0 1.91.13 2.81.39 2.14-1.44 3.08-1.13 3.08-1.13.61 1.55.22 2.7.11 2.98.71.77 1.14 1.75 1.14 2.95 0 4.22-2.57 5.14-5.02 5.42.4.34.76 1.01.76 2.04 0 1.47-.01 2.66-.01 3.02 0 .29.2.64.76.53 4.37-1.46 7.52-5.58 7.52-10.44C23.01 5.24 18.27.5 12 .5z" />
                        </svg>
                        <span>KeiAxiro</span>
                    </a>
                </div>
            </footer>
        </div>
    </div>

    <div id="disconnectModal"
        class="fixed inset-0 z-[200] hidden items-center justify-center bg-slate-900/90 backdrop-blur-sm p-4">
        <div class="bg-[#1e293b] border border-slate-600 rounded-2xl max-w-sm w-full p-6 shadow-2xl flex flex-col items-center text-center transform transition-transform duration-300 scale-95"
            id="disconnectModalContent">
            <div
                class="w-16 h-16 bg-red-500/20 text-red-500 rounded-full flex items-center justify-center text-3xl mb-4 border border-red-500/30">
                <i class="fa-solid fa-power-off"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Putuskan Koneksi?</h3>
            <p class="text-slate-400 text-sm mb-6">Sangat disarankan untuk mengunduh backup database sebelum Anda
                keluar.</p>
            <div class="flex flex-col w-full gap-3">
                <a href="?action=sqlite_all" onclick="closeDisconnectModal()"
                    class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-sm flex items-center justify-center gap-2 transition-colors shadow-lg shadow-emerald-500/20 border border-emerald-500/50">
                    <i class="fa-solid fa-download"></i> Unduh Full DB & Tetap Disini
                </a>
                <a href="?action=disconnect"
                    class="w-full py-3 bg-red-500/10 hover:bg-red-500/20 border border-red-500/50 text-red-400 rounded-lg font-bold text-sm flex items-center justify-center gap-2 transition-colors">
                    <i class="fa-solid fa-right-from-bracket"></i> Keluar Tanpa Backup
                </a>
                <button onclick="closeDisconnectModal()"
                    class="w-full py-3 bg-slate-800 hover:bg-slate-700 border border-slate-600 text-white rounded-lg font-bold text-sm transition-colors mt-2">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <aside id="appSidebar"
        class="w-64 bg-[#1e293b] border-r border-slate-700 flex flex-col z-40 shrink-0 shadow-xl transition-all duration-300 absolute md:relative inset-y-0 left-0 -translate-x-full md:translate-x-0">
        <div class="p-5 border-b border-slate-700 flex justify-between items-center bg-[#1e293b] h-[72px]">
            <h1
                class="text-xl font-black text-white tracking-wide flex items-center gap-2 overflow-hidden hide-on-collapse whitespace-nowrap">
                <i class="fa-solid fa-database text-theme"></i> VOID<span class="text-slate-500 font-light">DB</span>
            </h1>
            <div class="hidden justify-center w-full icon-on-collapse text-theme show-on-collapse"><i
                    class="fa-solid fa-database fa-lg"></i></div>
            <button onclick="toggleSidebarDesktop()"
                class="text-slate-400 hover:text-theme transition-colors hidden md:block hide-on-collapse"
                title="Collapse Sidebar"><i class="fa-solid fa-angles-left"></i></button>
            <button onclick="toggleSidebarDesktop()"
                class="text-slate-400 hover:text-theme transition-colors hidden show-on-collapse absolute right-0 left-0 mx-auto w-max mt-16 z-50 bg-slate-800 rounded-full p-2 border border-slate-600 shadow-lg"><i
                    class="fa-solid fa-angles-right"></i></button>
            <button onclick="toggleSidebarMobile()" class="md:hidden text-slate-400 hover:text-red-400 text-2xl"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="border-b border-slate-700 bg-slate-800/30">
            <div class="px-5 py-4 hide-on-collapse">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Active Connection</p>
                    <button onclick="toggleSettings()" class="text-slate-400 hover:text-theme transition-colors"
                        title="Settings"><i class="fa-solid fa-gear"></i></button>
                </div>
                <div class="flex items-center justify-between">
                    <div
                        class="flex items-center gap-2 text-sm text-slate-300 font-mono truncate bg-slate-900/50 p-1.5 rounded border border-slate-700 w-full">
                        <i
                            class="fa-solid <?= $driver === 'sqlite' ? 'fa-file-code text-emerald-400' : 'fa-server text-blue-400' ?> ml-1"></i>
                        <span class="truncate ml-1"
                            title="<?= htmlspecialchars($dbname) ?>"><?= htmlspecialchars($dbname) ?></span>
                        <button onclick="showDisconnectModal()"
                            class="text-red-400 hover:text-red-300 ml-auto mr-1 p-1 bg-red-500/10 hover:bg-red-500/20 rounded transition-colors border border-transparent hover:border-red-500/50"
                            title="Disconnect"><i class="fa-solid fa-power-off"></i></button>
                    </div>
                </div>
            </div>
            <div class="hidden flex-col items-center gap-4 py-6 show-on-collapse">
                <button onclick="toggleSettings()"
                    class="text-slate-400 hover:text-theme transition-colors p-2 rounded-lg hover:bg-slate-800"
                    title="Settings"><i class="fa-solid fa-gear fa-xl"></i></button>
                <button onclick="showDisconnectModal()"
                    class="text-red-400 hover:text-red-300 transition-colors p-2 rounded-lg hover:bg-red-900/30 mt-2"
                    title="Disconnect"><i class="fa-solid fa-power-off fa-xl"></i></button>
            </div>
        </div>
        <div class="px-5 mt-auto pt-6 border-t border-slate-700 space-y-3 mb-4">
            <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest mb-2 flex items-center gap-1.5">
                <i class="fa-solid fa-download"></i> Ekspor & Backup
            </p>
            <a href="?action=sqlite_all"
                class="flex items-center gap-2 text-[11px] font-bold text-slate-400 hover:text-emerald-400 transition-colors py-1.5 px-2 rounded hover:bg-emerald-500/10 border border-transparent hover:border-emerald-500/30"><i
                    class="fa-solid fa-database w-4 text-center"></i> Full DB (.sqlite)</a>
            <a href="?action=export_json_all"
                class="flex items-center gap-2 text-[11px] font-bold text-slate-400 hover:text-amber-400 transition-colors py-1.5 px-2 rounded hover:bg-amber-500/10 border border-transparent hover:border-amber-500/30"><i
                    class="fa-solid fa-file-code w-4 text-center"></i> Full DB (.json)</a>
            <a href="?action=export_csv&table=<?= urlencode($currentTable) ?>"
                class="flex items-center gap-2 text-[11px] font-bold text-slate-400 hover:text-blue-400 transition-colors py-1.5 px-2 rounded hover:bg-blue-500/10 border border-transparent hover:border-blue-500/30"><i
                    class="fa-solid fa-file-csv w-4 text-center"></i> Current Table (.csv)</a>
        </div>
        <div class="flex-1 overflow-y-auto py-4 space-y-1 hide-on-collapse flex flex-col">
            <div
                class="px-5 mb-3 text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center justify-between">
                <span>Daftar Tabel</span><span
                    class="bg-slate-800 px-1.5 py-0.5 rounded text-slate-400"><?= count($tables) ?></span>
            </div>
            <?php foreach ($tables as $t): ?>
                <a href="?table=<?= urlencode($t) ?>&mode=<?= $mode ?>"
                    class="sidebar-link block px-5 py-2.5 text-sm transition-all duration-200 <?= $t === $currentTable ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-table-cells text-slate-500 mr-2 opacity-70 w-4 text-center"></i>
                    <span class="truncate"><?= htmlspecialchars($t) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden backdrop-blur-sm"
        onclick="toggleSidebarMobile()"></div>

    <main class="flex-1 flex flex-col min-w-0 bg-[#0f172a] relative h-screen">
        <header
            class="bg-[#1e293b] border-b border-slate-700 shadow-sm z-[70] relative shrink-0 h-[72px] md:h-auto flex flex-col justify-center">
            <div id="mobileHeaderNormal"
                class="px-4 py-2 md:p-5 flex flex-1 flex-row justify-between items-center gap-4 transition-all">
                <div class="flex items-center gap-3 min-w-0 w-full md:w-auto">
                    <button onclick="toggleSidebarMobile()"
                        class="md:hidden text-slate-400 hover:text-white p-2 -ml-2 rounded-lg hover:bg-slate-800 transition-colors"><i
                            class="fa-solid fa-bars fa-lg"></i></button>
                    <div
                        class="w-10 h-10 rounded-lg bg-theme/10 border border-theme/20 items-center justify-center text-theme shrink-0 hidden md:flex">
                        <i class="fa-solid fa-table-list fa-lg"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg md:text-xl font-bold text-white font-mono leading-none truncate w-full">
                            <?= htmlspecialchars($currentTable) ?>
                        </h2>
                        <p class="text-[10px] md:text-xs text-slate-400 mt-1" id="rowCount"><i
                                class="fa-solid fa-chart-simple mr-1"></i> <?= count($rows) ?> baris dimuat</p>
                    </div>
                </div>

                <button onclick="toggleMobileSearch(true)"
                    class="md:hidden text-slate-400 hover:text-theme p-2.5 rounded-lg bg-slate-800 border border-slate-700 shrink-0 shadow-sm transition-colors">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>

                <div class="hidden md:flex items-center gap-2 md:gap-3 shrink-0 ml-auto">
                    <button id="btnAddRecordDesk" onclick="openPropertiesModal(null, null, null)"
                        class="hidden px-3 py-1.5 md:py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs md:text-sm font-medium transition-all shadow-sm items-center gap-2 shrink-0 border border-emerald-500/50 mr-2">
                        <i class="fa-solid fa-plus"></i> <span class="hidden xl:inline">Data Baru</span>
                    </button>

                    <button id="btnToggleModeDesk" onclick="cycleAppMode()"
                        class="px-4 py-1.5 md:py-2 bg-slate-800 hover:bg-slate-700 border border-slate-600 rounded-lg text-xs md:text-sm font-bold transition-colors flex items-center gap-2 shadow-sm shrink-0 mr-2 text-slate-400">
                        <i class="fa-solid fa-eye-slash text-lg" id="iconModeDesk"></i> <span class="hidden lg:inline"
                            id="textModeDesk">Read Only</span>
                    </button>

                    <div class="relative group shrink-0">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 group-focus-within:text-theme transition-colors text-xs md:text-sm"></i>
                        <input type="text" id="globalSearchDesk" placeholder="Cari data..."
                            class="pl-8 pr-3 py-1.5 md:py-2 bg-slate-900 border border-slate-700 focus:border-theme rounded-lg text-xs md:text-sm outline-none text-slate-200 transition-all w-32 md:w-48 xl:focus:w-72 lg:focus:w-56 shadow-inner">
                    </div>

                    <div class="relative group shrink-0">
                        <button
                            class="px-3 md:px-4 py-1.5 md:py-2 bg-slate-800 border border-slate-600 hover:border-slate-500 rounded-lg text-xs md:text-sm transition-colors flex items-center gap-2 text-slate-200 shadow-sm">
                            <i class="fa-solid fa-eye-slash text-slate-400"></i> <span
                                class="hidden xl:inline">Kolom</span>
                        </button>
                        <div class="absolute right-0 mt-2 w-56 md:w-64 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all p-2 flex flex-col gap-1 max-h-60 overflow-y-auto z-[100]"
                            id="columnToggles"></div>
                    </div>

                    <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner shrink-0">
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=raw"
                            class="px-3 md:px-4 py-1 md:py-1.5 rounded-md text-xs md:text-sm transition-colors <?= $mode === 'raw' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i
                                class="fa-solid fa-cube md:mr-1"></i> <span class="hidden md:inline">Raw</span></a>
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=join"
                            class="px-3 md:px-4 py-1 md:py-1.5 rounded-md text-xs md:text-sm transition-colors <?= $mode === 'join' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i
                                class="fa-solid fa-link md:mr-1"></i> <span class="hidden md:inline">Joined</span></a>
                    </div>
                </div>
            </div>

            <div id="mobileHeaderSearch" class="hidden absolute inset-0 bg-[#1e293b] z-20 px-4 items-center w-full">
                <div class="relative w-full flex items-center">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 text-theme text-sm z-10"></i>
                    <input type="text" id="globalSearchMobile" placeholder="Ketik untuk mencari..."
                        class="w-full pl-10 pr-12 py-3 bg-slate-900 border border-theme rounded-xl text-sm outline-none text-white shadow-lg transition-colors">
                    <button onclick="toggleMobileSearch(false)"
                        class="absolute right-3 text-slate-400 hover:text-red-400 p-2 rounded-lg bg-slate-800 transition-colors"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
        </header>

        <?php if (isset($db_error)): ?>
            <div class="px-4 md:px-6 pt-4 z-10 relative">
                <div
                    class="bg-red-500/10 border border-red-500/50 text-red-400 p-4 rounded-xl flex items-start gap-3 shadow-lg">
                    <i class="fa-solid fa-triangle-exclamation text-xl mt-0.5"></i>
                    <div>
                        <h4 class="font-bold text-sm">Error Pembacaan Data</h4>
                        <p class="text-xs mt-1 font-mono opacity-80"><?= htmlspecialchars($db_error) ?></p>
                    </div>
                </div>
            </div>
        <?php elseif ($query_status === "no_relation"): ?>
            <div class="px-4 md:px-6 pt-4 z-10 relative">
                <div
                    class="bg-amber-500/10 border border-amber-500/50 text-amber-500 px-4 py-3 rounded-xl text-xs flex items-center gap-3 shadow-lg">
                    <i class="fa-solid fa-circle-info fa-lg"></i>
                    <span>Tabel ini tidak memiliki <b>Foreign Key</b> (kolom relasi) ke tabel lain. Mode otomatis
                        dikembalikan ke <b>Raw</b>.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="pt-2 pl-2 pr-2 md:pl-4 md:pr-4 md:pt-4 flex-1 min-h-0 relative flex flex-col z-10">
            <div class="overflow-auto rounded-xl border border-slate-700 bg-[#1e293b] shadow-lg relative flex-1">
                <table class="w-full text-left border-collapse whitespace-nowrap" id="dataTable">
                    <?php if (!empty($rows)): ?>
                        <thead class="bg-slate-800 text-slate-300 text-xs uppercase tracking-wider shadow-sm">
                            <tr id="tableHeaderRow">
                                <?php foreach (array_keys($rows[0]) as $index => $col): ?>
                                    <th class="border-b border-slate-700 font-semibold bg-slate-800 cursor-pointer hover-text-theme transition-colors group/th cell-pad"
                                        onclick="sortTable(<?= $index ?>, this)" data-dir="">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2 flex-1 min-w-0">
                                                <span class="truncate"><?= htmlspecialchars($col) ?></span>
                                                <i
                                                    class="fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity text-slate-400 sort-icon-main"></i>
                                            </div>
                                            <button
                                                class="opacity-0 group-hover/th:opacity-100 w-6 h-6 flex items-center justify-center hover:bg-slate-700 rounded transition-all text-slate-400 hover-text-theme ml-2 shrink-0"
                                                onclick="event.stopPropagation(); pinColumn(<?= $index ?>)" title="Pin Kolom">
                                                <i class="fa-solid fa-thumbtack"></i>
                                            </button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="text-xs md:text-sm divide-y divide-slate-700/50 font-mono text-slate-300">
                            <?php foreach ($rows as $row):
                                $safeRowData = base64_encode(rawurlencode(json_encode($row, JSON_UNESCAPED_UNICODE)));
                                ?>
                                <tr class="hover:bg-slate-800/80 transition-colors data-row" data-row="<?= $safeRowData ?>">
                                    <?php
                                    $colKeys = array_keys($row);
                                    $colIndex = 0;
                                    foreach ($row as $cell):
                                        $colName = htmlspecialchars($colKeys[$colIndex], ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <td class="cell-pad properties-trigger" onclick="handleCellClick(this, '<?= $colName ?>')">
                                            <?= ($cell === null) ? '<span class="text-slate-600 italic">NULL</span>' : htmlspecialchars($cell) ?>
                                        </td>
                                        <?php $colIndex++; endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <tbody>
                            <tr>
                                <td class="py-20 text-center text-slate-500"><i
                                        class="fa-solid fa-folder-open text-4xl mb-3 opacity-50"></i>
                                    <p>Tidak ada data di tabel ini.</p>
                                </td>
                            </tr>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="md:hidden fixed bottom-6 right-6 z-[60]">
            <button id="fabBtn"
                class="w-14 h-14 bg-theme text-white rounded-full shadow-[0_4px_15px_rgba(0,0,0,0.5)] flex items-center justify-center text-xl hover:scale-105 transition-transform"
                onclick="toggleMobileTools(true)">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </button>
        </div>

        <div id="mobileToolsOverlay" class="fixed inset-0 bg-black/60 z-[65] hidden backdrop-blur-sm"
            onclick="toggleMobileTools(false)"></div>

        <div id="mobileToolsSheet"
            class="fixed inset-x-0 bottom-0 z-[70] translate-y-full transition-transform duration-300 ease-out bg-[#1e293b] rounded-t-3xl shadow-[0_-10px_40px_rgba(0,0,0,0.5)] border-t border-slate-700 flex flex-col max-h-[85vh]">
            <div
                class="p-5 border-b border-slate-700 flex justify-between items-center bg-slate-800/50 rounded-t-3xl shrink-0">
                <h3 class="font-bold text-white text-lg"><i class="fa-solid fa-sliders text-theme mr-2"></i> Alat Tabel
                </h3>
                <button onclick="toggleMobileTools(false)"
                    class="text-slate-400 p-2 hover:text-red-400 bg-slate-800 rounded-lg"><i
                        class="fa-solid fa-xmark fa-lg"></i></button>
            </div>
            <div class="p-5 overflow-y-auto space-y-6">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i
                            class="fa-solid fa-power-off mr-1"></i> App Mode</label>
                    <button id="btnToggleModeMob" onclick="cycleAppMode()"
                        class="w-full py-3 bg-slate-800 border border-slate-600 rounded-lg text-sm font-bold transition-colors flex items-center justify-center gap-2 shadow-sm text-slate-400">
                        <i class="fa-solid fa-eye-slash text-xl" id="iconModeMob"></i> <span id="textModeMob">Mode: Read
                            Only</span>
                    </button>
                </div>
                <div id="containerAddRecordMob" class="hidden">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i
                            class="fa-solid fa-plus mr-1"></i> Tambah Data Baru</label>
                    <button onclick="openPropertiesModal(null, null, null); toggleMobileTools(false);"
                        class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-sm border border-emerald-500/50">
                        <i class="fa-solid fa-plus"></i> Form Tambah Data
                    </button>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i
                            class="fa-solid fa-eye mr-1"></i> Mode Tampilan Data</label>
                    <div class="flex bg-slate-900 rounded-lg p-1.5 border border-slate-700 shadow-inner w-full">
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=raw"
                            onclick="localStorage.setItem('voidDbFabState', 'open');"
                            class="flex-1 text-center py-2 rounded-md text-sm font-bold transition-colors <?= $mode === 'raw' ? 'bg-theme text-white shadow-sm' : 'text-slate-500' ?>"><i
                                class="fa-solid fa-cube mr-1"></i> Raw</a>
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=join"
                            onclick="localStorage.setItem('voidDbFabState', 'open');"
                            class="flex-1 text-center py-2 rounded-md text-sm font-bold transition-colors <?= $mode === 'join' ? 'bg-theme text-white shadow-sm' : 'text-slate-500' ?>"><i
                                class="fa-solid fa-link mr-1"></i> Joined</a>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i
                            class="fa-solid fa-eye-slash mr-1"></i> Sembunyikan Kolom</label>
                    <div id="mobileColumnToggles"
                        class="grid grid-cols-2 gap-2 bg-slate-900/50 p-3 rounded-xl border border-slate-700"></div>
                </div>
            </div>
        </div>
    </main>

    <div id="propertiesModal"
        class="fixed inset-0 z-[130] hidden items-center justify-center bg-slate-900/90 backdrop-blur-md transition-opacity p-2 md:p-0">
        <div class="bg-[#1e293b] border border-slate-600 w-full h-full md:max-w-[95vw] lg:max-w-7xl md:h-[90vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden transform scale-95 transition-transform duration-200"
            id="propertiesModalContent">

            <div
                class="p-4 md:p-5 border-b border-slate-700/50 flex flex-col md:flex-row md:justify-between items-start md:items-center bg-slate-800/90 shrink-0 gap-3 md:gap-0">
                <div class="flex items-center gap-3 w-full md:w-auto justify-between md:justify-start">
                    <div>
                        <h3 class="text-lg md:text-xl font-bold text-white flex items-center gap-2"><i
                                class="fa-solid fa-sliders text-theme"></i> Row Properties</h3>
                        <p class="text-xs text-slate-400 font-mono mt-0.5 ml-7 flex items-center flex-wrap">
                            Target: <span class="text-emerald-400 ml-1"
                                id="propTargetTable"><?= htmlspecialchars($currentTable) ?></span>
                            <span id="propTargetCell" class="hidden text-slate-500 ml-2 tracking-wide"></span>
                        </p>
                    </div>
                    <button onclick="closePropertiesModal()"
                        class="md:hidden text-slate-400 hover:text-red-400 transition-colors bg-slate-900 p-2 rounded-lg border border-slate-700"><i
                            class="fa-solid fa-xmark fa-lg"></i></button>
                </div>

                <div class="flex md:hidden w-full bg-slate-900 p-1 rounded-lg border border-slate-700">
                    <button onclick="switchPropertiesTab('editor')" id="tabBtnEditor"
                        class="flex-1 py-1.5 text-xs font-bold rounded-md bg-theme text-white shadow-sm"><i
                            class="fa-solid fa-pen-to-square"></i> Editor</button>
                    <button onclick="switchPropertiesTab('inspector')" id="tabBtnInspector"
                        class="flex-1 py-1.5 text-xs font-bold rounded-md text-slate-400 hover:text-white transition-colors"><i
                            class="fa-solid fa-network-wired"></i> Inspector</button>
                </div>

                <div class="hidden md:flex gap-3">
                    <button onclick="closePropertiesModal()"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 bg-slate-900 border border-slate-700 hover:bg-red-500/20 hover:text-red-400 hover:border-red-500/50 transition-colors"><i
                            class="fa-solid fa-xmark fa-lg"></i></button>
                </div>
            </div>

            <div class="flex-1 overflow-hidden flex flex-col md:flex-row relative">
                <div id="paneEditor"
                    class="w-full md:w-2/5 lg:w-1/3 flex flex-col border-r border-slate-700 bg-[#1e293b] absolute md:relative inset-0 z-20 md:z-auto transition-transform duration-300"
                    style="transform: translateX(0);">
                    <div
                        class="p-4 bg-slate-800/50 border-b border-slate-700 shrink-0 flex justify-between items-center">
                        <h4 class="font-bold text-sm text-slate-300 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-database text-emerald-400"></i> Data Record
                        </h4>

                        <button onclick="cycleAppMode()" id="editorStatusBadge"
                            title="Klik untuk mengganti mode (Read/Inspect/Edit)"
                            class="text-[10px] bg-slate-800 text-slate-400 px-2.5 py-1 rounded border border-slate-600 font-bold tracking-widest uppercase transition-colors flex items-center gap-1.5 shadow-sm hover:bg-slate-700">
                            <i class='fa-solid fa-eye-slash text-sm'></i> Read Only
                        </button>
                    </div>

                    <div class="flex-1 p-5 overflow-y-auto properties-scroll" id="editorFormContainer"></div>

                    <div class="p-4 border-t border-slate-700 bg-slate-900/50 flex flex-wrap gap-2 shrink-0 hidden"
                        id="editorActionFooter">
                        <button id="editorBtnDelete"
                            class="px-3 py-2 bg-red-500/10 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white rounded-lg text-xs font-bold transition-colors hidden items-center gap-2 flex-1 md:flex-none justify-center"><i
                                class="fa-solid fa-trash"></i> Hapus</button>
                        <button onclick="saveEditorRecord()"
                            class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-500 transition-all shadow-lg shadow-emerald-600/20 flex items-center justify-center gap-2 flex-1"><i
                                class="fa-solid fa-save"></i> Simpan Perubahan</button>
                    </div>
                </div>

                <div id="paneInspector"
                    class="w-full md:w-3/5 lg:w-2/3 flex flex-col bg-[#0f172a] absolute md:relative inset-0 z-10 md:z-auto transition-transform duration-300"
                    style="transform: translateX(100%);">
                    <div class="p-3 md:p-4 bg-slate-800/80 border-b border-slate-700 flex flex-col gap-3 shrink-0">
                        <div class="flex items-center gap-3">
                            <button id="btnBackTracer" onclick="goBackTracer()"
                                class="hidden px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded-md transition-colors text-xs font-medium shrink-0"><i
                                    class="fa-solid fa-arrow-left"></i> Back</button>
                            <div id="tracerBreadcrumbs"
                                class="flex items-center gap-2 text-[10px] md:text-xs text-slate-400 font-mono overflow-x-auto whitespace-nowrap properties-scroll pb-1 md:pb-0">
                            </div>
                        </div>
                        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3">
                            <div class="flex flex-wrap gap-2 text-xs" id="inspectorFilters">
                                <button
                                    class="px-2.5 py-1 rounded-md bg-theme text-white font-medium filter-btn shadow-sm"
                                    data-filter="all">Semua</button>
                                <button
                                    class="px-2.5 py-1 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-1.5 items-center"
                                    data-filter="source_row"><i class="fa-solid fa-crosshairs"></i> Utama <span
                                        id="badge-source"
                                        class="bg-slate-800 text-[10px] px-1 rounded">0</span></button>
                                <button
                                    class="px-2.5 py-1 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-1.5 items-center"
                                    data-filter="row_relations"><i class="fa-solid fa-link"></i> Relasi <span
                                        id="badge-row" class="bg-slate-800 text-[10px] px-1 rounded">0</span></button>
                                <button
                                    class="px-2.5 py-1 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-1.5 items-center"
                                    data-filter="global_matches"><i class="fa-solid fa-globe"></i> Global <span
                                        id="badge-global"
                                        class="bg-slate-800 text-[10px] px-1 rounded">0</span></button>
                            </div>

                            <div
                                class="flex bg-slate-900 rounded-md p-1 border border-slate-700 shrink-0 w-max ml-auto xl:ml-0">
                                <button onclick="changeTracerMode('raw')" id="tracerModeRaw"
                                    class="px-3 py-1 rounded text-[10px] md:text-xs font-bold bg-theme text-white shadow-sm"><i
                                        class="fa-solid fa-table"></i> Raw</button>
                                <button onclick="changeTracerMode('join')" id="tracerModeJoin"
                                    class="px-3 py-1 rounded text-[10px] md:text-xs font-bold text-slate-400 hover:text-white transition-colors"><i
                                        class="fa-solid fa-object-group"></i> Join</button>
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto properties-scroll p-4 md:p-6" id="tracerBody">
                        <div class="flex flex-col items-center justify-center h-full text-slate-500 opacity-50"><i
                                class="fa-solid fa-network-wired text-6xl mb-4"></i>
                            <p class="text-sm mt-3">Klik sebuah sel untuk melacak relasi datanya.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- 0. STATE 3-MODE (GLOBAL) ---
        // readonly -> inspect -> editor -> readonly
        let currentAppMode = localStorage.getItem('voidDbAppMode') || 'readonly';

        // Backward compatibility migration
        if (localStorage.getItem('voidDbEditorMode') === 'on') {
            currentAppMode = 'editor';
            localStorage.removeItem('voidDbEditorMode');
            localStorage.setItem('voidDbAppMode', 'editor');
        }

        let currentEditRowData = null;
        const activeTable = "<?= htmlspecialchars($currentTable ?? '') ?>";
        const primaryKeyCol = "<?= htmlspecialchars($primaryKeyColPHP) ?>";
        const nativeCols = <?= json_encode($nativeCols ?? []) ?>;

        document.addEventListener('DOMContentLoaded', () => {
            applyAppModeState();

            if (localStorage.getItem('voidDbFabState') === 'open') {
                const sheet = document.getElementById('mobileToolsSheet');
                const overlay = document.getElementById('mobileToolsOverlay');
                sheet.classList.remove('transition-transform', 'duration-300');
                sheet.classList.remove('translate-y-full'); overlay.classList.remove('hidden');
                setTimeout(() => { sheet.classList.add('transition-transform', 'duration-300'); }, 50);
            }

            if (window.innerWidth >= 768) {
                document.getElementById('paneEditor').style.transform = 'none';
                document.getElementById('paneInspector').style.transform = 'none';
            }
        });

        function cycleAppMode() {
            if (currentAppMode === 'readonly') currentAppMode = 'inspect';
            else if (currentAppMode === 'inspect') currentAppMode = 'editor';
            else currentAppMode = 'readonly';

            localStorage.setItem('voidDbAppMode', currentAppMode);
            applyAppModeState();
        }

        function applyAppModeState() {
            // UI Elements
            const btnDesk = document.getElementById('btnToggleModeDesk');
            const iconDesk = document.getElementById('iconModeDesk');
            const textDesk = document.getElementById('textModeDesk');

            const btnMob = document.getElementById('btnToggleModeMob');
            const iconMob = document.getElementById('iconModeMob');
            const textMob = document.getElementById('textModeMob');

            const btnAddDesk = document.getElementById('btnAddRecordDesk');
            const containerAddMob = document.getElementById('containerAddRecordMob');

            const badgeStatus = document.getElementById('editorStatusBadge');

            // Setup Desktop Button
            if (btnDesk) {
                btnDesk.className = "px-4 py-1.5 md:py-2 border rounded-lg text-xs md:text-sm font-bold transition-colors flex items-center gap-2 shadow-sm shrink-0 mr-2";
                if (currentAppMode === 'readonly') {
                    btnDesk.classList.add('bg-slate-800', 'border-slate-600', 'text-slate-400', 'hover:bg-slate-700');
                    iconDesk.className = "fa-solid fa-eye-slash text-lg"; textDesk.innerText = "Read Only";
                    btnAddDesk.classList.add('hidden'); btnAddDesk.classList.remove('flex');
                } else if (currentAppMode === 'inspect') {
                    btnDesk.classList.add('bg-theme', 'border-theme', 'text-white', 'hover-bg-theme');
                    iconDesk.className = "fa-solid fa-magnifying-glass text-lg"; textDesk.innerText = "Inspect Mode";
                    btnAddDesk.classList.add('hidden'); btnAddDesk.classList.remove('flex');
                } else if (currentAppMode === 'editor') {
                    btnDesk.classList.add('bg-emerald-600/20', 'border-emerald-500/50', 'text-emerald-400', 'hover:bg-emerald-600/30');
                    iconDesk.className = "fa-solid fa-pen-to-square text-lg"; textDesk.innerText = "Editor Mode";
                    btnAddDesk.classList.remove('hidden'); btnAddDesk.classList.add('flex');
                }
            }

            // Setup Mobile Sheet Button
            if (btnMob) {
                btnMob.className = "w-full py-3 border rounded-lg text-sm font-bold transition-colors flex items-center justify-center gap-2 shadow-sm";
                if (currentAppMode === 'readonly') {
                    btnMob.classList.add('bg-slate-800', 'border-slate-600', 'text-slate-400');
                    iconMob.className = "fa-solid fa-eye-slash text-xl"; textMob.innerText = "Mode: Read Only";
                    containerAddMob.classList.add('hidden');
                } else if (currentAppMode === 'inspect') {
                    btnMob.classList.add('bg-theme', 'border-theme', 'text-white');
                    iconMob.className = "fa-solid fa-magnifying-glass text-xl"; textMob.innerText = "Mode: Inspect";
                    containerAddMob.classList.add('hidden');
                } else if (currentAppMode === 'editor') {
                    btnMob.classList.add('bg-emerald-600/20', 'border-emerald-500/50', 'text-emerald-400');
                    iconMob.className = "fa-solid fa-pen-to-square text-xl"; textMob.innerText = "Mode: Editor";
                    containerAddMob.classList.remove('hidden');
                }
            }

            // Setup Modal Badge (Inside Properties)
            if (badgeStatus) {
                badgeStatus.className = "text-[10px] px-2.5 py-1 rounded border font-bold tracking-widest uppercase transition-colors flex items-center gap-1.5 shadow-sm";
                if (currentAppMode === 'readonly') {
                    badgeStatus.classList.add('bg-slate-800', 'border-slate-600', 'text-slate-400', 'hover:bg-slate-700');
                    badgeStatus.innerHTML = "<i class='fa-solid fa-eye-slash text-sm'></i> Read Only";
                } else if (currentAppMode === 'inspect') {
                    badgeStatus.classList.add('bg-blue-500/20', 'border-blue-500/50', 'text-blue-400', 'hover:bg-blue-500/30');
                    badgeStatus.innerHTML = "<i class='fa-solid fa-magnifying-glass text-sm'></i> Inspecting";
                } else if (currentAppMode === 'editor') {
                    badgeStatus.classList.add('bg-emerald-500/20', 'border-emerald-500/50', 'text-emerald-400', 'hover:bg-emerald-500/30');
                    badgeStatus.innerHTML = "<i class='fa-solid fa-pen text-sm'></i> Editing";
                }
            }

            // Body Classes for Tooltips
            document.body.classList.remove('mode-inspect', 'mode-editor');
            document.documentElement.classList.remove('mode-inspect-preload', 'mode-editor-preload');
            if (currentAppMode === 'inspect') document.body.classList.add('mode-inspect');
            if (currentAppMode === 'editor') document.body.classList.add('mode-editor');

            // Refresh Properties Pane if open
            const propModal = document.getElementById('propertiesModal');
            if (propModal && !propModal.classList.contains('hidden')) {
                updateEditorPane(currentEditTable, currentEditRowData);
            }
        }

        // --- 1. HANDLING ROW CLICKS & PROPERTIES MODAL ---
        let currentEditPkVal = null;
        let currentEditTable = activeTable;

        const escapeHTML = (str) => {
            if (str === null || str === undefined) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        };
        const unEscapeHTML = (str) => {
            return String(str).replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#039;/g, "'");
        };

        function handleCellClick(td, clickedColName) {
            // MENGIZINKAN TEXT SELECTION AMAN TANPA MEMBUKA MODAL!
            if (currentAppMode === 'readonly') return;

            const tr = td.closest('tr');
            if (!tr.dataset.row) return;

            // PENGGUNAAN BASE64 -> 100% AMAN DARI QUOTE HELL
            const rowData = JSON.parse(decodeURIComponent(atob(tr.dataset.row)));
            openPropertiesModal(clickedColName, rowData[clickedColName], rowData, activeTable);
        }

        function updateEditorPane(tableName, rowData) {
            currentEditTable = tableName;
            currentEditRowData = rowData;
            document.getElementById('propTargetTable').innerText = tableName;

            const container = document.getElementById('editorFormContainer');
            const btnDelete = document.getElementById('editorBtnDelete');
            const footerAction = document.getElementById('editorActionFooter');

            let html = '<form id="editorForm" class="space-y-4 flex flex-col">';

            let guessedPkCol = primaryKeyCol;
            if (tableName !== activeTable && rowData) guessedPkCol = rowData['id'] !== undefined ? 'id' : Object.keys(rowData)[0];

            const buildInput = (col, val, isPk, isNative) => {
                const safeVal = escapeHTML(val);
                const isLongText = String(val).length > 50;
                let locked = !isNative || (isPk && rowData !== null);
                let badgeHtml = !isNative ? '<span class="text-blue-400 text-[9px] px-1.5 py-0.5 rounded border border-blue-400/30 bg-blue-400/10 ml-2 uppercase tracking-widest">[Joined]</span>' : (isPk && rowData !== null ? '<span class="text-red-400 text-[9px] px-1.5 py-0.5 rounded border border-red-400/30 bg-red-400/10 ml-2 uppercase tracking-widest">[PK]</span>' : '');

                const finalReadonly = locked || currentAppMode !== 'editor';
                const baseClass = finalReadonly ? 'w-full bg-slate-900/50 border border-slate-700/50 rounded-lg px-3 py-2 text-sm text-slate-500 cursor-not-allowed font-mono shadow-inner outline-none' : 'w-full bg-[#0f172a] border border-slate-600 rounded-lg px-3 py-2 text-sm text-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-all font-mono placeholder-slate-600';

                let inputHtml = (isLongText && !finalReadonly) ? `<textarea name="${col}" rows="3" class="${baseClass}">${safeVal}</textarea>` : `<input type="text" ${isNative ? `name="${col}"` : ''} value="${safeVal}" ${finalReadonly ? 'readonly tabindex="-1"' : ''} class="${baseClass}" placeholder="${isNative ? 'Ketik nilai...' : ''}">`;

                return `
                <div class="group flex flex-col">
                    <label class="text-xs font-bold text-slate-400 mb-1 tracking-wider group-focus-within:text-emerald-400 transition-colors flex items-center">
                        ${col} ${badgeHtml}
                        ${!isNative ? '<i class="fa-solid fa-lock ml-auto text-slate-600 text-[10px]"></i>' : ''}
                    </label>
                    ${inputHtml}
                </div>`;
            };

            if (rowData) {
                currentEditPkVal = rowData[guessedPkCol];
                Object.keys(rowData).forEach(col => {
                    const isNative = (tableName === activeTable) ? nativeCols.includes(col) : true;
                    html += buildInput(col, rowData[col], col === guessedPkCol, isNative);
                });

                if (currentAppMode === 'editor') {
                    btnDelete.classList.remove('hidden'); btnDelete.classList.add('flex'); btnDelete.onclick = () => deleteEditorRecord(currentEditPkVal);
                    footerAction.classList.remove('hidden');
                } else {
                    footerAction.classList.add('hidden');
                }
            } else {
                currentEditPkVal = null;
                nativeCols.forEach(col => { html += buildInput(col, '', col === guessedPkCol, true); });
                btnDelete.classList.add('hidden'); btnDelete.classList.remove('flex');
                if (currentAppMode === 'editor') footerAction.classList.remove('hidden'); else footerAction.classList.add('hidden');
            }

            container.innerHTML = html + '</form>';
        }

        function openPropertiesModal(triggerColName = null, triggerCellValue = null, rowData = null, tableName = activeTable) {
            const modal = document.getElementById('propertiesModal');
            const content = document.getElementById('propertiesModalContent');
            const targetCellSpan = document.getElementById('propTargetCell');

            if (triggerColName && triggerCellValue !== undefined) {
                let displayVal = (triggerCellValue === null || triggerCellValue === '') ? 'NULL' : triggerCellValue;
                targetCellSpan.innerHTML = `(<span class="text-blue-400">${triggerColName}</span>=<span class="text-amber-400">${escapeHTML(displayVal)}</span>)`;
                targetCellSpan.classList.remove('hidden');
            } else {
                targetCellSpan.classList.add('hidden');
                targetCellSpan.innerHTML = '';
            }

            if (window.innerWidth < 768) switchPropertiesTab(rowData === null || currentAppMode === 'editor' ? 'editor' : 'inspector');

            updateEditorPane(tableName, rowData);

            if (rowData !== null && triggerColName && triggerCellValue !== undefined) {
                startTrace(tableName, triggerColName, triggerCellValue);
            } else if (!rowData) {
                showTableOverviewInInspector();
            } else {
                document.getElementById('tracerBody').innerHTML = `<div class="flex flex-col items-center justify-center h-full text-slate-500 opacity-50"><i class="fa-solid fa-network-wired text-6xl mb-4"></i><p class="text-sm">Klik sebuah sel untuk melacak relasi datanya.</p></div>`;
                document.getElementById('tracerBreadcrumbs').innerHTML = ''; document.getElementById('btnBackTracer').style.display = 'none';
            }

            modal.classList.remove('hidden'); modal.classList.add('flex');
            setTimeout(() => content.classList.remove('scale-95'), 10);
        }

        function showTableOverviewInInspector() {
            const globalRowsData = <?= json_encode(array_slice($rows, 0, 50), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('tracerBreadcrumbs').innerHTML = `<span class="text-theme font-bold"><i class="fa-solid fa-table"></i> Data Tabel Aktif (${activeTable})</span>`;
            document.getElementById('btnBackTracer').style.display = 'none';

            if (!globalRowsData || globalRowsData.length === 0) {
                document.getElementById('tracerBody').innerHTML = `<div class="flex flex-col items-center justify-center h-full text-slate-500 opacity-50"><i class="fa-solid fa-folder-open text-6xl mb-4"></i><p class="text-sm">Tabel ini masih kosong.</p></div>`;
                return;
            }

            const keys = Object.keys(globalRowsData[0]);
            let tableHtml = `
            <div class="bg-[#1e293b] border border-slate-700 rounded-xl overflow-hidden shadow-md">
                <div class="overflow-x-auto w-full properties-scroll">
                    <table class="w-full text-left text-xs min-w-max">
                        <thead class="bg-[#0f172a]/50 text-slate-400"><tr>${keys.map((k) => `<th class="px-3 py-2 font-semibold border-b border-slate-700">${k}</th>`).join('')}</tr></thead>
                        <tbody class="divide-y divide-slate-700/50 font-mono">
                            ${globalRowsData.map(r => `
                                <tr class="hover:bg-slate-700/50 transition-colors">
                                    ${Object.entries(r).map(([k, v]) => `<td class="px-3 py-2 text-slate-300 whitespace-nowrap">${v !== null ? escapeHTML(v) : '<span class="text-slate-600 italic">NULL</span>'}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>`;
            document.getElementById('tracerBody').innerHTML = tableHtml;
        }

        function closePropertiesModal() {
            const modal = document.getElementById('propertiesModal');
            const content = document.getElementById('propertiesModalContent');
            content.classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 150);
        }

        function switchPropertiesTab(tab) {
            const paneEd = document.getElementById('paneEditor'), paneIn = document.getElementById('paneInspector');
            const btnEd = document.getElementById('tabBtnEditor'), btnIn = document.getElementById('tabBtnInspector');
            const actBtn = "flex-1 py-1.5 text-xs font-bold rounded-md bg-theme text-white shadow-sm", inactBtn = "flex-1 py-1.5 text-xs font-bold rounded-md text-slate-400 hover:text-white transition-colors";

            if (tab === 'editor') {
                paneEd.style.transform = 'translateX(0)'; paneIn.style.transform = 'translateX(100%)';
                if (btnEd) btnEd.className = actBtn; if (btnIn) btnIn.className = inactBtn;
            } else {
                paneEd.style.transform = 'translateX(-100%)'; paneIn.style.transform = 'translateX(0)';
                if (btnIn) btnIn.className = actBtn; if (btnEd) btnEd.className = inactBtn;
            }
        }

        async function saveEditorRecord() {
            const form = document.getElementById('editorForm');
            const formData = new FormData(form);
            const dataObj = {};
            formData.forEach((value, key) => { if (value.trim() !== "") dataObj[key] = value; });

            try {
                const reqData = new FormData();
                reqData.append('operation', 'save'); reqData.append('table', currentEditTable);
                if (currentEditPkVal) reqData.append('pkVal', currentEditPkVal);
                reqData.append('data', JSON.stringify(dataObj));

                const res = await fetch('?action=crud', { method: 'POST', body: reqData });
                const result = await res.json();
                if (result.success) location.reload(); else alert('Gagal menyimpan: ' + result.message);
            } catch (err) { alert('Error sistem: ' + err.message); }
        }

        async function deleteEditorRecord(pkVal) {
            if (!confirm('🚨 PERINGATAN!\n\nApakah kamu yakin ingin menghapus data ini secara permanen? Data tidak dapat dikembalikan!')) return;
            try {
                const reqData = new FormData();
                reqData.append('operation', 'delete'); reqData.append('table', currentEditTable); reqData.append('pkVal', pkVal);
                const res = await fetch('?action=crud', { method: 'POST', body: reqData });
                const result = await res.json();
                if (result.success) location.reload(); else alert('Gagal menghapus: ' + result.message);
            } catch (err) { alert('Error sistem: ' + err.message); }
        }

        // --- 2. DEEP INSPECTOR LOGIC ---
        let traceHistory = []; let currentTraceData = null; let currentTracerMode = 'raw';

        function changeTracerMode(mode) {
            currentTracerMode = mode;
            const actCls = 'px-3 py-1 rounded text-[10px] md:text-xs font-bold bg-theme text-white shadow-sm';
            const inactCls = 'px-3 py-1 rounded text-[10px] md:text-xs font-bold text-slate-400 hover:text-white transition-colors';

            const rawBtn = document.getElementById('tracerModeRaw'), joinBtn = document.getElementById('tracerModeJoin');
            if (rawBtn) rawBtn.className = mode === 'raw' ? actCls : inactCls;
            if (joinBtn) joinBtn.className = mode === 'join' ? actCls : inactCls;

            if (traceHistory.length > 0) {
                const last = traceHistory[traceHistory.length - 1]; executeTrace(last.table, last.col, last.val, true);
            }
        }

        function inspectAndEdit(tableName, colName, b64RowStr) {
            const rowObj = JSON.parse(decodeURIComponent(atob(b64RowStr)));
            const value = rowObj[colName];
            if (window.innerWidth < 768) switchPropertiesTab('inspector');
            pushTrace(tableName, colName, value);
            updateEditorPane(tableName, rowObj);
        }

        function startTrace(tableName, colName, value) {
            traceHistory = []; executeTrace(tableName, colName, value);
        }

        function pushTrace(tableName, colName, value) {
            if (window.innerWidth < 768) switchPropertiesTab('inspector');
            executeTrace(tableName, colName, value);
        }

        function goBackTracer() {
            if (traceHistory.length > 1) {
                traceHistory.pop(); const prev = traceHistory[traceHistory.length - 1]; executeTrace(prev.table, prev.col, prev.val, true);
            }
        }

        async function executeTrace(tableName, colName, value, skipPush = false) {
            const tBody = document.getElementById('tracerBody');

            let sendValue = value === null ? 'NULL' : value;

            if (!skipPush) traceHistory.push({ table: tableName, col: colName, val: sendValue });
            document.getElementById('btnBackTracer').style.display = traceHistory.length > 1 ? 'block' : 'none';

            document.getElementById('tracerBreadcrumbs').innerHTML = traceHistory.map((step, i) => {
                let dispVal = (step.val === null || step.val === '') ? 'NULL' : step.val;
                return `<span class="${i === traceHistory.length - 1 ? 'text-theme font-bold' : 'text-slate-500'}">${step.table} <span class="hidden md:inline">(${step.col}=${escapeHTML(dispVal)})</span></span>`;
            }).join(' <span class="text-slate-600 text-[10px]"><i class="fa-solid fa-chevron-right"></i></span> ');

            tBody.innerHTML = `<div class="flex flex-col gap-4 justify-center items-center h-full"><i class="fa-solid fa-circle-notch fa-spin text-4xl text-theme"></i><p class="text-slate-400 text-xs md:text-sm">Menarik relasi data...</p></div>`;

            try {
                const response = await fetch(`?action=trace&table=${encodeURIComponent(tableName)}&col=${encodeURIComponent(colName)}&val=${encodeURIComponent(sendValue)}&trace_mode=${currentTracerMode}`);
                const resData = await response.json();
                if (resData.error) throw new Error(resData.message);

                currentTraceData = resData.data;
                updateFilterBadges();

                document.querySelectorAll('.filter-btn').forEach(b => {
                    b.classList.remove('bg-theme', 'text-white', 'shadow-sm');
                    b.classList.add('text-slate-400', 'hover:bg-slate-700');
                });
                document.querySelector('.filter-btn[data-filter="all"]').classList.remove('text-slate-400', 'hover:bg-slate-700');
                document.querySelector('.filter-btn[data-filter="all"]').classList.add('bg-theme', 'text-white', 'shadow-sm');

                renderTracerData('all');
            } catch (err) {
                tBody.innerHTML = `<div class="text-red-400 bg-red-900/20 border border-red-900 p-6 rounded-xl flex items-center gap-3"><i class="fa-solid fa-triangle-exclamation fa-2x"></i> Error: ${err.message}</div>`;
            }
        }

        function updateFilterBadges() {
            const d = currentTraceData;
            document.getElementById('badge-source').innerText = d.source_row.length;
            document.getElementById('badge-row').innerText = d.row_relations.length + d.value_relations.length;
            document.getElementById('badge-global').innerText = d.global_matches.length;
        }

        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.filter-btn').forEach(b => {
                    b.classList.remove('bg-theme', 'text-white', 'shadow-sm'); b.classList.add('text-slate-400', 'hover:bg-slate-700');
                });
                const target = e.currentTarget;
                target.classList.remove('text-slate-400', 'hover:bg-slate-700'); target.classList.add('bg-theme', 'text-white', 'shadow-sm');
                renderTracerData(target.dataset.filter);
            });
        });

        function renderTracerData(filter) {
            if (!currentTraceData) return;
            const d = currentTraceData; let html = '';
            const tBody = document.getElementById('tracerBody');

            const renderBlock = (groups, sectionTitle, iconHtml, colorCls) => {
                if (groups.length === 0) return '';
                let blockHtml = `<h4 class="text-white font-bold mb-3 mt-4 flex items-center gap-2 px-1 text-sm border-b border-slate-700 pb-2"><span class="text-${colorCls}">${iconHtml}</span> ${sectionTitle}</h4><div class="space-y-4 mb-6">`;
                groups.forEach(group => {
                    if (group.data.length === 0) return;
                    let keys = Object.keys(group.data[0]);
                    blockHtml += `
                        <div class="bg-[#1e293b] border border-slate-700 rounded-xl overflow-hidden shadow-md">
                            <div class="bg-slate-800 px-3 py-2 flex flex-wrap justify-between items-center border-b border-slate-700 gap-2">
                                <div class="flex items-center min-w-0">
                                    <span class="text-[9px] text-${colorCls} font-bold uppercase tracking-widest bg-slate-900 px-2 py-0.5 rounded border border-slate-700 shrink-0">${group.type}</span>
                                    <span class="text-xs text-white font-bold tracking-wide flex items-center gap-2 truncate ml-2">
                                        <i class="fa-solid fa-table text-slate-500 hidden sm:inline"></i> 
                                        <span class="truncate">${group.table}</span>
                                        <span class="bg-slate-700 text-slate-300 text-[10px] px-2 py-0.5 rounded-full ml-1 shrink-0">${group.data.length} baris</span>
                                    </span>
                                </div>
                                <a href="?table=${encodeURIComponent(group.table)}" class="text-[10px] bg-theme/20 text-theme hover-bg-theme px-2.5 py-1 rounded transition-colors flex items-center gap-1.5 shrink-0 font-bold border border-theme/30 ml-auto">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> <span class="hidden sm:inline">Buka Tabel</span>
                                </a>
                            </div>
                            <div class="overflow-x-auto w-full properties-scroll">
                                <table class="w-full text-left text-xs min-w-max">
                                    <thead class="bg-[#0f172a]/50 text-slate-400">
                                        <tr>${keys.map((k, idx) => `
                                            <th class="px-3 py-2 font-semibold border-b border-slate-700 whitespace-nowrap cursor-pointer hover-text-theme transition-colors group/th cell-pad" onclick="sortInspectorTable(this, ${idx})">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span>${k}</span><i class="fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity sort-icon-insp text-slate-500"></i>
                                                </div>
                                            </th>`).join('')}
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700/50 font-mono">
                                        ${group.data.map(row => {
                        const b64RowStr = btoa(encodeURIComponent(JSON.stringify(row)));
                        return `
                                            <tr class="hover:bg-slate-700/50 transition-colors">
                                                ${Object.entries(row).map(([k, v]) => `
                                                    <td class="px-3 py-2 text-slate-300 cursor-pointer hover-text-theme whitespace-nowrap properties-trigger" onclick="inspectAndEdit('${group.table}', '${k}', '${b64RowStr}')">
                                                        ${v !== null ? escapeHTML(v) : '<span class="text-slate-600 italic">NULL</span>'}
                                                    </td>
                                                `).join('')}
                                            </tr>`;
                    }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>`;
                });
                return blockHtml + `</div>`;
            };

            if (filter === 'all' || filter === 'source_row') html += renderBlock(d.source_row, "Data Baris Utama", '<i class="fa-solid fa-crosshairs"></i>', 'blue-400');
            if (filter === 'all' || filter === 'row_relations') {
                html += renderBlock(d.row_relations, "Relasi Terhubung (ID Entitas)", '<i class="fa-solid fa-link"></i>', 'emerald-400');
                html += renderBlock(d.value_relations, "Relasi Berdasarkan Value Sel", '<i class="fa-solid fa-puzzle-piece"></i>', 'emerald-400');
            }
            if (filter === 'all' || filter === 'global_matches') html += renderBlock(d.global_matches, "Ditemukan Juga Di", '<i class="fa-solid fa-globe"></i>', 'purple-400');

            if (html === '') html = `<div class="text-center p-8 bg-slate-800/50 rounded-xl border border-slate-700"><i class="fa-solid fa-box-open text-3xl text-slate-600 mb-3"></i><p class="text-slate-400 text-sm">Tidak ada relasi data.</p></div>`;
            tBody.innerHTML = html;
        }

        // --- 3. SCRIPT GLOBAL BAWAAN ---
        function toggleMobileSearch(show) {
            const normal = document.getElementById('mobileHeaderNormal'), search = document.getElementById('mobileHeaderSearch'), input = document.getElementById('globalSearchMobile');
            if (show) {
                normal.classList.add('hidden'); normal.classList.remove('flex'); search.classList.remove('hidden'); search.classList.add('flex'); input.focus();
            } else { search.classList.add('hidden'); search.classList.remove('flex'); normal.classList.remove('hidden'); normal.classList.add('flex'); input.value = ''; executeSearch(''); }
        }

        const executeSearch = function (val) {
            let filter = val.toLowerCase(), count = 0;
            document.querySelectorAll("#dataTable tbody tr.data-row").forEach(row => {
                if (row.innerText.toLowerCase().includes(filter)) { row.style.display = ""; count++; } else row.style.display = "none";
            });
            document.getElementById('rowCount').innerHTML = `<i class="fa-solid fa-chart-simple mr-1"></i> ${count} baris dimuat`;
        };
        if (document.getElementById('globalSearchDesk')) document.getElementById('globalSearchDesk').addEventListener('keyup', (e) => executeSearch(e.target.value));
        if (document.getElementById('globalSearchMobile')) document.getElementById('globalSearchMobile').addEventListener('keyup', (e) => executeSearch(e.target.value));

        function toggleMobileTools(forceState = null) {
            const sheet = document.getElementById('mobileToolsSheet'), overlay = document.getElementById('mobileToolsOverlay');
            let willOpen = forceState !== null ? forceState : sheet.classList.contains('translate-y-full');
            if (willOpen) {
                sheet.classList.remove('translate-y-full'); overlay.classList.remove('hidden');
                localStorage.setItem('voidDbFabState', 'open');
            } else {
                sheet.classList.add('translate-y-full'); overlay.classList.add('hidden');
                localStorage.setItem('voidDbFabState', 'closed');
            }
        }

        // --- RESTORASI SIDEBAR, SETTINGS, HIDE, TEMA, A11Y ---
        const sidebar = document.getElementById('appSidebar');
        const overlaySidebar = document.getElementById('sidebarOverlay');
        let isDesktopCollapsed = false;

        function toggleSidebarMobile() {
            sidebar.classList.toggle('-translate-x-full');
            overlaySidebar.classList.toggle('hidden');
        }

        function toggleSidebarDesktop() {
            isDesktopCollapsed = !isDesktopCollapsed;
            if (isDesktopCollapsed) {
                sidebar.classList.add('sidebar-collapsed');
                document.querySelectorAll('.show-on-collapse').forEach(el => {
                    el.classList.remove('hidden', 'md:hidden'); el.classList.add('flex');
                });
            } else {
                sidebar.classList.remove('sidebar-collapsed');
                document.querySelectorAll('.show-on-collapse').forEach(el => {
                    el.classList.add('hidden'); el.classList.remove('flex');
                });
            }
        }

        function toggleSettings() {
            const m = document.getElementById('settingsModal');
            m.classList.toggle('hidden'); m.classList.toggle('flex');
            if (!m.classList.contains('hidden')) {
                const currentColor = getComputedStyle(document.documentElement).getPropertyValue('--theme-color').trim();
                document.getElementById('colorPicker').value = currentColor || '#3b82f6';
            }
        }

        function showDisconnectModal() {
            const m = document.getElementById('disconnectModal');
            const content = document.getElementById('disconnectModalContent');
            m.classList.remove('hidden'); m.classList.add('flex');
            setTimeout(() => { content.classList.remove('scale-95'); }, 10);
        }

        function closeDisconnectModal() {
            const m = document.getElementById('disconnectModal');
            const content = document.getElementById('disconnectModalContent');
            content.classList.add('scale-95');
            setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 200);
        }

        function setTheme(hexColor) {
            const darkenHex = (hex, amount) => {
                let color = hex.replace('#', '');
                if (color.length === 3) color = color.split('').map(c => c + c).join('');
                let num = parseInt(color, 16);
                let r = Math.max(0, (num >> 16) - amount); let g = Math.max(0, ((num >> 8) & 0x00FF) - amount); let b = Math.max(0, (num & 0x0000FF) - amount);
                return '#' + (g | (b << 8) | (r << 16)).toString(16).padStart(6, '0');
            };

            const hexHover = darkenHex(hexColor, 20);
            const root = document.documentElement;
            root.style.setProperty('--theme-color', hexColor); root.style.setProperty('--theme-color-hover', hexHover);

            let c; if (/^#([A-Fa-f0-9]{3}){1,2}$/.test(hexColor)) {
                c = hexColor.substring(1).split('');
                if (c.length == 3) c = [c[0], c[0], c[1], c[1], c[2], c[2]]; c = '0x' + c.join('');
                root.style.setProperty('--theme-bg-subtle', 'rgba(' + [(c >> 16) & 255, (c >> 8) & 255, c & 255].join(',') + ',0.15)');
            }

            document.getElementById('colorPicker').value = hexColor;
            const formData = new FormData(); formData.append('action', 'save_theme'); formData.append('color', hexColor); formData.append('hover', hexHover);
            fetch('?action=save_theme', { method: 'POST', body: formData });
        }

        function updateA11yUI() {
            const fs = localStorage.getItem('voidDbFontSize') || '16';
            const dens = JSON.parse(localStorage.getItem('voidDbDensity')) || { py: '0.75rem', px: '1.25rem' };

            ['font14', 'font16', 'font18'].forEach(id => {
                if (document.getElementById(id)) document.getElementById(id).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors text-slate-400 hover:text-white';
            });
            if (document.getElementById('font' + fs)) document.getElementById('font' + fs).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors bg-theme text-white shadow-sm';

            ['denseTight', 'denseNormal', 'denseLoose'].forEach(id => {
                if (document.getElementById(id)) document.getElementById(id).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors text-slate-400 hover:text-white';
            });

            let densId = 'denseNormal';
            if (dens.py === '0.35rem') densId = 'denseTight';
            else if (dens.py === '1.25rem') densId = 'denseLoose';
            if (document.getElementById(densId)) document.getElementById(densId).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors bg-theme text-white shadow-sm';
        }

        function setA11y(type, val) {
            if (type === 'font') {
                document.documentElement.style.setProperty('--app-font-scale', val + 'px');
                localStorage.setItem('voidDbFontSize', val);
            } else if (type === 'density') {
                let py = '0.75rem', px = '1.25rem';
                if (val === 'tight') { py = '0.35rem'; px = '0.75rem'; }
                else if (val === 'loose') { py = '1.25rem'; px = '1.5rem'; }
                document.documentElement.style.setProperty('--cell-py', py);
                document.documentElement.style.setProperty('--cell-px', px);
                localStorage.setItem('voidDbDensity', JSON.stringify({ py, px }));
            }
            updateA11yUI();
        }
        if (document.getElementById('font16')) updateA11yUI();

        const colContainer = document.getElementById('columnToggles'), mobileColContainer = document.getElementById('mobileColumnToggles');
        if (colContainer && mobileColContainer) {
            document.querySelectorAll('#tableHeaderRow th').forEach((th, index) => {
                let colNameText = th.querySelector('.flex-1')?.innerText.trim() || `Kolom ${index}`;
                const labelDesk = document.createElement('label'); labelDesk.className = "flex items-center gap-3 px-3 py-2 hover:bg-slate-700 rounded-md cursor-pointer text-sm text-slate-300 transition-colors border border-transparent hover:border-slate-600";
                labelDesk.innerHTML = `<input type="checkbox" checked class="col-toggle-${index} rounded bg-slate-900 border-slate-600 text-theme focus:ring-theme w-4 h-4 cursor-pointer" onchange="toggleColumn(${index}, this.checked)"> <span class="truncate flex-1">${colNameText}</span>`;
                colContainer.appendChild(labelDesk);
                const labelMob = document.createElement('label'); labelMob.className = "flex items-center gap-2 p-2 bg-slate-800 rounded-lg cursor-pointer text-xs text-slate-300 border border-slate-700 active:border-theme";
                labelMob.innerHTML = `<input type="checkbox" checked class="col-toggle-${index} rounded bg-slate-900 border-slate-600 text-theme focus:ring-theme w-4 h-4 cursor-pointer" onchange="toggleColumn(${index}, this.checked)"> <span class="truncate flex-1">${colNameText}</span>`;
                mobileColContainer.appendChild(labelMob);
            });
        }

        function toggleColumn(index, isVisible) {
            document.querySelectorAll(`.col-toggle-${index}`).forEach(cb => cb.checked = isVisible);
            const n = index + 1;
            document.querySelectorAll(`#dataTable th:nth-child(${n}), #dataTable td:nth-child(${n})`).forEach(cell => { cell.style.display = isVisible ? '' : 'none'; });
            if (!isVisible && currentPinnedIndex === index) pinColumn(index);
        }

        let currentPinnedIndex = -1;
        function pinColumn(index) {
            const nth = index + 1; const styleTag = document.getElementById('dynamicPinStyle');
            if (currentPinnedIndex === index) {
                styleTag.innerHTML = ''; currentPinnedIndex = -1;
            } else {
                styleTag.innerHTML = `#dataTable th:nth-child(${nth}), #dataTable td:nth-child(${nth}) { position: sticky !important; left: 0 !important; right: 0 !important; z-index: 30 !important; background-color: var(--bg-panel) !important; box-shadow: -4px 0 15px rgba(0,0,0,0.5), 4px 0 15px rgba(0,0,0,0.5) !important; border-left: 2px solid var(--theme-color) !important; border-right: 2px solid var(--theme-color) !important; } #dataTable td:nth-child(${nth}) { background-color: #1e293b !important; } #dataTable th:nth-child(${nth}) { z-index: 40 !important; background-color: #1e293b !important; }`;
                currentPinnedIndex = index;
            }
        }

        function sortTable(n, headerElem) {
            const tbody = document.querySelector("#dataTable tbody"); const rows = Array.from(tbody.querySelectorAll("tr.data-row"));
            const table = headerElem.closest('table');
            table.querySelectorAll('th').forEach(th => {
                if (th !== headerElem) {
                    th.dataset.dir = ''; const icon = th.querySelector('i.fa-sort-up, i.fa-sort-down, i.fa-sort');
                    if (icon) icon.className = 'fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity text-slate-400 sort-icon-main';
                }
            });
            let isAsc = headerElem.dataset.dir !== 'asc'; headerElem.dataset.dir = isAsc ? 'asc' : 'desc';
            const icon = headerElem.querySelector('i.sort-icon-main');
            if (icon) {
                icon.className = isAsc ? 'fa-solid fa-sort-up text-theme opacity-100 sort-icon-main spin-anim' : 'fa-solid fa-sort-down text-theme opacity-100 sort-icon-main spin-anim';
                icon.classList.remove('spin-anim'); void icon.offsetWidth; icon.classList.add('spin-anim');
            }
            rows.sort((a, b) => {
                let x = a.children[n].innerText.trim(), y = b.children[n].innerText.trim();
                if (x === 'NULL') return isAsc ? -1 : 1; if (y === 'NULL') return isAsc ? 1 : -1;
                let numX = parseFloat(x), numY = parseFloat(y);
                if (!isNaN(numX) && !isNaN(numY)) return isAsc ? numX - numY : numY - numX;
                return isAsc ? x.localeCompare(y) : y.localeCompare(x);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        function sortInspectorTable(headerElem, n) {
            const table = headerElem.closest('table'); const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            table.querySelectorAll('th').forEach(th => {
                if (th !== headerElem) {
                    th.dataset.dir = ''; const icon = th.querySelector('i.fa-sort-up, i.fa-sort-down, i.fa-sort');
                    if (icon) icon.className = 'fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity sort-icon-insp text-slate-500';
                }
            });
            let isAsc = headerElem.dataset.dir !== 'asc'; headerElem.dataset.dir = isAsc ? 'asc' : 'desc';
            const icon = headerElem.querySelector('i');
            if (icon) {
                icon.className = isAsc ? 'fa-solid fa-sort-up text-theme opacity-100 spin-anim' : 'fa-solid fa-sort-down text-theme opacity-100 spin-anim';
                icon.classList.remove('spin-anim'); void icon.offsetWidth; icon.classList.add('spin-anim');
            }
            rows.sort((a, b) => {
                let x = a.children[n].innerText.trim(), y = b.children[n].innerText.trim();
                if (x === 'NULL') return isAsc ? -1 : 1; if (y === 'NULL') return isAsc ? 1 : -1;
                let numX = parseFloat(x), numY = parseFloat(y);
                if (!isNaN(numX) && !isNaN(numY)) return isAsc ? numX - numY : numY - numX;
                return isAsc ? x.localeCompare(y) : y.localeCompare(x);
            });
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>

</html>