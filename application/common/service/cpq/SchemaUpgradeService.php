<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use think\Config;

/**
 * CPQ 增量升级执行服务（GYTAI-67）。
 *
 * 约定（database/cpq/upgrades/README.md）：
 *  - 升级脚本按文件名序（YYYYMMDDXX_说明.sql）只增不改；
 *  - 每个脚本必须在空库（先执行 install.sql）和已有库上可执行；
 *  - 执行记录写入 {prefix}cpq_migration，cpq:upgrade 只应用未执行的脚本。
 *
 * 本类直接持有 PDO 连接，命令行与升级测试可共用同一逻辑；
 * 空库安装（php think install）在全新建库后把现有脚本标记为已执行，
 * 因为 install.sql 始终携带最终结构。
 */
class SchemaUpgradeService
{
    /** @var string 升级脚本目录 */
    private $upgradeDir;

    /** @var string 追踪表物理名（含前缀） */
    private $migrationTable;

    /** @var string 数据库表前缀 */
    private $prefix;

    public function __construct($upgradeDir = null, $prefix = null)
    {
        $this->upgradeDir = $upgradeDir ?: ROOT_PATH . 'database' . DS . 'cpq' . DS . 'upgrades';
        if (!is_dir($this->upgradeDir)) {
            throw new RuntimeException('CPQ 升级脚本目录不存在：' . $this->upgradeDir);
        }
        $this->prefix = $prefix !== null ? (string)$prefix : (string)Config::get('database.prefix');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $this->prefix)) {
            throw new InvalidArgumentException('数据库表前缀不合法');
        }
        $this->migrationTable = '`' . $this->prefix . 'cpq_migration`';
    }

    /**
     * 已执行的脚本名列表。
     *
     * @param PDO $pdo
     * @return array
     */
    public function appliedScripts(PDO $pdo)
    {
        $this->ensureMigrationTable($pdo);
        $rows = $pdo->query('SELECT `name` FROM ' . $this->migrationTable)->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    /**
     * 待执行的脚本名列表（按文件名序）。
     *
     * @param PDO $pdo
     * @return array
     */
    public function pendingScripts(PDO $pdo)
    {
        $applied = array_flip($this->appliedScripts($pdo));
        $pending = [];
        foreach ($this->scriptFiles() as $name) {
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }
        return $pending;
    }

    /**
     * 应用全部待执行脚本；每个脚本成功后立即记录，失败抛出异常并停止。
     *
     * @param PDO           $pdo
     * @param callable|null $onApplied function(string $name) 进度回调
     * @return array 已应用的脚本名
     * @throws RuntimeException
     */
    public function applyAll(PDO $pdo, callable $onApplied = null)
    {
        $applied = [];
        foreach ($this->pendingScripts($pdo) as $name) {
            $sql = file_get_contents($this->upgradeDir . DS . $name);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('升级脚本为空：' . $name);
            }
            $sql = str_replace('__PREFIX__', $this->prefix, $sql);
            try {
                $pdo->exec($sql);
            } catch (\Throwable $exception) {
                throw new RuntimeException(sprintf(
                    '升级脚本 %s 执行失败：%s（请从备份恢复后重试，见 database/cpq/README.md）',
                    $name,
                    $exception->getMessage()
                ));
            }
            $this->recordApplied($pdo, $name);
            $applied[] = $name;
            if ($onApplied) {
                $onApplied($name);
            }
        }
        return $applied;
    }

    /**
     * 空库安装后把现有脚本全部标记为已执行（install.sql 即最终结构）。
     *
     * @param PDO $pdo
     * @return array
     */
    public function markAllApplied(PDO $pdo)
    {
        $this->ensureMigrationTable($pdo);
        $marked = [];
        foreach ($this->scriptFiles() as $name) {
            $this->recordApplied($pdo, $name);
            $marked[] = $name;
        }
        return $marked;
    }

    /**
     * 升级脚本文件名列表（排序后）。
     *
     * @return array
     */
    public function scriptFiles()
    {
        $files = glob($this->upgradeDir . DS . '*.sql');
        if (!$files) {
            return [];
        }
        sort($files);
        return array_map('basename', $files);
    }

    /**
     * 记录脚本已执行（幂等）。
     *
     * @param PDO    $pdo
     * @param string $name
     */
    private function recordApplied(PDO $pdo, $name)
    {
        $statement = $pdo->prepare('INSERT IGNORE INTO ' . $this->migrationTable . ' (`name`, `applied_at`) VALUES (?, ?)');
        $statement->execute([$name, time()]);
    }

    /**
     * 确保升级记录表存在（对 M0 基线库执行 cpq:upgrade 时自动补建）。
     *
     * @param PDO $pdo
     */
    private function ensureMigrationTable(PDO $pdo)
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->migrationTable . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'ID\','
            . '`name` VARCHAR(190) NOT NULL COMMENT \'升级脚本文件名\','
            . '`applied_at` INT UNSIGNED NULL COMMENT \'执行时间\','
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uk_cpq_migration_name` (`name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'CPQ增量升级执行记录\''
        );
    }
}
