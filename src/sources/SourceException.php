<?php

namespace justinholtweb\passer\sources;

use yii\base\Exception;

/**
 * Raised when a source cannot be reached, authenticated to, or read.
 */
class SourceException extends Exception
{
    public function getName(): string
    {
        return 'Passer source error';
    }
}
