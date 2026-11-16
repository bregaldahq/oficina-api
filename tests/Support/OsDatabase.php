<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Database\PdoConnection;
use PDO;

/**
 * O banco do serviço de OS em SQLite, para os testes de integração, de aplicação e o BDD: as
 * tabelas que o código usa, com as colunas das migrations da fundação (001 a 004). O checker
 * de contratos do repositório principal (MIG-2) avisa quando uma coluna nova não entra aqui.
 */
final class OsDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach ([
            "CREATE TABLE customers (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, document TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT 'ACTIVE', email TEXT NULL, phone TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )",
            'CREATE TABLE vehicles (
                id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customers(id),
                license_plate TEXT NOT NULL UNIQUE, brand TEXT NOT NULL, model TEXT NOT NULL, year INTEGER NOT NULL
            )',
            "CREATE TABLE service_orders (
                id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customers(id),
                vehicle_id TEXT NOT NULL REFERENCES vehicles(id), status TEXT NOT NULL DEFAULT 'RECEIVED',
                total_amount REAL NOT NULL DEFAULT 0.00, budget_id TEXT NULL, payment_url TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )",
            'CREATE TABLE service_order_status_history (
                id TEXT PRIMARY KEY, service_order_id TEXT NOT NULL, from_status TEXT NULL,
                to_status TEXT NOT NULL, changed_at TEXT NOT NULL, changed_by TEXT NULL
            )',
            "CREATE TABLE sagas (
                id TEXT PRIMARY KEY, os_id TEXT NOT NULL UNIQUE REFERENCES service_orders(id),
                correlation_id TEXT NOT NULL DEFAULT '', state TEXT NOT NULL, step TEXT NOT NULL,
                failed_step TEXT NULL, reason TEXT NULL, data TEXT NOT NULL, pending TEXT NOT NULL,
                history TEXT NOT NULL, deadline_at TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                alerted INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )",
            'CREATE TABLE outbox (
                id TEXT PRIMARY KEY, type TEXT NOT NULL, body TEXT NOT NULL, occurred_at TEXT NOT NULL,
                published_at TEXT NULL
            )',
            'CREATE TABLE inbox (message_id TEXT PRIMARY KEY, processed_at TEXT NOT NULL)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        PdoConnection::setInstance($pdo);

        return $pdo;
    }
}
