<?php
// index.php
require_once 'config.php';
require_once 'DatabaseManager.php';

// Fungsi Helper Rahasia: Mengubah array PHP menjadi Tabel di SQLite Sementara
function convertArrayToSQLite($pdo, $tableName, $data) {
    if (empty($data)) {
        $pdo->exec("CREATE TABLE `$tableName` (`id` INTEGER PRIMARY KEY)");
        return;
    }
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $tableName);
    
    // Kumpulkan semua nama kolom dari seluruh baris (Menghindari JSON berlubang)
    $allKeys = [];
    foreach ($data as $row) {
        if (is_array($row)) {
            foreach (array_keys($row) as $k) $allKeys[$k] = true;
        }
    }
    $cols = array_keys($allKeys);
    if (empty($cols)) $cols = ['Value'];

    $colDefs = implode(", ", array_map(function($c) { 
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

// --- HANDLE THEME SAVE (GLOBAL SESSION) ---
if (isset($_GET['action']) && $_GET['action'] === 'save_theme') {
    $_SESSION['theme_color'] = $_POST['color'];
    $_SESSION['theme_color_hover'] = $_POST['hover'];
    echo json_encode(['success' => true]);
    exit;
}

$themeColor = $_SESSION['theme_color'] ?? '#3b82f6';
$themeHover = $_SESSION['theme_color_hover'] ?? '#2563eb';
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) $hex = str_repeat(substr($hex,0,1), 2) . str_repeat(substr($hex,1,1), 2) . str_repeat(substr($hex,2,1), 2);
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
if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
if ($handle = opendir($tempDir)) {
    while (false !== ($file = readdir($handle))) {
        $filepath = $tempDir . '/' . $file;
        if (is_file($filepath) && time() - filemtime($filepath) > 43200) @unlink($filepath);
    }
    closedir($handle);
}

// --- HANDLE CONNECTION FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['connect_mysql'])) {
        $_SESSION['db_connection'] = [
            'driver' => 'mysql', 'host' => $_POST['host'] ?? 'localhost',
            'user' => $_POST['user'] ?? 'root', 'pass' => $_POST['pass'] ?? '',
            'dbname' => $_POST['dbname'] ?? 'db_voidbraver'
        ];
        header("Location: index.php"); exit;
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
                header("Location: index.php"); exit;
            }
        } else {
            $errCodes = [ UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi batas php.ini.', UPLOAD_ERR_PARTIAL => 'Terupload sebagian.', UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diunggah.' ];
            $conn_error = "Upload Gagal: " . ($errCodes[$file['error']] ?? "Error Code {$file['error']}");
        }
   } elseif (isset($_POST['connect_file']) && isset($_FILES['data_file'])) {
        $file = $_FILES['data_file'];
        $allowed_exts = ['json', 'csv'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_exts)) {
            $conn_error = "Upload Gagal: Format file tidak didukung! Harap unggah file .json atau .csv.";
        } elseif ($file['error'] === UPLOAD_ERR_OK) {
            // INI MAGIC-NYA: Kita ubah File JSON/CSV jadi Database SQLite Sementara!
            $destSqlite = $tempDir . '/converted_' . time() . '_' . rand(1000,9999) . '.sqlite';
            try {
                $pdoTemp = new PDO("sqlite:" . $destSqlite);
                $pdoTemp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                if ($file_ext === 'json') {
                    $content = file_get_contents($file['tmp_name']);
                    $json = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("File JSON tidak valid: " . json_last_error_msg());
                    }
                    
                    // Deteksi struktur phpMyAdmin (banyak tabel)
                    $isPhpMyAdmin = false;
                    if (is_array($json)) {
                        foreach ($json as $item) {
                            if (is_array($item) && isset($item['type']) && $item['type'] === 'table') {
                                $isPhpMyAdmin = true; break;
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
                        if (!is_array($json) || (array_keys($json) !== range(0, count($json) - 1))) {
                            $json = [$json]; // Ubah objek jadi array 1 item
                        }
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
                    } else {
                        throw new Exception("Gagal membaca file CSV.");
                    }
                }
                
                // Set sesi seolah-olah user mengupload SQLite! (Sehingga UI dapat Full Feature SQL)
                $_SESSION['db_connection'] = ['driver' => 'sqlite', 'file' => $destSqlite, 'dbname' => basename($file['name']) . ' (Parsed)'];
                header("Location: index.php"); exit;
            } catch (Exception $e) {
                $conn_error = "Konversi Gagal: " . $e->getMessage();
                @unlink($destSqlite);
            }
        } else {
            $conn_error = "Upload Gagal dengan Error Code {$file['error']}";
        }
    }
}

