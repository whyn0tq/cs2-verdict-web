<?php

function open_database($settings, $with_database = true)
{
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers())) {
        fail('pdo_missing', 500);
    }

    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int)$settings['port'];
    if ($with_database) {
        $dsn .= ';dbname=' . $settings['database'];
    }

    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8
    );

    $pdo = new PDO($dsn, $settings['user'], $settings['password'], $options);
    try {
        $pdo->exec('SET NAMES utf8mb4');
    } catch (PDOException $error) {
        $pdo->exec('SET NAMES utf8');
    }
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function db($replace = null)
{
    static $connection = null;
    if ($replace !== null) {
        $connection = $replace;
    }
    if ($connection === null) {
        $connection = open_database(config_value('db'));
    }
    return $connection;
}

function db_query($sql, $params = array())
{
    $statement = db()->prepare($sql);
    $position = 1;
    foreach ($params as $value) {
        if (is_int($value)) {
            $statement->bindValue($position, $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $statement->bindValue($position, null, PDO::PARAM_NULL);
        } elseif (is_bool($value)) {
            $statement->bindValue($position, $value ? 1 : 0, PDO::PARAM_INT);
        } else {
            $statement->bindValue($position, (string)$value, PDO::PARAM_STR);
        }
        $position++;
    }
    $statement->execute();
    return $statement;
}

function db_rows($sql, $params = array())
{
    return db_query($sql, $params)->fetchAll();
}

function db_row($sql, $params = array())
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value($sql, $params = array())
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function db_execute($sql, $params = array())
{
    return db_query($sql, $params)->rowCount();
}

function db_insert_id()
{
    return (int)db()->lastInsertId();
}

function table_prefix()
{
    $prefix = (string)config_value('prefix', 'sb');
    if ($prefix !== '' && substr($prefix, -1) !== '_') {
        $prefix .= '_';
    }
    return $prefix;
}

function table($name)
{
    return '`' . table_prefix() . $name . '`';
}

function placeholders($count)
{
    if ($count < 1) {
        return 'NULL';
    }
    return implode(', ', array_fill(0, $count, '?'));
}

function duplicate_key_error($error)
{
    return $error instanceof PDOException && isset($error->errorInfo[1]) && $error->errorInfo[1] == 1062;
}
