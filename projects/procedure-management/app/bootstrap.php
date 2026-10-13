<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Yekaterinburg');

const APP_NAME = 'Procedure Management Demo';
define('DB_PATH', getenv('APP_DB_PATH') ?: __DIR__ . '/../storage/data.sqlite');

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    if (!is_dir(dirname(DB_PATH))) mkdir(dirname(DB_PATH), 0775, true);
    $options=[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo=method_exists(PDO::class,'connect') ? PDO::connect('sqlite:'.DB_PATH,null,null,$options) : new PDO('sqlite:'.DB_PATH,null,null,$options);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000');
    $lower=static fn($v)=>mb_strtolower((string)$v,'UTF-8');
    if(method_exists($pdo,'createFunction'))$pdo->createFunction('utf8_lower',$lower,1);
    else $pdo->sqliteCreateFunction('utf8_lower',$lower,1);
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, login TEXT NOT NULL UNIQUE,
 password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('admin','manager')),
 is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS clients (
 id INTEGER PRIMARY KEY AUTOINCREMENT, last_name TEXT NOT NULL, first_name TEXT NOT NULL,
 middle_name TEXT NOT NULL DEFAULT '', birth_date TEXT, phone TEXT NOT NULL DEFAULT '',
 email TEXT NOT NULL DEFAULT '', document_note TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT, archived_by INTEGER,
 archive_reason TEXT, FOREIGN KEY(archived_by) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_clients_name ON clients(last_name,first_name);
CREATE INDEX IF NOT EXISTS idx_clients_phone ON clients(phone);
CREATE TABLE IF NOT EXISTS services (
 id INTEGER PRIMARY KEY AUTOINCREMENT, price_number TEXT NOT NULL, name TEXT NOT NULL,
 category TEXT NOT NULL DEFAULT 'Процедуры', duration TEXT NOT NULL DEFAULT '',
 price_cents INTEGER NOT NULL, room TEXT NOT NULL DEFAULT '', is_active INTEGER NOT NULL DEFAULT 1,
 source_note TEXT NOT NULL DEFAULT 'Прейскурант с 01.06.2025',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT UNIQUE, client_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'unpaid' CHECK(status IN ('unpaid','paid','completed','cancelled')),
 visit_at TEXT NOT NULL, notes TEXT NOT NULL DEFAULT '', total_cents INTEGER NOT NULL DEFAULT 0,
 created_by INTEGER NOT NULL, created_at TEXT NOT NULL, paid_at TEXT, paid_by INTEGER,
 archived_at TEXT, archived_by INTEGER, archive_reason TEXT,
 FOREIGN KEY(client_id) REFERENCES clients(id), FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(paid_by) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_orders_client ON orders(client_id,created_at DESC);
CREATE TABLE IF NOT EXISTS order_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, service_id INTEGER,
 service_name TEXT NOT NULL, price_cents INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity>0),
 duration TEXT NOT NULL DEFAULT '', room TEXT NOT NULL DEFAULT '', scheduled_at TEXT,
 line_total_cents INTEGER NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(service_id) REFERENCES services(id)
);
CREATE TABLE IF NOT EXISTS audit_log (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL,
 entity_type TEXT NOT NULL, entity_id INTEGER, details TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS procedure_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER NOT NULL, performed_at TEXT NOT NULL,
 created_at TEXT NOT NULL, user_id INTEGER NOT NULL, notes TEXT NOT NULL DEFAULT '',
 FOREIGN KEY(item_id) REFERENCES order_items(id), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS submission_keys (
 token TEXT PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id)
);
SQL);
    if ((int)$db->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
        $q=$db->prepare('INSERT INTO services(price_number,name,category,duration,price_cents) VALUES(?,?,?,?,?)');
        foreach (require __DIR__.'/services.php' as $r) $q->execute([$r[0],$r[1],$r[2],$r[3],$r[4]*100]);
    }
}

function now(): string { return date('Y-m-d H:i:s'); }
function e(?string $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function money(int $c): string { return number_format($c/100,2,',',' ').' ₽'; }
function current_user(): ?array { return $_SESSION['user']??null; }
function is_manager(): bool { return (current_user()['role']??'')==='manager'; }
function require_auth(): void { if (!current_user()) redirect('?page=login'); }
function csrf(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(24)); }
function check_csrf(): void {
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) {
        http_response_code(419); exit('Сессия устарела. Обновите страницу.');
    }
}
function flash(string $type,string $text): void { $_SESSION['flash'][]=compact('type','text'); }
function audit(string $action,string $entity,?int $id,string $details=''): void {
    $q=db()->prepare('INSERT INTO audit_log(user_id,action,entity_type,entity_id,details,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([current_user()['id']??null,$action,$entity,$id,$details,now()]);
}
function redirect(string $url): never { header('Location: '.$url); exit; }
function client_name(array $c): string { return trim($c['last_name'].' '.$c['first_name'].' '.$c['middle_name']); }
function status_label(string $s): string {
    return ['unpaid'=>'К оплате','paid'=>'Оплачено','completed'=>'Оказано','cancelled'=>'Отменено'][$s]??$s;
}
function record(string $table,int $id): array {
    if(!in_array($table,['clients','services','orders'],true))throw new RuntimeException('Неверный объект');
    $q=db()->prepare("SELECT * FROM $table WHERE id=?");$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new RuntimeException('Запись не найдена.');
    return $row;
}
function valid_datetime(string $value): string {
    $value=str_replace('T',' ',$value);
    if(strlen($value)===16)$value.=':00';
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
    if(!$dt || $dt->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Некорректная дата и время.');
    return $value;
}

