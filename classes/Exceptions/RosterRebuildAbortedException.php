<?php
/**
 * Signals an abort_run roster delete failure during a transactional rebuild.
 *
 * @package InterSoccer\ReportsRosters\Exceptions
 */

namespace InterSoccer\ReportsRosters\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown when a rebuild-scoped obsolete-row delete fails and the run must roll back.
 */
class RosterRebuildAbortedException extends \Exception {
}
