<?php

// SELECT: decripta una colonna (usa vector_id della stessa tabella)
function decrypt_col_expr(string $alias, string $col, ?string $as = null): string {
    $as = $as ?? $col;
    return "CONVERT(CAST(AES_DECRYPT(UNHEX({$alias}.{$col}),@key_str,{$alias}.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4) AS {$as}";
}

// SELECT (senza alias): utile in CONCAT, ecc.
function decrypt_col_raw(string $alias, string $col): string {
    return "CONVERT(CAST(AES_DECRYPT(UNHEX({$alias}.{$col}),@key_str,{$alias}.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
}

// INSERT/UPDATE: cifra un parametro bindato (sicuro)
function encrypt_bind(string $param): string {
    return "HEX(AES_ENCRYPT(:{$param}:,@key_str,@init_vector))";
}
