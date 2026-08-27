<?php

namespace justinholtweb\passer\sources;

use yii\base\Exception;

/**
 * Raised when an importer asks a source for something the source mode cannot supply.
 *
 * This should never surface to a user: the planner consults SourceCapabilities first and refuses
 * to schedule a phase the source cannot serve. Reaching this exception means a capability
 * declaration and an implementation have drifted apart.
 */
class UnsupportedOperationException extends Exception
{
    public function getName(): string
    {
        return 'Unsupported source operation';
    }
}
