<?php
class Database {
    private static ?PDO $instance = null;
    
    private const DB_HOST = 'localhost';
    private const DB_NAME = 'u82669';
    private const DB_USER = 'u82669';
    private const DB_PASS = '9085380';
    
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                self::$instance = new PDO(
                    'mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8mb4',
                    self::DB_USER,
                    self::DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                throw new RuntimeException('Ошибка подключения к базе данных');
            }
        }
        return self::$instance;
    }
    
    private function __construct() {}
    private function __clone() {}
}