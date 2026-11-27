<?php

namespace Tests\Feature;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

class MysqlAdminLockTest extends TestCase
{
    public function test_a_second_mysql_transaction_waits_for_the_admin_row_lock(): void
    {
        try {
            $server = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (Throwable) {
            $this->markTestSkipped('MySQL is not available for the lock check.');
        }

        $database = 'lockcheck_'.bin2hex(random_bytes(4));
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $first = null;
        $second = null;
        $after = null;

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ];
            $dsn = 'mysql:host=127.0.0.1;port=3306;dbname='.$database;
            $first = new PDO($dsn, 'root', '', $options);
            $second = new PDO($dsn, 'root', '', $options);

            $first->exec('CREATE TABLE users (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, role varchar(32) NOT NULL) ENGINE=InnoDB');
            $first->exec("INSERT INTO users (role) VALUES ('admin'), ('admin')");

            $lock = "SELECT COUNT(*) AS aggregate FROM users WHERE role = 'admin' ORDER BY id FOR UPDATE";

            $first->beginTransaction();
            $this->assertSame(2, (int) $first->query($lock)->fetchColumn());
            $first->exec("UPDATE users SET role = 'writer' ORDER BY id LIMIT 1");

            $second->exec('SET innodb_lock_wait_timeout = 2');
            $second->beginTransaction();

            try {
                $second->query($lock)->fetchColumn();
                $this->fail('The second transaction read the admin rows while the first lock was open.');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
            }

            $first->commit();

            $after = new PDO($dsn, 'root', '', $options);
            $after->beginTransaction();
            $this->assertSame(1, (int) $after->query($lock)->fetchColumn());
            $after->commit();
        } finally {
            $first = null;
            $second = null;
            $after = null;
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
