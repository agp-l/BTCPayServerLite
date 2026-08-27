<?php
// BTCPayLite/classes/Database.php
declare(strict_types=1);
namespace BtcPayLite;

use PDO;
use PDOException;
use Exception;

class Database
{
    private PDO $pdo;

    public function __construct(string $host, string $dbname, string $user, string $pass)
    {
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new Exception("Kritická chyba: Nelze se připojit k databázi.");
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}