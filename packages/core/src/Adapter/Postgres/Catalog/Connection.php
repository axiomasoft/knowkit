<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use KnowKit\Domain\{DomainFailure, Outcome};
use PDO;

/** Bound schema, bound values. No SQL predicate/identifier from an API request. */
final readonly class Connection
{
    public function __construct(public PDO $pdo, public string $schema)
    {
        self::schema($schema);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \InvalidArgumentException('PostgreSQL required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $role = $pdo->query('SELECT rolsuper FROM pg_roles WHERE rolname=current_user');
        if ($role === false || $role->fetchColumn()) {
            throw new DomainFailure(Outcome::Denied, 'privileged_database_role');
        }
    }

    public static function schema(string $schema): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $schema) !== 1) {
            throw new \InvalidArgumentException('Safe schema required');
        }
    }

    public function table(string $name): string
    {
        return '"' . $this->schema . '".' . $name;
    }

    public function execute(string $sql, array $values = []): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            foreach (array_values($values) as $i => $value) {
                $type = match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                };
                $statement->bindValue($i + 1, $value, $type);
            }
            $statement->execute();
            return $statement;
        } catch (\PDOException $error) {
            // Never propagate SQL detail (bound metadata, host refs or credentials).
            if ($error->getCode() === '55P03') {
                throw new DomainFailure(Outcome::Unavailable, 'RETRYABLE_LOCK_TIMEOUT');
            }
            $conflict = str_starts_with((string) $error->getCode(), '23');
            throw new DomainFailure(
                $conflict ? Outcome::Incompatible : Outcome::Unavailable,
                $conflict ? 'storage_conflict' : 'storage_unavailable',
            );
        }
    }

    public function row(string $sql, array $values = []): ?array
    {
        $row = $this->execute($sql, $values)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