// ==========================================
// TAMPILAN LOGIN / KONEKSI
// ==========================================
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
        :root { --theme-color: <?= $themeColor ?>; --theme-color-hover: <?= $themeHover ?>; --theme-bg-subtle: <?= $themeSubtle ?>; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; color: #e2e8f0; }
        .text-theme { color: var(--theme-color) !important; }
        .bg-theme { background-color: var(--theme-color) !important; color: white !important; }
        .border-theme { border-color: var(--theme-color) !important; }
        .hover-bg-theme:hover { background-color: var(--theme-color-hover) !important; color: white !important; }
        .glass-card { background: rgba(30, 41, 59, 0.8); backdrop-filter: blur(20px); border: 1px solid #334155; }
        select option { background-color: #0f172a; color: #e2e8f0; padding: 10px; }
    </style>
</head>
<body class="min-h-screen w-full flex items-center justify-center p-4 lg:p-8 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] overflow-y-auto selection:bg-theme selection:text-white">
    <div class="glass-card max-w-5xl w-full rounded-2xl shadow-2xl overflow-hidden flex flex-col lg:flex-row h-auto">
        <div class="flex-1 p-6 lg:p-10 border-b lg:border-b-0 lg:border-r border-slate-700 relative">
            <h2 class="text-2xl font-bold text-theme mb-2"><i class="fa-solid fa-server mr-2"></i> MySQL Server</h2>
            <p class="text-sm text-slate-400 mb-6">Hubungkan ke database lokal atau remote.</p>
            
            <?php if(isset($conn_error)): ?>
                <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg text-xs mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($conn_error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="mysqlForm">
                <input type="hidden" name="connect_mysql" value="1">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-slate-400 mb-1">Host</label><input type="text" name="host" id="host" value="localhost" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors"></div>
                    <div><label class="block text-xs font-bold text-slate-400 mb-1">User</label><input type="text" name="user" id="user" value="root" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors"></div>
                </div>
                <div><label class="block text-xs font-bold text-slate-400 mb-1">Password</label><input type="password" name="pass" id="pass" placeholder="(Kosongkan jika XAMPP)" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-theme transition-colors"></div>
                <div>
                    <div class="flex justify-between items-end mb-1">
                        <label class="block text-xs font-bold text-theme">Pilih Database</label>
                        <button type="button" onclick="loadDatabases()" id="btnLoadDb" class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-md transition-colors"><i class="fa-solid fa-rotate mr-1"></i> Load List</button>
                    </div>
                    <input type="text" name="dbname" id="dbInput" value="db_voidbraver" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none text-theme font-bold tracking-wide focus:border-theme transition-colors">
                    <select id="dbSelect" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none text-theme font-bold cursor-pointer hidden focus:border-theme transition-colors"></select>
                </div>
                <button type="submit" class="w-full bg-theme hover-bg-theme font-bold py-3 px-4 rounded-xl transition-colors mt-4 shadow-lg shadow-black/20">Connect MySQL <i class="fa-solid fa-arrow-right ml-1"></i></button>
            </form>
        </div>
        <div class="flex-1 p-6 lg:p-10 bg-slate-800/50 flex flex-col justify-center">
            <h2 class="text-2xl font-bold text-emerald-500 mb-2"><i class="fa-solid fa-file-code mr-2"></i> SQLite File</h2>
            <p class="text-sm text-slate-400 mb-6">Unggah file .sqlite atau .db untuk diinspeksi.</p>
            <form method="POST" enctype="multipart/form-data" class="flex flex-col flex-1" id="sqliteForm">
                <input type="hidden" name="connect_sqlite" value="1">
                <div class="flex-1 border-2 border-dashed border-slate-600 rounded-xl flex flex-col items-center justify-center p-8 text-center hover:border-emerald-500 hover:bg-emerald-500/5 transition-colors group cursor-pointer relative min-h-[250px]">
                    <input type="file" name="sqlite_file" accept=".sqlite,.db,.sqlite3" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" 
                    onchange="
                        const allowedExts = ['sqlite', 'sqlite3', 'db']; const file = this.files[0];
                        if (file) {
                            if (!allowedExts.includes(file.name.split('.').pop().toLowerCase())) {
                                alert('Format ditolak! Hanya menerima .sqlite, .sqlite3, atau .db'); this.value = ''; return false;
                            }
                            document.getElementById('uploadIcon').className = 'fa-solid fa-circle-notch fa-spin text-5xl text-emerald-500 mb-4'; 
                            document.getElementById('fileName').innerText = 'Memuat Database...'; 
                            this.form.submit();
                        }
                    ">
                    <i id="uploadIcon" class="fa-solid fa-cloud-arrow-up text-5xl text-slate-500 group-hover:text-emerald-500 mb-4 transition-colors"></i>
                    <p class="text-base font-bold text-slate-300 group-hover:text-emerald-400" id="fileName">Klik atau Drop file SQLite ke sini</p>
                    <p class="text-sm text-slate-500 mt-2" id="fileDesc">Mendukung file format .sqlite, .sqlite3, .db</p>
                </div>
            </form>
        </div>
        <div class="flex-1 p-6 lg:p-10 border-t lg:border-t-0 lg:border-l border-slate-700 bg-slate-800/30 flex flex-col justify-center">
            <h2 class="text-2xl font-bold text-amber-400 mb-2"><i class="fa-solid fa-file-csv mr-2"></i> JSON / CSV</h2>
            <p class="text-sm text-slate-400 mb-6">Inspeksi data JSON/CSV dengan mode Super.</p>
            <form method="POST" enctype="multipart/form-data" class="flex flex-col flex-1" id="fileForm">
                <input type="hidden" name="connect_file" value="1">
                <div class="flex-1 border-2 border-dashed border-slate-600 rounded-xl flex flex-col items-center justify-center p-8 text-center hover:border-amber-400 hover:bg-amber-400/5 transition-colors group cursor-pointer relative min-h-[250px]">
                    <input type="file" name="data_file" accept=".json,.csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" 
                    onchange="
                        const allowedExts = ['json', 'csv']; const file = this.files[0];
                        if (file) {
                            if (!allowedExts.includes(file.name.split('.').pop().toLowerCase())) {
                                alert('Format ditolak! Hanya menerima .json atau .csv'); this.value = ''; return false;
                            }
                            document.getElementById('uploadIconFile').className = 'fa-solid fa-circle-notch fa-spin text-5xl text-amber-400 mb-4'; 
                            document.getElementById('fileNameFile').innerText = 'Mengonversi Data...'; 
                            this.form.submit();
                        }
                    ">
                    <i id="uploadIconFile" class="fa-solid fa-file-code text-5xl text-slate-500 group-hover:text-amber-400 mb-4 transition-colors"></i>
                    <p class="text-base font-bold text-slate-300 group-hover:text-amber-400" id="fileNameFile">Drop file JSON/CSV ke sini</p>
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
                    if(!foundVoid && data.dbs.length > 0) select.selectedIndex = 0;
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
// DASHBOARD UTAMA (SETELAH KONEKSI BERHASIL)
// ==========================================
$sessionConn = $_SESSION['db_connection'] ?? null;
$driver = $sessionConn['driver'] ?? 'mysql';

$dbManager = new DatabaseManager($pdo, $dbname, $driver);

if (isset($_GET['action']) && $_GET['action'] === 'trace') {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    try {
        $traceMode = $_GET['trace_mode'] ?? 'raw';
        $res = $dbManager->traceValueAdvanced($_GET['table'], $_GET['col'], $_GET['val'], $traceMode);
        echo json_encode(['error' => false, 'data' => $res]);
    } catch (Exception $e) { echo json_encode(['error' => true, 'message' => $e->getMessage()]); }
    exit;
}

if (isset($_GET['action']) && ($_GET['action'] === 'sqlite' || $_GET['action'] === 'sqlite_all')) {
    $file = ($_GET['action'] === 'sqlite_all') ? $dbManager->exportFullDatabaseToSQLite() : $dbManager->exportToSQLite($_GET['table']);
    if ($file && file_exists($file)) {
        $filename = ($_GET['action'] === 'sqlite_all') ? "{$dbname}_FULL_Backup.sqlite" : "{$_GET['table']}_backup.sqlite";
        header('Content-Description: File Transfer'); header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: ' . filesize($file));
        readfile($file); unlink($file); exit;
    }
    die("Gagal mengekspor data.");
}

$tables = $dbManager->getTables();
$relations = $dbManager->getRelations();
$currentTable = $_GET['table'] ?? (!empty($tables) ? $tables[0] : null);
$mode = $_GET['mode'] ?? 'join';

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
        if (isset($result['error'])) {
            $db_error = $result['error'];
        } else {
            $rows = $result;
        }
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
            --theme-color: <?= $themeColor ?>; --theme-color-hover: <?= $themeHover ?>; --theme-bg-subtle: <?= $themeSubtle ?>; 
            --bg-base: #0f172a; --bg-panel: #1e293b; --border-color: #334155; 
            --app-font-scale: 16px; --cell-py: 0.75rem; --cell-px: 1.25rem;
        }
        
        html { font-size: var(--app-font-scale); }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-base); color: #e2e8f0; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .text-theme { color: var(--theme-color) !important; }
        .bg-theme { background-color: var(--theme-color) !important; color: white !important;}
        .border-theme { border-color: var(--theme-color) !important; }
        .hover-text-theme:hover { color: var(--theme-color) !important; }
        .hover-bg-theme:hover { background-color: var(--theme-color-hover) !important; color: white !important; }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--theme-color); }
        
        th { position: sticky; top: 0; z-index: 20; }
        .cell-pad { padding: var(--cell-py) var(--cell-px) !important; }

        @keyframes spin-anim { 0% { transform: rotate(-180deg) scale(0.5); opacity: 0; } 100% { transform: rotate(0deg) scale(1); opacity: 1; } }
        .spin-anim { animation: spin-anim 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        
        td { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; }
        td:hover { background: var(--theme-bg-subtle); color: var(--theme-color); cursor: pointer; }
        td::after { content: "\f002 Inspect"; font-family: 'Plus Jakarta Sans', 'Font Awesome 6 Free'; font-weight: 700; position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%) translateY(5px); background: var(--theme-color); color: white; padding: 4px 10px; border-radius: 6px; font-size: 11px; opacity: 0; visibility: hidden; pointer-events: none; transition: all 0.2s ease; white-space: nowrap; z-index: 50; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3); }
        td::before { content: ""; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%) translateY(5px); border-width: 4px; border-style: solid; border-color: var(--theme-color) transparent transparent transparent; opacity: 0; visibility: hidden; transition: all 0.2s ease; z-index: 50; }
        td:hover::after, td:hover::before { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        
        .glass-panel { background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(20px); border: 1px solid var(--border-color); }
        .sidebar-link.active { background: var(--theme-bg-subtle); border-right: 3px solid var(--theme-color); color: var(--theme-color); font-weight: 600; }
        .sidebar-collapsed { width: 5rem !important; }
        .sidebar-collapsed .hide-on-collapse { display: none !important; }
        .sidebar-collapsed .show-on-collapse { display: flex !important; }
        .sidebar-collapsed .center-on-collapse { justify-content: center !important; padding-left: 0; padding-right: 0; }
    </style>
    <style id="dynamicPinStyle"></style>
    
    <script>
        const savedFontSize = localStorage.getItem('voidDbFontSize') || '16';
        const savedDensity = JSON.parse(localStorage.getItem('voidDbDensity')) || {py: '0.75rem', px: '1.25rem'};
        document.documentElement.style.setProperty('--app-font-scale', savedFontSize + 'px');
        document.documentElement.style.setProperty('--cell-py', savedDensity.py);
        document.documentElement.style.setProperty('--cell-px', savedDensity.px);
    </script>
</head>
<body class="flex h-screen overflow-hidden antialiased selection:bg-theme selection:text-white">

    <div id="settingsModal" class="fixed inset-0 z-[120] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-[#1e293b] border border-slate-700 w-full max-w-lg rounded-xl shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
            <div class="p-5 border-b border-slate-700/50 flex justify-between items-center bg-slate-800/50 shrink-0">
                <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fa-solid fa-gear text-slate-400"></i> Konfigurasi Sistem</h3>
                <button onclick="toggleSettings()" class="text-slate-400 hover:text-red-400"><i class="fa-solid fa-xmark fa-lg"></i></button>
            </div>
            <div class="p-6 space-y-8 overflow-y-auto">
                
                <?php if ($driver === 'mysql' && $sessionConn): ?>
                <div>
                    <label class="text-xs text-slate-400 block mb-2 uppercase font-bold tracking-wider"><i class="fa-solid fa-user mr-1"></i> Informasi Profil</label>
                    <div class="bg-slate-900/50 border border-slate-700 rounded-lg p-3 text-sm text-slate-300 font-mono shadow-inner">
                        <p><span class="text-slate-500 mr-2">Host:</span> <?= htmlspecialchars($sessionConn['host']) ?></p>
                        <p><span class="text-slate-500 mr-2">User:</span> <?= htmlspecialchars($sessionConn['user']) ?></p>
                        <p><span class="text-slate-500 mr-2">Tipe:</span> MySQL DB</p>
                    </div>
                </div>
                <?php endif; ?>

                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i class="fa-solid fa-palette mr-1"></i> Aksen Warna Tema</label>
                    <div class="flex flex-wrap gap-3 items-center">
                        <button onclick="setTheme('#3b82f6')" class="w-8 h-8 rounded-full bg-blue-500 hover:scale-110 transition-transform shadow-md"></button>
                        <button onclick="setTheme('#10b981')" class="w-8 h-8 rounded-full bg-emerald-500 hover:scale-110 transition-transform shadow-md"></button>
                        <button onclick="setTheme('#8b5cf6')" class="w-8 h-8 rounded-full bg-violet-500 hover:scale-110 transition-transform shadow-md"></button>
                        <button onclick="setTheme('#f43f5e')" class="w-8 h-8 rounded-full bg-rose-500 hover:scale-110 transition-transform shadow-md"></button>
                        <button onclick="setTheme('#f59e0b')" class="w-8 h-8 rounded-full bg-amber-500 hover:scale-110 transition-transform shadow-md"></button>
                        <button onclick="setTheme('#06b6d4')" class="w-8 h-8 rounded-full bg-cyan-500 hover:scale-110 transition-transform shadow-md"></button>
                        <div class="h-6 w-px bg-slate-700 mx-1"></div>
                        <div class="flex items-center gap-2 bg-slate-800 p-1.5 rounded-lg border border-slate-700 hover:border-slate-500 transition-colors">
                            <label for="colorPicker" class="text-xs font-bold text-slate-400 cursor-pointer pl-1"><i class="fa-solid fa-eye-dropper"></i> Custom</label>
                            <input type="color" id="colorPicker" value="<?= $themeColor ?>" class="w-6 h-6 rounded cursor-pointer border-0 p-0 bg-transparent" onchange="setTheme(this.value)">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i class="fa-solid fa-universal-access mr-1"></i> Aksesibilitas & Tampilan</label>
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs text-slate-500 mb-2">Ukuran Huruf (Global Zoom)</p>
                            <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner w-full">
                                <button onclick="setA11y('font', 14)" id="font14" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Kecil</button>
                                <button onclick="setA11y('font', 16)" id="font16" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Normal</button>
                                <button onclick="setA11y('font', 18)" id="font18" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Besar</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 mb-2">Kerapatan Tabel (Padding)</p>
                            <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner w-full">
                                <button onclick="setA11y('density', 'tight')" id="denseTight" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Rapat</button>
                                <button onclick="setA11y('density', 'normal')" id="denseNormal" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Normal</button>
                                <button onclick="setA11y('density', 'loose')" id="denseLoose" class="flex-1 py-1.5 rounded-md text-xs font-bold transition-colors">Longgar</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pt-2">
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i class="fa-solid fa-hard-drive mr-1"></i> Database Backup</label>
                    <a href="?action=sqlite_all" class="w-full py-3 px-4 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-white font-medium flex items-center justify-center gap-2">
                        <i class="fa-solid fa-file-arrow-down text-theme"></i> Unduh Full DB (.sqlite)
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="tracerModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-2 md:p-4 bg-slate-900/90 backdrop-blur-md transition-opacity">
        <div class="glass-panel w-full max-w-[98vw] lg:max-w-7xl h-[95vh] rounded-xl shadow-2xl flex flex-col overflow-hidden transform scale-95 transition-transform border border-slate-600" id="tracerContent">
            <div class="p-4 border-b border-slate-700/50 flex flex-col gap-4 bg-slate-800/90">
                <div class="flex justify-between items-start md:items-center">
                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                        <button id="btnBackTracer" onclick="goBackTracer()" class="hidden px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded-md transition-colors text-sm font-medium w-max"><i class="fa-solid fa-arrow-left mr-1"></i> Kembali</button>
                        <div>
                            <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fa-solid fa-network-wired text-theme"></i> Deep Inspector</h3>
                            <div id="tracerBreadcrumbs" class="flex items-center gap-2 mt-1 text-xs text-slate-400 font-mono overflow-x-auto max-w-[250px] md:max-w-3xl whitespace-nowrap"></div>
                        </div>
                    </div>
                    <button onclick="closeTracer()" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-red-500/20 hover:text-red-400 transition-colors"><i class="fa-solid fa-xmark fa-lg"></i></button>
                </div>
                <div class="flex flex-col md:flex-row justify-between md:items-center bg-slate-900/60 p-2 rounded-lg border border-slate-700/50 gap-3">
                    <div class="flex gap-2 text-sm overflow-x-auto pb-1 md:pb-0" id="inspectorFilters">
                        <button class="px-3 py-1.5 rounded-md bg-theme text-white font-medium filter-btn whitespace-nowrap shadow-sm" data-filter="all">Semua</button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center whitespace-nowrap" data-filter="source_row"><i class="fa-solid fa-crosshairs hidden md:inline"></i> Utama <span id="badge-source" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center whitespace-nowrap" data-filter="row_relations"><i class="fa-solid fa-link hidden md:inline"></i> Relasi <span id="badge-row" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center whitespace-nowrap" data-filter="global_matches"><i class="fa-solid fa-globe hidden md:inline"></i> Global <span id="badge-global" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                    </div>
                    <div class="flex bg-slate-800 rounded-md p-1 border border-slate-700 shrink-0">
                        <button onclick="changeTracerMode('raw')" id="tracerModeRaw" class="px-3 py-1 rounded text-xs font-bold bg-theme text-white"><i class="fa-solid fa-table mr-1"></i> Raw</button>
                        <button onclick="changeTracerMode('join')" id="tracerModeJoin" class="px-3 py-1 rounded text-xs font-bold text-slate-400 hover:text-white"><i class="fa-solid fa-object-group mr-1"></i> Join</button>
                    </div>
                </div>
            </div>
            <div class="p-4 md:p-6 overflow-y-auto overflow-x-hidden flex-1 font-mono text-sm space-y-6 bg-[#0f172a]" id="tracerBody"></div>
        </div>
    </div>

    <aside id="appSidebar" class="w-64 bg-[#1e293b] border-r border-slate-700 flex flex-col z-40 shrink-0 shadow-xl transition-all duration-300 absolute md:relative inset-y-0 left-0 -translate-x-full md:translate-x-0">
        <div class="p-5 border-b border-slate-700 flex justify-between items-center bg-[#1e293b] h-[72px]">
            <h1 class="text-xl font-black text-white tracking-wide flex items-center gap-2 overflow-hidden hide-on-collapse whitespace-nowrap">
                <i class="fa-solid fa-database text-theme"></i> VOID<span class="text-slate-500 font-light">DB</span>
            </h1>
            <div class="hidden justify-center w-full icon-on-collapse text-theme show-on-collapse"><i class="fa-solid fa-database fa-lg"></i></div>
            <button onclick="toggleSidebarDesktop()" class="text-slate-400 hover:text-theme transition-colors hidden md:block hide-on-collapse" title="Collapse Sidebar"><i class="fa-solid fa-angles-left"></i></button>
            <button onclick="toggleSidebarDesktop()" class="text-slate-400 hover:text-theme transition-colors hidden show-on-collapse absolute right-0 left-0 mx-auto w-max mt-16 z-50 bg-slate-800 rounded-full p-2 border border-slate-600 shadow-lg"><i class="fa-solid fa-angles-right"></i></button>
            <button onclick="toggleSidebarMobile()" class="md:hidden text-slate-400 hover:text-red-400 text-2xl"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="border-b border-slate-700 bg-slate-800/30">
            <div class="px-5 py-4 hide-on-collapse">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Active Connection</p>
                    <button onclick="toggleSettings()" class="text-slate-400 hover:text-theme transition-colors" title="Settings"><i class="fa-solid fa-gear"></i></button>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm text-slate-300 font-mono truncate bg-slate-900/50 p-1.5 rounded border border-slate-700 w-full">
                        <i class="fa-solid <?= $driver === 'sqlite' ? 'fa-file-code text-emerald-400' : 'fa-server text-blue-400' ?> ml-1"></i>
                        <span class="truncate ml-1" title="<?= htmlspecialchars($dbname) ?>"><?= htmlspecialchars($dbname) ?></span>
                        <a href="?action=disconnect" class="text-red-400 hover:text-red-300 ml-auto mr-1 p-1 bg-red-500/10 rounded transition-colors" title="Disconnect"><i class="fa-solid fa-power-off"></i></a>
                    </div>
                </div>
            </div>
            <div class="hidden flex-col items-center gap-4 py-6 show-on-collapse">
                <button onclick="toggleSettings()" class="text-slate-400 hover:text-theme transition-colors p-2 rounded-lg hover:bg-slate-800" title="Settings"><i class="fa-solid fa-gear fa-xl"></i></button>
                <a href="?action=disconnect" class="text-red-400 hover:text-red-300 transition-colors p-2 rounded-lg hover:bg-red-900/30 mt-2" title="Disconnect"><i class="fa-solid fa-power-off fa-xl"></i></a>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto py-4 space-y-1 hide-on-collapse flex flex-col">
            <div class="px-5 mb-3 text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center justify-between">
                <span>Daftar Tabel</span><span class="bg-slate-800 px-1.5 py-0.5 rounded text-slate-400"><?= count($tables) ?></span>
            </div>
            <?php foreach ($tables as $t): ?>
                <a href="?table=<?= urlencode($t) ?>&mode=<?= $mode ?>" class="sidebar-link block px-5 py-2.5 text-sm transition-all duration-200 <?= $t === $currentTable ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-table-cells text-slate-500 mr-2 opacity-70 w-4 text-center"></i> 
                    <span class="truncate"><?= htmlspecialchars($t) ?></span>
                </a>
            <?php endforeach; ?>
            
            <div class="px-5 mt-auto pt-4 border-t border-slate-700">
                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest mb-2">Metadata Stats</p>
                <div class="flex items-center justify-between text-[11px] text-slate-400">
                    <span>Relasi Terdeteksi:</span>
                    <span class="font-mono text-theme"><?= count($relations) ?></span>
                </div>
            </div>
        </div>
    </aside>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden backdrop-blur-sm" onclick="toggleSidebarMobile()"></div>

    <main class="flex-1 flex flex-col min-w-0 bg-[#0f172a] relative h-screen">
        
        <header class="bg-[#1e293b] border-b border-slate-700 shadow-sm z-[70] relative shrink-0 h-[72px] md:h-auto flex flex-col justify-center">
            
            <div id="mobileHeaderNormal" class="px-4 py-2 md:p-5 flex flex-1 flex-row justify-between items-center gap-4 transition-all">
                <div class="flex items-center gap-3 min-w-0 w-full md:w-auto">
                    <button onclick="toggleSidebarMobile()" class="md:hidden text-slate-400 hover:text-white p-2 -ml-2 rounded-lg hover:bg-slate-800 transition-colors"><i class="fa-solid fa-bars fa-lg"></i></button>
                    <div class="w-10 h-10 rounded-lg bg-theme/10 border border-theme/20 items-center justify-center text-theme shrink-0 hidden md:flex"><i class="fa-solid fa-table-list fa-lg"></i></div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg md:text-xl font-bold text-white font-mono leading-none truncate w-full"><?= htmlspecialchars($currentTable) ?></h2>
                        <p class="text-[10px] md:text-xs text-slate-400 mt-1" id="rowCount"><i class="fa-solid fa-chart-simple mr-1"></i> <?= count($rows) ?> baris dimuat</p>
                    </div>
                </div>
                
                <button onclick="toggleMobileSearch(true)" class="md:hidden text-slate-400 hover:text-theme p-2.5 rounded-lg bg-slate-800 border border-slate-700 shrink-0 shadow-sm transition-colors">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>

                <div class="hidden md:flex items-center gap-2 md:gap-3 shrink-0 ml-auto">
                    <div class="relative group shrink-0">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 group-focus-within:text-theme transition-colors text-xs md:text-sm"></i>
                        <input type="text" id="globalSearchDesk" placeholder="Cari data..." class="pl-8 pr-3 py-1.5 md:py-2 bg-slate-900 border border-slate-700 focus:border-theme rounded-lg text-xs md:text-sm outline-none text-slate-200 transition-all w-32 md:w-56 focus:w-48 md:focus:w-72 shadow-inner">
                    </div>
                    <div class="relative group shrink-0">
                        <button class="px-3 md:px-4 py-1.5 md:py-2 bg-slate-800 border border-slate-600 hover:border-slate-500 rounded-lg text-xs md:text-sm transition-colors flex items-center gap-2 text-slate-200 shadow-sm">
                            <i class="fa-solid fa-eye-slash text-slate-400"></i> <span class="hidden md:inline">Kolom</span>
                        </button>
                        <div class="absolute right-0 mt-2 w-56 md:w-64 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all p-2 flex flex-col gap-1 max-h-60 overflow-y-auto z-[100]" id="columnToggles"></div>
                    </div>
                    
                    <a href="?action=sqlite&table=<?= urlencode($currentTable) ?>" class="px-3 md:px-4 py-1.5 md:py-2 bg-slate-800 border border-slate-600 hover-border-theme hover-bg-theme text-slate-200 rounded-lg text-xs md:text-sm font-medium transition-all flex items-center gap-2 shadow-sm shrink-0">
                        <i class="fa-solid fa-download"></i> <span class="hidden lg:inline">.sqlite</span>
                    </a>
                    <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner shrink-0">
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=raw" class="px-3 md:px-4 py-1 md:py-1.5 rounded-md text-xs md:text-sm transition-colors <?= $mode === 'raw' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i class="fa-solid fa-cube md:mr-1"></i> <span class="hidden md:inline">Raw</span></a>
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=join" class="px-3 md:px-4 py-1 md:py-1.5 rounded-md text-xs md:text-sm transition-colors <?= $mode === 'join' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i class="fa-solid fa-link md:mr-1"></i> <span class="hidden md:inline">Joined</span></a>
                    </div>
                </div>
            </div>

            <div id="mobileHeaderSearch" class="hidden absolute inset-0 bg-[#1e293b] z-20 px-4 items-center w-full">
                <div class="relative w-full flex items-center">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 text-theme text-sm z-10"></i>
                    <input type="text" id="globalSearchMobile" placeholder="Ketik untuk mencari..." class="w-full pl-10 pr-12 py-3 bg-slate-900 border border-theme rounded-xl text-sm outline-none text-white shadow-lg transition-colors">
                    <button onclick="toggleMobileSearch(false)" class="absolute right-3 text-slate-400 hover:text-red-400 p-2 rounded-lg bg-slate-800 transition-colors"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
        </header>

        <?php if (isset($db_error)): ?>
            <div class="px-4 md:px-6 pt-4 z-10 relative">
                <div class="bg-red-500/10 border border-red-500/50 text-red-400 p-4 rounded-xl flex items-start gap-3 shadow-lg">
                    <i class="fa-solid fa-triangle-exclamation text-xl mt-0.5"></i>
                    <div>
                        <h4 class="font-bold text-sm">Error Pembacaan Data</h4>
                        <p class="text-xs mt-1 font-mono opacity-80"><?= htmlspecialchars($db_error) ?></p>
                    </div>
                </div>
            </div>
        <?php elseif ($query_status === "no_relation"): ?>
            <div class="px-4 md:px-6 pt-4 z-10 relative">
                <div class="bg-amber-500/10 border border-amber-500/50 text-amber-500 px-4 py-3 rounded-xl text-xs flex items-center gap-3 shadow-lg">
                    <i class="fa-solid fa-circle-info fa-lg"></i>
                    <span>Tabel ini tidak memiliki <b>Foreign Key</b> (kolom relasi) ke tabel lain. Mode otomatis dikembalikan ke <b>Raw</b>.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="p-4 md:p-6 flex-1 min-h-0 relative flex flex-col z-10">
            <div class="overflow-auto rounded-xl border border-slate-700 bg-[#1e293b] shadow-lg relative flex-1">
                <table class="w-full text-left border-collapse whitespace-nowrap" id="dataTable">
                    <?php if (!empty($rows)): ?>
                        <thead class="bg-slate-800 text-slate-300 text-xs uppercase tracking-wider select-none shadow-sm">
                            <tr id="tableHeaderRow">
                                <?php foreach (array_keys($rows[0]) as $index => $col): ?>
                                    <th class="border-b border-slate-700 font-semibold bg-slate-800 cursor-pointer hover-text-theme transition-colors group/th cell-pad" onclick="sortTable(<?= $index ?>, this)" data-dir="">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2 flex-1 min-w-0">
                                                <span class="truncate"><?= htmlspecialchars($col) ?></span>
                                                <i class="fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity text-slate-400 sort-icon-main"></i>
                                            </div>
                                            <button class="opacity-0 group-hover/th:opacity-100 w-6 h-6 flex items-center justify-center hover:bg-slate-700 rounded transition-all text-slate-400 hover-text-theme ml-2 shrink-0" onclick="event.stopPropagation(); pinColumn(<?= $index ?>)" title="Pin Kolom">
                                                <i class="fa-solid fa-thumbtack"></i>
                                            </button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="text-xs md:text-sm divide-y divide-slate-700/50 font-mono text-slate-300">
                            <?php foreach ($rows as $row): ?>
                                <tr class="hover:bg-slate-800/80 transition-colors data-row">
                                    <?php 
                                    $colKeys = array_keys($row);
                                    $colIndex = 0;
                                    foreach ($row as $cell): 
                                        $colName = $colKeys[$colIndex];
                                    ?>
                                        <td class="cell-pad traceable" onclick="startTrace('<?= htmlspecialchars($currentTable) ?>', '<?= htmlspecialchars($colName) ?>', '<?= htmlspecialchars($cell ?? '') ?>')">
                                            <?= ($cell === null) ? '<span class="text-slate-600 italic">NULL</span>' : htmlspecialchars($cell) ?>
                                        </td>
                                    <?php $colIndex++; endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <tbody><tr><td class="py-20 text-center text-slate-500"><i class="fa-solid fa-folder-open text-4xl mb-3 opacity-50"></i><p>Tidak ada data di tabel ini.</p></td></tr></tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="md:hidden fixed bottom-6 right-6 z-[60]">
            <button id="fabBtn" class="w-14 h-14 bg-theme text-white rounded-full shadow-[0_4px_15px_rgba(0,0,0,0.5)] flex items-center justify-center text-xl hover:scale-105 transition-transform" onclick="toggleMobileTools()">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </button>
        </div>

        <div id="mobileToolsOverlay" class="fixed inset-0 bg-black/60 z-[65] hidden backdrop-blur-sm" onclick="toggleMobileTools()"></div>
        
        <div id="mobileToolsSheet" class="fixed inset-x-0 bottom-0 z-[70] translate-y-full transition-transform duration-300 ease-out bg-[#1e293b] rounded-t-3xl shadow-[0_-10px_40px_rgba(0,0,0,0.5)] border-t border-slate-700 flex flex-col max-h-[85vh]">
            <div class="p-5 border-b border-slate-700 flex justify-between items-center bg-slate-800/50 rounded-t-3xl">
                <h3 class="font-bold text-white text-lg"><i class="fa-solid fa-sliders text-theme mr-2"></i> Alat Tabel</h3>
                <button onclick="toggleMobileTools()" class="text-slate-400 p-2 hover:text-red-400 bg-slate-800 rounded-lg"><i class="fa-solid fa-xmark fa-lg"></i></button>
            </div>
            <div class="p-5 overflow-y-auto space-y-6">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i class="fa-solid fa-eye mr-1"></i> Mode Tampilan</label>
                    <div class="flex bg-slate-900 rounded-lg p-1.5 border border-slate-700 shadow-inner w-full">
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=raw" class="flex-1 text-center py-2 rounded-md text-sm font-bold transition-colors <?= $mode === 'raw' ? 'bg-theme text-white shadow-sm' : 'text-slate-500' ?>"><i class="fa-solid fa-cube mr-1"></i> Raw</a>
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=join" class="flex-1 text-center py-2 rounded-md text-sm font-bold transition-colors <?= $mode === 'join' ? 'bg-theme text-white shadow-sm' : 'text-slate-500' ?>"><i class="fa-solid fa-link mr-1"></i> Joined</a>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i class="fa-solid fa-download mr-1"></i> Ekspor Data</label>
                    <a href="?action=sqlite&table=<?= urlencode($currentTable) ?>" class="w-full py-3 bg-slate-800 border border-slate-600 text-slate-200 rounded-lg text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-sm">
                        <i class="fa-solid fa-file-arrow-down text-theme"></i> Download Tabel (.sqlite)
                    </a>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 block"><i class="fa-solid fa-eye-slash mr-1"></i> Sembunyikan Kolom</label>
                    <div id="mobileColumnToggles" class="grid grid-cols-2 gap-2 bg-slate-900/50 p-3 rounded-xl border border-slate-700"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleMobileSearch(show) {
            const normal = document.getElementById('mobileHeaderNormal');
            const search = document.getElementById('mobileHeaderSearch');
            const input = document.getElementById('globalSearchMobile');
            
            if (show) {
                normal.classList.add('hidden'); normal.classList.remove('flex');
                search.classList.remove('hidden'); search.classList.add('flex');
                input.focus();
            } else {
                search.classList.add('hidden'); search.classList.remove('flex');
                normal.classList.remove('hidden'); normal.classList.add('flex');
                input.value = ''; executeSearch('');
            }
        }

        function updateA11yUI() {
            const fs = localStorage.getItem('voidDbFontSize') || '16';
            const dens = JSON.parse(localStorage.getItem('voidDbDensity')) || {py: '0.75rem', px: '1.25rem'};
            
            ['font14', 'font16', 'font18'].forEach(id => { 
                if(document.getElementById(id)) document.getElementById(id).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors text-slate-400 hover:text-white'; 
            });
            if(document.getElementById('font' + fs)) document.getElementById('font' + fs).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors bg-theme text-white shadow-sm';

            ['denseTight', 'denseNormal', 'denseLoose'].forEach(id => { 
                if(document.getElementById(id)) document.getElementById(id).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors text-slate-400 hover:text-white'; 
            });
            
            let densId = 'denseNormal';
            if (dens.py === '0.35rem') densId = 'denseTight';
            else if (dens.py === '1.25rem') densId = 'denseLoose';
            if(document.getElementById(densId)) document.getElementById(densId).className = 'flex-1 py-1.5 rounded-md text-xs font-bold transition-colors bg-theme text-white shadow-sm';
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
                localStorage.setItem('voidDbDensity', JSON.stringify({py, px}));
            }
            updateA11yUI();
        }
        
        if(document.getElementById('font16')) updateA11yUI();

        function toggleMobileTools() {
            const sheet = document.getElementById('mobileToolsSheet');
            const overlay = document.getElementById('mobileToolsOverlay');
            if (sheet.classList.contains('translate-y-full')) {
                sheet.classList.remove('translate-y-full'); overlay.classList.remove('hidden');
            } else {
                sheet.classList.add('translate-y-full'); overlay.classList.add('hidden');
            }
        }

        const sidebar = document.getElementById('appSidebar');
        const overlaySidebar = document.getElementById('sidebarOverlay');
        let isDesktopCollapsed = false;

        function toggleSidebarMobile() {
            sidebar.classList.toggle('-translate-x-full');
            overlaySidebar.classList.toggle('hidden');
        }

        function toggleSidebarDesktop() {
            isDesktopCollapsed = !isDesktopCollapsed;
            if(isDesktopCollapsed) {
                sidebar.classList.add('sidebar-collapsed');
                document.querySelectorAll('.show-on-collapse').forEach(el => el.classList.remove('hidden', 'md:hidden'));
                document.querySelectorAll('.show-on-collapse').forEach(el => el.classList.add('flex'));
            } else {
                sidebar.classList.remove('sidebar-collapsed');
                document.querySelectorAll('.show-on-collapse').forEach(el => el.classList.add('hidden'));
                document.querySelectorAll('.show-on-collapse').forEach(el => el.classList.remove('flex'));
            }
        }

        function toggleSettings() {
            const m = document.getElementById('settingsModal');
            m.classList.toggle('hidden'); m.classList.toggle('flex');
            if(!m.classList.contains('hidden')) {
                const currentColor = getComputedStyle(document.documentElement).getPropertyValue('--theme-color').trim();
                document.getElementById('colorPicker').value = currentColor || '#3b82f6';
            }
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
            
            let c; if(/^#([A-Fa-f0-9]{3}){1,2}$/.test(hexColor)){
                c= hexColor.substring(1).split('');
                if(c.length== 3) c= [c[0], c[0], c[1], c[1], c[2], c[2]]; c= '0x'+c.join('');
                root.style.setProperty('--theme-bg-subtle', 'rgba('+[(c>>16)&255, (c>>8)&255, c&255].join(',')+',0.15)');
            }
            
            document.getElementById('colorPicker').value = hexColor;
            const formData = new FormData(); formData.append('action', 'save_theme'); formData.append('color', hexColor); formData.append('hover', hexHover);
            fetch('?action=save_theme', {method: 'POST', body: formData});
        }

        const colContainer = document.getElementById('columnToggles');
        const mobileColContainer = document.getElementById('mobileColumnToggles');
        
        if (colContainer && mobileColContainer) {
            document.querySelectorAll('#tableHeaderRow th').forEach((th, index) => {
                let colNameText = th.querySelector('.flex-1')?.innerText.trim() || `Kolom ${index}`;
                
                const labelDesk = document.createElement('label');
                labelDesk.className = "flex items-center gap-3 px-3 py-2 hover:bg-slate-700 rounded-md cursor-pointer text-sm text-slate-300 transition-colors border border-transparent hover:border-slate-600";
                labelDesk.innerHTML = `<input type="checkbox" checked class="col-toggle-${index} rounded bg-slate-900 border-slate-600 text-theme focus:ring-theme w-4 h-4 cursor-pointer" onchange="toggleColumn(${index}, this.checked)"> <span class="truncate flex-1">${colNameText}</span>`;
                colContainer.appendChild(labelDesk);

                const labelMob = document.createElement('label');
                labelMob.className = "flex items-center gap-2 p-2 bg-slate-800 rounded-lg cursor-pointer text-xs text-slate-300 border border-slate-700 active:border-theme";
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
            const nth = index + 1;
            const styleTag = document.getElementById('dynamicPinStyle');
            if (currentPinnedIndex === index) {
                styleTag.innerHTML = ''; currentPinnedIndex = -1;
            } else {
                styleTag.innerHTML = `
                    #dataTable th:nth-child(${nth}), #dataTable td:nth-child(${nth}) {
                        position: sticky !important; left: 0 !important; right: 0 !important; z-index: 30 !important;
                        background-color: var(--bg-panel) !important; box-shadow: -4px 0 15px rgba(0,0,0,0.5), 4px 0 15px rgba(0,0,0,0.5) !important;
                        border-left: 2px solid var(--theme-color) !important; border-right: 2px solid var(--theme-color) !important;
                    }
                    #dataTable td:nth-child(${nth}) { background-color: #1e293b !important; }
                    #dataTable th:nth-child(${nth}) { z-index: 40 !important; background-color: #1e293b !important; }
                `;
                currentPinnedIndex = index;
            }
        }

        function sortTable(n, headerElem) {
            const tbody = document.querySelector("#dataTable tbody");
            const rows = Array.from(tbody.querySelectorAll("tr.data-row"));
            const table = headerElem.closest('table');
            
            table.querySelectorAll('th').forEach(th => {
                if (th !== headerElem) {
                    th.dataset.dir = '';
                    const icon = th.querySelector('i.fa-sort-up, i.fa-sort-down, i.fa-sort');
                    if(icon) icon.className = 'fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity text-slate-400 sort-icon-main';
                }
            });

            let isAsc = headerElem.dataset.dir !== 'asc'; headerElem.dataset.dir = isAsc ? 'asc' : 'desc';
            const icon = headerElem.querySelector('i.sort-icon-main');
            if(icon) {
                icon.className = isAsc ? 'fa-solid fa-sort-up text-theme opacity-100 sort-icon-main spin-anim' : 'fa-solid fa-sort-down text-theme opacity-100 sort-icon-main spin-anim';
                icon.classList.remove('spin-anim'); void icon.offsetWidth; icon.classList.add('spin-anim');
            }

            rows.sort((a, b) => {
                let x = a.children[n].innerText.trim(), y = b.children[n].innerText.trim();
                if(x === 'NULL') return isAsc ? -1 : 1; if(y === 'NULL') return isAsc ? 1 : -1;
                let numX = parseFloat(x), numY = parseFloat(y);
                if(!isNaN(numX) && !isNaN(numY)) return isAsc ? numX - numY : numY - numX;
                return isAsc ? x.localeCompare(y) : y.localeCompare(x);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        function sortInspectorTable(headerElem, n) {
            const table = headerElem.closest('table'); const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            table.querySelectorAll('th').forEach(th => {
                if (th !== headerElem) {
                    th.dataset.dir = '';
                    const icon = th.querySelector('i.fa-sort-up, i.fa-sort-down, i.fa-sort');
                    if(icon) icon.className = 'fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity sort-icon-insp text-slate-500';
                }
            });

            let isAsc = headerElem.dataset.dir !== 'asc'; headerElem.dataset.dir = isAsc ? 'asc' : 'desc';
            const icon = headerElem.querySelector('i');
            if(icon) {
                icon.className = isAsc ? 'fa-solid fa-sort-up text-theme opacity-100 spin-anim' : 'fa-solid fa-sort-down text-theme opacity-100 spin-anim';
                icon.classList.remove('spin-anim'); void icon.offsetWidth; icon.classList.add('spin-anim');
            }

            rows.sort((a, b) => {
                let x = a.children[n].innerText.trim(), y = b.children[n].innerText.trim();
                if(x === 'NULL') return isAsc ? -1 : 1; if(y === 'NULL') return isAsc ? 1 : -1;
                let numX = parseFloat(x), numY = parseFloat(y);
                if(!isNaN(numX) && !isNaN(numY)) return isAsc ? numX - numY : numY - numX;
                return isAsc ? x.localeCompare(y) : y.localeCompare(x);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        const executeSearch = function(val) {
            let filter = val.toLowerCase(), count = 0;
            document.querySelectorAll("#dataTable tbody tr.data-row").forEach(row => {
                if(row.innerText.toLowerCase().includes(filter)) { row.style.display = ""; count++; }
                else row.style.display = "none";
            });
            document.getElementById('rowCount').innerHTML = `<i class="fa-solid fa-chart-simple mr-1"></i> ${count} baris dimuat`;
        };
        const searchDesk = document.getElementById('globalSearchDesk'); const searchMob = document.getElementById('globalSearchMobile');
        if(searchDesk) searchDesk.addEventListener('keyup', (e) => executeSearch(e.target.value));
        if(searchMob) searchMob.addEventListener('keyup', (e) => executeSearch(e.target.value));

        const modal = document.getElementById('tracerModal'); const tracerBody = document.getElementById('tracerBody');
        let traceHistory = []; let currentTraceData = null; let currentTracerMode = 'raw'; 

        function changeTracerMode(mode) {
            currentTracerMode = mode;
            const actCls = 'px-3 py-1 rounded text-xs font-bold bg-theme text-white shadow-sm';
            const inactCls = 'px-3 py-1 rounded text-xs font-bold text-slate-400 hover:text-white transition-colors';
            document.getElementById('tracerModeRaw').className = mode === 'raw' ? actCls : inactCls;
            document.getElementById('tracerModeJoin').className = mode === 'join' ? actCls : inactCls;
            if(traceHistory.length > 0) { const last = traceHistory[traceHistory.length - 1]; executeTrace(last.table, last.col, last.val, true); }
        }

        function startTrace(tableName, colName, value) {
            if (value === 'NULL' || value === '') return;
            traceHistory = []; executeTrace(tableName, colName, value);
        }

        function pushTrace(tableName, colName, value) {
            if (value === 'NULL' || value === '') return;
            executeTrace(tableName, colName, value);
        }

        function goBackTracer() {
            if (traceHistory.length > 1) {
                traceHistory.pop(); const prev = traceHistory[traceHistory.length - 1]; executeTrace(prev.table, prev.col, prev.val, true);
            }
        }

        async function executeTrace(tableName, colName, value, skipPush = false) {
            modal.classList.remove('hidden'); modal.classList.add('flex');
            setTimeout(() => document.getElementById('tracerContent').classList.remove('scale-95'), 10);
            
            if(!skipPush) traceHistory.push({table: tableName, col: colName, val: value});
            document.getElementById('btnBackTracer').style.display = traceHistory.length > 1 ? 'block' : 'none';
            
            document.getElementById('tracerBreadcrumbs').innerHTML = traceHistory.map((step, i) => {
                return `<span class="${i === traceHistory.length - 1 ? 'text-theme font-bold' : 'text-slate-500'}">${step.table} <span class="hidden md:inline">(${step.col}=${step.val})</span></span>`;
            }).join(' <span class="text-slate-600 text-[10px]"><i class="fa-solid fa-chevron-right"></i></span> ');

            tracerBody.innerHTML = `<div class="flex flex-col gap-4 justify-center items-center p-12 md:p-20"><i class="fa-solid fa-circle-notch fa-spin text-4xl text-theme"></i><p class="text-slate-400 text-xs md:text-sm">Menarik relasi data dari database...</p></div>`;

            try {
                const response = await fetch(`?action=trace&table=${encodeURIComponent(tableName)}&col=${encodeURIComponent(colName)}&val=${encodeURIComponent(value)}&trace_mode=${currentTracerMode}`);
                const resData = await response.json();
                if(resData.error) throw new Error(resData.message);
                
                currentTraceData = resData.data; updateFilterBadges(); renderTracerData('all'); 
            } catch (err) {
                tracerBody.innerHTML = `<div class="text-red-400 bg-red-900/20 border border-red-900 p-6 rounded-xl flex items-center gap-3"><i class="fa-solid fa-triangle-exclamation fa-2x"></i> Error: ${err.message}</div>`;
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
            if(!currentTraceData) return;
            const d = currentTraceData; let html = '';

            const renderBlock = (groups, sectionTitle, iconHtml, colorCls) => {
                if(groups.length === 0) return '';
                let blockHtml = `<h4 class="text-white font-bold mb-4 mt-6 flex items-center gap-2 px-2 text-sm md:text-base border-b border-slate-700 pb-2"><span class="text-${colorCls}">${iconHtml}</span> ${sectionTitle}</h4><div class="space-y-5 mb-8">`;
                groups.forEach(group => {
                    if(group.data.length === 0) return;
                    let keys = Object.keys(group.data[0]);
                    blockHtml += `
                        <div class="bg-[#1e293b] border border-slate-700 rounded-xl overflow-hidden shadow-md">
                            <div class="bg-slate-800 px-4 py-3 flex flex-wrap justify-between items-center border-b border-slate-700 gap-2">
                                <div class="flex items-center min-w-0">
                                    <span class="text-[10px] text-${colorCls} font-bold uppercase tracking-widest bg-slate-900 px-2.5 py-1 rounded border border-slate-700 shrink-0">${group.type}</span>
                                    <span class="text-sm text-white font-bold tracking-wide flex items-center gap-2 truncate ml-3"><i class="fa-solid fa-table text-slate-500 hidden md:inline"></i> <span class="truncate">${group.table}</span> <span class="bg-slate-700 text-slate-300 text-[10px] px-2 py-0.5 rounded-full ml-1 shrink-0">${group.data.length} baris</span></span>
                                </div>
                                <a href="?table=${encodeURIComponent(group.table)}" class="text-xs bg-theme/20 text-theme hover-bg-theme px-3 py-1.5 rounded transition-colors flex items-center gap-2 shrink-0 font-bold border border-theme/30 ml-auto">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> <span class="hidden md:inline">Buka Tabel</span>
                                </a>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="w-full text-left text-sm min-w-max">
                                    <thead class="bg-[#0f172a]/50 text-slate-400 text-xs">
                                        <tr>${keys.map((k, idx) => `
                                            <th class="px-4 py-3 font-semibold border-b border-slate-700 whitespace-nowrap cursor-pointer hover-text-theme transition-colors group/th cell-pad" onclick="sortInspectorTable(this, ${idx})">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span>${k}</span>
                                                    <i class="fa-solid fa-sort opacity-30 group-hover/th:opacity-100 transition-opacity sort-icon-insp text-slate-500"></i>
                                                </div>
                                            </th>`).join('')}
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700/50 font-mono text-xs">
                                        ${group.data.map(row => `
                                            <tr class="hover:bg-slate-700/50 transition-colors group/row">
                                                ${Object.entries(row).map(([k, v]) => `
                                                    <td class="cell-pad text-slate-300 cursor-pointer hover-text-theme whitespace-nowrap" onclick="pushTrace('${group.table}', '${k}', '${v}')">
                                                        ${v !== null ? v : '<span class="text-slate-600 italic">NULL</span>'}
                                                    </td>
                                                `).join('')}
                                            </tr>
                                        `).join('')}
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

            if(html === '') html = `<div class="text-center p-8 md:p-12 bg-slate-800/50 rounded-xl border border-slate-700"><i class="fa-solid fa-box-open text-4xl text-slate-600 mb-4"></i><p class="text-slate-400">Tidak ada data untuk kategori ini.</p></div>`;
            tracerBody.innerHTML = html;
        }

        function closeTracer() {
            document.getElementById('tracerContent').classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 150);
        }
    </script>
</body>
</html>