<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

/**
 * Versioned migration runner (CONTRATOS.md §6).
 *
 * The .sql files live in the oficina-infra-fundacao repository and are mounted/copied into
 * MIGRATIONS_PATH; this runner only applies them, in lexicographic order, recording each
 * applied file in schema_migrations so a second run is a no-op.
 *
 * Files whose name ends in _demo.sql carry demonstration data and are skipped in production.
 */
final class MigrationRunner
{
    public const TABLE = 'schema_migrations';

    /** @var \Closure(string): void */
    private readonly \Closure $output;

    /** @param (\Closure(string): void)|null $output line printer, defaults to echo */
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $skipDemo = false,
        ?\Closure $output = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
            echo $line . "\n";
        };
    }

    /**
     * Applies every pending migration found in $directory.
     *
     * @return array<int, string> versions applied in this run (empty when already up to date)
     */
    public function run(string $directory): array
    {
        $this->ensureControlTable();

        $applied = [];

        foreach ($this->pendingFiles($directory) as $version => $path) {
            ($this->output)("-> aplicando {$version}");
            $this->applyFile($path);
            $this->markApplied($version);
            $applied[] = $version;
        }

        if ($applied === []) {
            ($this->output)('Nenhuma migration pendente.');
        }

        return $applied;
    }

    /** @return array<string, string> version => absolute path, in lexicographic order */
    private function pendingFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("Diretório de migrations não encontrado: {$directory}");
        }

        $files = glob(rtrim($directory, '/') . '/*.sql');

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $alreadyApplied = $this->appliedVersions();
        $pending        = [];

        foreach ($files as $path) {
            $version = basename($path);

            if ($this->skipDemo && str_ends_with($version, '_demo.sql')) {
                ($this->output)("-> ignorando {$version} (dados de demonstração, APP_ENV=production)");
                continue;
            }

            if (in_array($version, $alreadyApplied, true)) {
                continue;
            }

            $pending[$version] = $path;
        }

        return $pending;
    }

    private function applyFile(string $path): void
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new \RuntimeException("Não foi possível ler a migration: {$path}");
        }

        foreach ($this->splitStatements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * Statements are terminated by a semicolon at end of line (no DELIMITER blocks, per contract).
     *
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $parts      = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $clean = trim($part);

            if ($clean === '' || $this->isOnlyComments($clean)) {
                continue;
            }

            $statements[] = $clean;
        }

        return $statements;
    }

    private function isOnlyComments(string $statement): bool
    {
        foreach (preg_split('/\r?\n/', $statement) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '#')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public function appliedVersions(): array
    {
        $stmt = $this->pdo->query('SELECT version FROM ' . self::TABLE . ' ORDER BY version');

        if ($stmt === false) {
            return [];
        }

        return array_map(static fn ($v): string => (string)$v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function markApplied(string $version): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (version) VALUES (:version)');
        $stmt->execute([':version' => $version]);
    }

    private function ensureControlTable(): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // SQLite (used by the test suite) understands neither DATETIME(3) nor ENGINE=InnoDB.
        $sql = $driver === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                   version    VARCHAR(255) NOT NULL PRIMARY KEY,
                   applied_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
               ) ENGINE=InnoDB'
            : 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                   version    TEXT NOT NULL PRIMARY KEY,
                   applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
               )';

        $this->pdo->exec($sql);
    }
}
