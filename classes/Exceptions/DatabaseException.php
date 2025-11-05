<?php
/**
 * Database Exception
 * 
 * Exception thrown when database operations fail
 * 
 * @package InterSoccer\ReportsRosters\Exceptions
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Exceptions;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Exception Class
 * 
 * Thrown when database operations fail
 */
class DatabaseException extends \Exception {
    
    /**
     * SQL error message
     * 
     * @var string
     */
    protected $sql_error;
    
    /**
     * SQL state code
     * 
     * @var string
     */
    protected $sql_state;
    
    /**
     * Failed query
     * 
     * @var string|null
     */
    protected $failed_query;
    
    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string $sql_error SQL error message
     * @param string $sql_state SQL state code
     * @param string|null $failed_query The query that failed
     */
    public function __construct(
        $message = "Database operation failed",
        $code = 0,
        \Throwable $previous = null,
        $sql_error = '',
        $sql_state = '',
        $failed_query = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql_error = $sql_error;
        $this->sql_state = $sql_state;
        $this->failed_query = $failed_query;
    }
    
    /**
     * Get SQL error
     * 
     * @return string
     */
    public function getSqlError() {
        return $this->sql_error;
    }
    
    /**
     * Get SQL state
     * 
     * @return string
     */
    public function getSqlState() {
        return $this->sql_state;
    }
    
    /**
     * Get failed query
     * 
     * @return string|null
     */
    public function getFailedQuery() {
        return $this->failed_query;
    }
}

