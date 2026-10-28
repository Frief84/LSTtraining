<?php
// Behavioral endpoint tests with an in-memory PDO test double. No live API/database writes.
define('ABSPATH', __DIR__ . '/');
$hooks = [];
function add_action($name, $fn) { $GLOBALS['hooks'][$name][] = $fn; }
function current_user_can($cap) { return $GLOBALS['admin'] ?? false; }
function get_current_user_id() { return 1; }
function current_time($type, $gmt = false) { return '2026-10-07 12:00:00'; }
function remove_accents($value, $locale = null) { return strtr($value, ['ö'=>'o','ä'=>'a','ü'=>'u']); }
function register_rest_route($namespace, $path, $config) { $GLOBALS['routes'][$path] = $config; }
class WP_REST_Request {
    public function __construct(public array $body) {}
    public function get_json_params() { return $this->body; }
}
function lst_manage_success($data, $status = 200) { return ['status'=>$status, 'data'=>$data]; }
function lst_manage_error($code, $message, $status) { return ['status'=>$status, 'error'=>$code, 'message'=>$message]; }
function lst_manage_connection() { return $GLOBALS['db']; }
function lst_manage_get_config($resource) { return ['table'=>$resource,'object_type'=>$resource]; }
function lst_manage_normalize_payload($config, $data, $creating) {
    unset($data['leitstellen'], $data['nebenleitstellen']);
    if (isset($data['adresse']) && strlen($data['adresse']) > 500) { throw new InvalidArgumentException('Adresse zu lang'); }
    return $data;
}
function lst_manage_fetch_one($pdo, $resource, $id) { return $pdo->rows[$resource][$id] ?? null; }
function lst_manage_sync_relations($pdo, $config, $id, $data) { $pdo->relations[$id] = array_intersect_key($data, array_flip(['leitstellen', 'nebenleitstellen'])); }

