<?php
namespace App\Libraries;

class Crypto_helper {

    private function decryptExpr(string $element): string
    {
        $vectorExpr = strpos($element, '.') !== false
            ? substr($element, 0, strpos($element, '.') + 1) . 'vector_id'
            : 'vector_id';

        return "CONVERT(CAST(AES_DECRYPT(UNHEX(" . $element . "),@key_str," . $vectorExpr . ") AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    // Funzione per l'escape dei caratteri speciali
    public function escapeSpecialCharacters($string) {
        $search = array(
            "\\",  // Backslash
            "\x00", // Null byte
            "\n",   // Newline
            "\r",   // Carriage return
            "'",    // Single quote
            "\"",   // Double quote
            "\x1a", // ASCII 26 - Substituite
            "\x08", // Backspace
            "\t",   // Tab
        );

        $replace = array(
            "\\\\", // Doppio backslash
            "\\0",  // Escape null byte
            "\\n",  // Escape newline
            "\\r",  // Escape carriage return
            "\\'",  // Escape single quote
            "\\\"", // Escape double quote
            "\\Z",  // Escape ASCII 26
            "\\b",  // Escape backspace
            "\\t",  // Escape tab
        );

        return str_replace($search, $replace, $string);
    }

    // Funzione per crittografare l'elemento
    public function encrypt($element) {
        try {
            return "HEX(AES_ENCRYPT('".$this->escapeSpecialCharacters($element)."',@key_str,@init_vector))";
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    // Funzione per crittografare l'elemento (insert)
    public function encrypt_insert($element) {
        try {
            return "HEX(AES_ENCRYPT(".$element.",@key_str,@init_vector))";
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    // Funzione per crittografare (select)
    public function encrypt_select($element) {
        try {
            return "HEX(AES_ENCRYPT(:".$element.":,@key_str,vector_id))";
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    public function encrypt_select_login($element) {
        try {
            return "HEX(AES_ENCRYPT(".$element.",@key_str,vector_id))";
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    public function encrypt_select_pulito($element) {
        try {
            return "HEX(AES_ENCRYPT('".$element."',@key_str,vector_id))";
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    // Funzione per decrittografare l'elemento
    public function decrypt($element) {
        try {
            return $this->decryptExpr($element) . " as " . substr($element, strpos($element, ".") + 1);
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    public function decryptSenzaAlias($element) {
        try {
            return $this->decryptExpr($element);
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    // Funzione per decrittografare (concat)
    public function decrypt_concat($element) {
        try {
            return $this->decryptExpr($element);
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }

    // Funzione per decrittografare (insert)
    public function decrypt_insert($element) {
        try {
            return $this->decryptExpr($element);
        } catch (Exception $e) {
            log_message('error', "ERRORE ".$e->getMessage()); // Utilizzo del sistema di log di CodeIgniter
        }
    }
}
?>
