<?php
/**
 * Db.php —— PDO 单例 + 查询辅助
 *
 * 【硬约束】全部 SQL 必须经由本类预处理，禁止字符串拼接（SPEC §9.5）。
 * 表名 / 列名仍是拼接的，因此 insert() / update() 只允许传内部字面量，绝不能传用户输入。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // 关闭模拟预处理 → 真正的服务端预处理，杜绝注入（SPEC §9.5）
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // 不暴露连接串（SPEC §10.4「数据库连接失败」）
                ErrorHandler::log(new RuntimeException('数据库连接失败'));
                throw new RuntimeException('数据库连接失败');
            }
        }

        return self::$pdo;
    }

    /**
     * 执行预处理查询。
     * @param array $params 位置参数（0 起）或命名参数
     */
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    /** 取首行首列 */
    public static function val(string $sql, array $params = [])
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    /**
     * 插入一行。$table 与列名来自内部字面量，禁止传入用户输入。
     */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * 按主键更新。
     */
    public static function update(string $table, array $row, string $whereCol, $whereVal): int
    {
        $sets = [];
        $vals = [];
        foreach ($row as $col => $val) {
            $sets[] = '`' . $col . '`=?';
            $vals[] = $val;
        }
        $vals[] = $whereVal;
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE `' . $whereCol . '`=?';
        return self::q($sql, $vals)->rowCount();
    }

    /**
     * 转义字符串字面量 —— **仅供导出 SQL 生成使用**。
     *
     * 这是 SPEC §9.5「禁止字符串拼接」的唯一例外，且例外是安全的：
     * 导出时值全部来自数据库而非请求参数，转义的目的不是防注入，
     * 而是让生成的 .sql 能被重新导入（引号、反斜杠、换行不破坏语法）。
     * 除导出外任何地方都不要用它拼 SQL。
     */
    public static function escape($value): string
    {
        return substr(self::pdo()->quote((string) $value), 1, -1);
    }

    public static function begin(): void    { self::pdo()->beginTransaction(); }
    public static function commit(): void   { self::pdo()->commit(); }
    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /** 表是否存在（供 install.php 判断是否已安装） */
    public static function tableExists(string $table): bool
    {
        try {
            self::q('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
