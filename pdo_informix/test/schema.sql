-- Tabela cu rezultatele rulărilor de teste (Informix 14.10)
-- Se creează automat și de report.php, fișierul este aici ca referință.

CREATE TABLE teste (
    id            SERIAL            NOT NULL PRIMARY KEY,
    run_id        CHAR(36)          NOT NULL,
    executed_at   DATETIME YEAR TO SECOND NOT NULL,
    test_name     VARCHAR(64)       NOT NULL,
    result        CHAR(6),
    assertions    SMALLINT,
    failures      SMALLINT,
    exit_code     SMALLINT,
    duration_ms   INTEGER,
    os_name       VARCHAR(32),
    php_version   VARCHAR(32),
    ext_version   VARCHAR(16),
    server_name   VARCHAR(96),
    db_name       VARCHAR(32),
    os_user       VARCHAR(32),
    message       LVARCHAR(1024)
) IN datadbs;
