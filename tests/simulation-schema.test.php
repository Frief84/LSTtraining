<?php
define('ABSPATH', __DIR__ . '/');
define('LSTTRAINING_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
function add_action(...$args): void {}
require_once LSTTRAINING_PATH . 'includes/ajax/ajax_simulation.php';

// Model a connection with native prepares: SHOW ... LIKE ? fails at prepare.
class SchemaProbePDO extends PDO {
    public array $tables = ['leitstelle_nebenleitstellen', 'instanz_user'];
    public array $columns = ['instanz_user' => ['connected']];
    public bool $fail = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->fail) {
            throw new PDOException('Metadata connection failed');
        }
        if (strpos($query, 'SHOW ') === 0) {
            throw new PDOException('Native SHOW placeholder is not supported');
        }
        if (strpos($query, 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?') === false) {
            throw new LogicException('Metadata lookup must be scoped to the current database and exact table name');
        }
        return new SchemaProbeStatement($this, strpos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false);
    }
}
class SchemaProbeStatement extends PDOStatement {
    private SchemaProbePDO $db;
    private bool $columnQuery;
    private int $count = 0;
    public function __construct(SchemaProbePDO $db, bool $columnQuery) {
        $this->db = $db;
        $this->columnQuery = $columnQuery;
    }
    public function execute(?array $params = null): bool {
        $this->count = (int) ($this->columnQuery
            ? in_array($params[1], $this->db->columns[$params[0]] ?? [], true)
            : in_array($params[0], $this->db->tables, true));
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return (string) $this->count; }
}
function check_schema(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$db = new SchemaProbePDO();
check_schema(lsttraining_sim_table_exists($db, 'leitstelle_nebenleitstellen'), 'Existing relation table must be detected');
check_schema(!lsttraining_sim_table_exists($db, 'missing_table'), 'Missing table must return false');
check_schema(!lsttraining_sim_table_exists($db, 'instanz%'), 'Table wildcard must be literal');
check_schema(!lsttraining_sim_table_exists($db, 'instanz_user%'), 'Underscores and percent must not expand');
check_schema(lsttraining_sim_column_exists($db, 'instanz_user', 'connected'), 'Existing column must be detected');
check_schema(!lsttraining_sim_column_exists($db, 'instanz_user', 'missing'), 'Missing column must return false');
check_schema(!lsttraining_sim_column_exists($db, 'other_table', 'connected'), 'Column must belong to the requested table');
check_schema(!lsttraining_sim_column_exists($db, 'instanz_user', 'connect%'), 'Column wildcard must be literal');
$db->fail = true;
foreach ([fn() => lsttraining_sim_table_exists($db, 'instanz_user'), fn() => lsttraining_sim_column_exists($db, 'instanz_user', 'connected')] as $probe) {
    try { $probe(); throw new RuntimeException('Database error was swallowed'); }
    catch (PDOException $e) { check_schema($e->getMessage() === 'Metadata connection failed', 'Original database failure must propagate'); }
}
$db->fail = false;
$db->tables = [];
try {
    lsttraining_sim_ensure_neighbor_schema($db);
    throw new RuntimeException('Genuinely missing relation table must block bootstrap');
} catch (RuntimeException $e) {
    check_schema(strpos($e->getMessage(), 'leitstelle_nebenleitstellen fehlt') !== false, 'Missing schema error must remain actionable');
}
$db->tables = ['leitstelle_nebenleitstellen'];
lsttraining_sim_ensure_neighbor_schema($db);
echo "OK: simulation metadata checks and neighbor bootstrap guard\n";
