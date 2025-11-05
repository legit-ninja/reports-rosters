<?php
/**
 * Validation Exception
 * 
 * Exception thrown when data validation fails
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
 * Validation Exception Class
 * 
 * Thrown when validation rules are violated
 */
class ValidationException extends \Exception {
    
    /**
     * Validation errors
     * 
     * @var array
     */
    protected $validation_errors = [];
    
    /**
     * Field that failed validation
     * 
     * @var string|null
     */
    protected $failed_field;
    
    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param array $errors Validation errors array
     * @param string|null $failed_field Field that failed
     */
    public function __construct(
        $message = "Validation failed",
        $code = 0,
        \Throwable $previous = null,
        array $errors = [],
        $failed_field = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->validation_errors = $errors;
        $this->failed_field = $failed_field;
    }
    
    /**
     * Get validation errors
     * 
     * @return array
     */
    public function getValidationErrors() {
        return $this->validation_errors;
    }
    
    /**
     * Get failed field
     * 
     * @return string|null
     */
    public function getFailedField() {
        return $this->failed_field;
    }
    
    /**
     * Check if has validation errors
     * 
     * @return bool
     */
    public function hasValidationErrors() {
        return !empty($this->validation_errors);
    }
}