class FakeStatement extends PDOStatement {
    public array $result = [];
    public function __construct(private FakePDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        if (str_contains($this->sql, 'GET_LOCK')) {
            $this->result = [$this->db->held ? 0 : 1]; $this->db->held = true;
        } elseif (str_contains($this->sql, 'RELEASE_LOCK')) { $this->db->held = false; $this->result=[1]; }
        elseif ($this->sql === 'SELECT DATABASE()') { $this->result=['test']; }
        elseif ($this->sql === 'SELECT adresse FROM wachen LIMIT 0') {
            if (!isset($this->db->columns['wachen.adresse'])) { throw new Exception('Unbekannte Spalte adresse'); }
            $this->result=[];
        }
        elseif ($this->sql === 'SELECT id, wache_id, rufname FROM fahrzeuge LIMIT 0') { $this->result=[]; }
        elseif (str_contains($this->sql, 'INFORMATION_SCHEMA.TABLES')) { $this->result=[1]; }
        elseif (str_contains($this->sql, 'INFORMATION_SCHEMA.COLUMNS')) { $this->result=[isset($this->db->columns[$params[0].'.'.$params[1]]) ? 1 : 0]; }
        elseif (preg_match('/^SELECT .* FROM (wachen|fahrzeuge)$/', $this->sql, $m)) { $this->result=array_values($this->db->rows[$m[1]]); }
        elseif (preg_match('/^INSERT INTO (wachen|fahrzeuge) \(([^)]+)\)/', $this->sql, $m)) {
            if (!$this->db->held || !$this->db->tx) { throw new Exception('Insert ohne Sperre/Transaktion'); }
            $id=++$this->db->last;
            $this->db->rows[$m[1]][$id]=array_combine(explode(',', $m[2]),$params)+['id'=>$id];
            $this->db->mutations++;
        } elseif (preg_match('/^UPDATE (wachen|fahrzeuge) SET (.+) WHERE id = \?$/', $this->sql, $m)) {
            $id=array_pop($params);$fields=explode(', ', $m[2]);
            foreach ($fields as $i=>$field) { $this->db->rows[$m[1]][$id][str_replace(' = ?', '', $field)]=$params[$i]; }
            $this->db->mutations++;
        } else { throw new Exception('Unerwartetes SQL: '.$this->sql); }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->result[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->result; }
}
class FakePDO extends PDO {
    public array $rows=['wachen'=>[], 'fahrzeuge'=>[]], $snapshot=[], $columns=[], $relations=[];
    public bool $held=false, $tx=false;
    public int $last=100, $mutations=0, $ddl=0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new FakeStatement($this,$query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $s=$this->prepare($query);$s->execute();return $s; }
    public function beginTransaction(): bool { $this->tx=true;$this->snapshot=$this->rows;return true; }
    public function commit(): bool { $this->tx=false;return true; }
    public function rollBack(): bool { $this->rows=$this->snapshot;$this->tx=false;return true; }
    public function inTransaction(): bool { return $this->tx; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->last; }
    public function exec(string $statement): int|false {
        if (preg_match('/ALTER TABLE `?(\w+)`? ADD(?: COLUMN)? `?(\w+)`?/', $statement, $m)) { $this->columns[$m[1].'.'.$m[2]]=true;$this->ddl++;return 1; }
        throw new Exception('Unerwartetes DDL: '.$statement);
    }
}
require dirname(__DIR__).'/includes/restapi/rest-abgleich.php';
foreach ($hooks['rest_api_init'] as $fn) { $fn(); }
function expect($condition, $message) { if (!$condition) { throw new Exception($message); } }
expect(!$GLOBALS['routes']['/abgleich/aktion']['permission_callback'](), 'Nicht-Admin erlaubt');
$GLOBALS['admin']=true;
expect($GLOBALS['routes']['/abgleich/aktion']['permission_callback'](), 'Admin gesperrt');
$db=new FakePDO();
expect(lst_abgleich_status()['status']===503,'Fehlende Adressmigration als bereit gemeldet');
$db->rows['wachen'][1]=['id'=>1,'name'=>'Feuerwehr Stade','adresse'=>null,'latitude'=>53.6,'longitude'=>9.5,'land'=>'Deutschland','bundesland'=>'Niedersachsen'];
$entry=['action'=>'ADD','freigabe_id'=>str_repeat('a',64),'wache_id'=>1,'beleg'=>'Florian Stade 20/48-01 LF 10 Feuerwehr Stade','quelle'=>'https://feuerwehr-stade.de/fahrzeuge','after'=>['callsign'=>'Florian Stade 20/48-01','type'=>'LF 10']];
$first=lst_abgleich_write(new WP_REST_Request($entry));
expect($first['status']===201, json_encode($first));
expect($db->mutations===1 && !$db->held && !$db->tx, 'Insert/Lock cleanup');
$duplicate=lst_abgleich_write(new WP_REST_Request($entry));
expect($duplicate['status']===409 && $db->mutations===1, 'Doppelter Rufname angelegt');
$db->held=true;
$locked=lst_abgleich_write(new WP_REST_Request($entry));
expect($locked['status']===409 && $db->mutations===1, 'Parallele Sperre umgangen');
$db->held=false;
$db->rows['fahrzeuge'][101]['status']='frei';$db->rows['fahrzeuge'][101]['fms_status']='2';
$update=$entry;$update['action']='UPDATE';$update['vehicle_id']=101;$update['before']=$db->rows['fahrzeuge'][101];$update['after']['type']='LF 20';
$updated=lst_abgleich_write(new WP_REST_Request($update));expect($updated['status']===200, json_encode($updated));
expect(lst_abgleich_write(new WP_REST_Request($update))['status']===409,'Veraltetes Update akzeptiert');
$ad=$entry;$ad['action']='SET_AD';$ad['vehicle_id']=101;$ad['before']=$db->rows['fahrzeuge'][101];$ad['beleg'].=' außer Dienst';
expect(lst_abgleich_write(new WP_REST_Request($ad))['status']===200,'SET_AD gesperrt');
expect($db->rows['fahrzeuge'][101]['status']==='nicht einsatzbereit' && $db->rows['fahrzeuge'][101]['fms_status']==='6','SET_AD nicht umgesetzt');
$db->rows['wachen'][1]['name']='way/123';
$rename=['action'=>'UPDATE_WACHE','freigabe_id'=>str_repeat('b',64),'wache_id'=>1,'before'=>$db->rows['wachen'][1],'after'=>['name'=>'Feuerwehr Stade','adresse'=>'Teststraße 1'],'beleg'=>'Feuerwehr Stade Teststraße 1','quelle'=>'https://feuerwehr-stade.de/kontakt'];
expect(lst_abgleich_write(new WP_REST_Request($rename))['status']===200,'Auflösung gesperrt');
expect(lst_abgleich_write(new WP_REST_Request($rename))['status']===409,'Umbenannte Wache überschrieben');
$create=$rename;$create['action']='ADD_WACHE';$create['after']=['name'=>'Neue Wache','adresse'=>'Teststraße 1','typ'=>'FFW','land'=>'Deutschland','bundesland'=>'Niedersachsen','latitude'=>53.6,'longitude'=>9.5,'leitstellen'=>[1]];
expect(lst_abgleich_write(new WP_REST_Request($create))['status']===409,'Standortdublette angelegt');
$create['after']['latitude']=54.2;
expect(lst_abgleich_write(new WP_REST_Request($create))['status']===201,'Belegte Wachenanlage gesperrt');
expect($db->relations[$db->last]['leitstellen']===[1],'Neue Wache ohne Leitstellenzuordnung');
expect(lst_abgleich_key('Flörian Stade 20/48-01')===lst_abgleich_key('Florian Stade 20-48/01'),'Normalisierung inkonsistent');
require dirname(__DIR__).'/includes/migrations.php';
lsttraining_migration_add_column($db,'wachen','adresse','VARCHAR(500) NULL DEFAULT NULL AFTER name');
lsttraining_migration_add_column($db,'wachen','adresse','VARCHAR(500) NULL DEFAULT NULL AFTER name');
expect($db->ddl===1,'Adressmigration nicht idempotent');
expect(lst_abgleich_status()['status']===200,'Bereites Schema nicht bestätigt');
echo "OK: guarded actions, duplicate/lock conflicts, stale writes, permissions, relations and idempotent address migration\n";
