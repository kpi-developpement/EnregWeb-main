<?php
class Database
{
    private static $instance;
    private $db;

    private function __construct()
    {
        $user = getenv("DB_USER") ?: "kpi";
        $pass = getenv("DB_PASSWORD") ?: "KPIuser2024.";
        $port = (int)(getenv("DB_PORT") ?: 3306);
        $database = getenv("DB_NAME") ?: "enregistrement_audio";
        $host = getenv("DB_HOST") ?: "10.10.10.55";

        try {
            $this->db = new PDO(
                "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH, // garde le comportement actuel (numérique + assoc)
                ]
            );
        } catch (PDOException $e) {
            echo '<h1>Connection failed:</h1> ' . $e->getMessage();
        }
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance->db;
    }

    // ====== Anciennes fonctions (compat) ======
    public static function select($requet)
    {
        try {
            $stmt = self::getInstance()->prepare($requet);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            echo '<h1>Select failed:</h1> ' . $e->getMessage();
            return null;
        }
    }

    public static function selectFETCH_ASSOC($requet)
    {
        try {
            $stmt = self::getInstance()->prepare($requet);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo '<h1>Select failed:</h1> ' . $e->getMessage();
            return null;
        }
    }

    public static function insert($requet)
    {
        try {
            $stmt = self::getInstance()->prepare($requet);
            $stmt->execute();
            return 1;
        } catch (PDOException $e) {
            return 0;
        }
    }

    public static function delete($requet)
    {
        try {
            $stmt = self::getInstance()->prepare($requet);
            $stmt->execute();
            return 1;
        } catch (PDOException $e) {
            return 0;
        }
    }

    public static function update($requet)
    {
        try {
            $stmt = self::getInstance()->prepare($requet);
            $stmt->execute();
            return 1;
        } catch (PDOException $e) {
            echo '{ "messageDB" :' . $e->getMessage() . '},';
            return 0;
        }
    }

    // ====== Nouvelles fonctions (sécurisées, sans casser l'existant) ======
    public static function selectParams($sql, $params = [], $fetchMode = PDO::FETCH_BOTH)
    {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll($fetchMode);
        } catch (PDOException $e) {
            echo '<h1>Select failed:</h1> ' . $e->getMessage();
            return null;
        }
    }

    public static function executeParams($sql, $params = [])
    {
        try {
            $stmt = self::getInstance()->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            return false;
        }
    }

    // ====== Auth (corrigée + inclut is_admin) ======
    public static function auth($username, $pass)
    {
        try {
            $password = md5($pass);

            // On garde l'idée "username OR email", mais en requête préparée (plus sûr)
            $sql = "SELECT *
                    FROM agent
                    WHERE (username = :u OR email = :u)
                      AND password = :p
                    LIMIT 1";

            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute([
                ':u' => $username,
                ':p' => $password
            ]);

            $result = $stmt->fetch(); // FETCH_BOTH (comme avant)
            return $result ? $result : false;
        } catch (PDOException $e) {
            echo '<h1>Auth failed:</h1> ' . $e->getMessage();
            return false;
        }
    }
}
?>
