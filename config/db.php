<?php
/**
 * Database connection (PostgreSQL via PDO)
 *
 * On Render, the DATABASE_URL environment variable is provided automatically
 * when you link a Neon Postgres database (or you set it manually to the
 * Neon connection string). Locally, it falls back to a local Postgres
 * instance so you can develop without any cloud dependency.
 *
 * Expected DATABASE_URL format (Neon / standard Postgres URI):
 *   postgres://USER:PASSWORD@HOST:PORT/DBNAME?sslmode=require
 */

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $databaseUrl = getenv('DATABASE_URL');

    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);

        if ($parts === false || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
            throw new RuntimeException('DATABASE_URL is set but could not be parsed.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 5432;
        $dbName = ltrim($parts['path'], '/');
        $user = urldecode($parts['user']);
        $pass = urldecode($parts['pass']);

        // Neon requires SSL. Parse sslmode from the query string if present,
        // otherwise default to 'require' since Neon always needs it.
        $sslMode = 'require';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['sslmode'])) {
                $sslMode = $query['sslmode'];
            }
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslMode}";

        // Older libpq builds (notably the one bundled with XAMPP on Windows)
        // don't support SNI, which Neon normally uses to route the connection
        // to the right endpoint. As a fallback, pass the endpoint ID explicitly
        // via the 'options' connection parameter, as recommended by Neon:
        // https://neon.tech/sni
        // The endpoint ID is the first label of the host, e.g. for
        // "ep-wandering-rain-azjeb1ik-pooler.c-3.ap-southeast-1.aws.neon.tech"
        // it's "ep-wandering-rain-azjeb1ik-pooler".
        if (preg_match('/^([a-z0-9-]+)\.(.+\.neon\.tech)$/i', $host, $matches)) {
            $endpointId = $matches[1];
            $dsn .= ";options=endpoint={$endpointId}";
        }
    } else {
        // Local development fallback
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '5432';
        $dbName = getenv('DB_NAME') ?: 'voting_app';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulate prepares client-side rather than using native/named
            // server-side prepared statements. Neon's connection pooler
            // (PgBouncer in transaction-pooling mode) can hand a session a
            // different backend connection between statements, which native
            // prepared statements don't tolerate well — queries can appear
            // to succeed (no PHP exception) without actually persisting.
            // Emulated prepares avoid the server-side prepare/bind protocol
            // entirely, which is the standard fix for this class of issue.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    } catch (PDOException $e) {
        // Never leak connection details/credentials to the client.
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('A server error occurred. Please try again later.');
    }

    return $pdo;
}