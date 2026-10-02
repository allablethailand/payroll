<?php
declare(strict_types=1);

/** A rule an import row broke; getErrorKey() is the stable machine key, the message is "key: readable sentence". */
class ImportRowValidationException extends InvalidArgumentException {
    private string $errorKey;

    public function __construct(string $errorKey, string $message) {
        parent::__construct("{$errorKey}: {$message}");
        $this->errorKey = $errorKey;
    }

    public function getErrorKey(): string {
        return $this->errorKey;
    }
}
