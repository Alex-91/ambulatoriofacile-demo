<?php
namespace App\Services\Pacs;

/** Only these messages may be shown to operators; never include upstream bodies or URLs. */
class PacsException extends \RuntimeException {}
