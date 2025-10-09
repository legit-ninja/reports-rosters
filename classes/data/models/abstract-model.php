<?php
/**
 * Abstract Model Class
 * 
 * Base model class for all data models in InterSoccer Reports & Rosters.
 * Provides common functionality like validation, serialization, and attribute access.
 * 
 * @package InterSoccer\ReportsRosters\Data\Models
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Models;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Utils\ValidationHelper;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Model Class
 * 
 * Base class for all data models providing common functionality
 */
abstract class AbstractModel {
    
    /**
     * Model attributes
     * 
     * @var array
     */
    protected $attributes = [];
    
    /**
     * Original attributes (for change detection)
     * 
     * @var array
     */
    protected $original = [];
    
    /**
     * Fillable attributes
     * 
     * @var array
     */
    protected $fillable = [];
    
    /**
     * Hidden attributes (not included in serialization)
     * 
     * @var array
     */
    protected $hidden = [];
    
    /**
     * Validation rules
     * 
     * @var array
     */
    protected $validation_rules = [];
    
    /**
     * Attribute casting rules
     * 
     * @var array
     */
    protected $casts = [];
    
    /**
     * Date format for date attributes
     * 
     * @var string
     */
    protected $date_format = 'Y-m-d';
    
    /**
     * DateTime format for datetime attributes
     * 
     * @var string
     */
    protected $datetime_format = 'Y-m-d H:i:s';
    
    /**
     * Primary key field name
     * 
     * @var string
     */
    protected $primary_key = 'id';
    
    /**
     * Whether model exists in database
     * 
     * @var bool
     */
    protected $exists = false;
    
    /**
     * Constructor
     * 
     * @param array $attributes Initial attributes
     */
    public function __construct(array $attributes = []) {
        $this->fill($attributes);
        $this->syncOriginal();
    }
    
    /**
     * Fill model with attributes
     * 
     * @param array $attributes Attributes to fill
     * @return self
     */
    public function fill(array $attributes) {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        
        return $this;
    }
    
    /**
     * Check if attribute is fillable
     * 
     * @param string $key Attribute key
     * @return bool Is fillable
     */
    protected function isFillable($key) {
        // If no fillable array is defined, allow all
        if (empty($this->fillable)) {
            return true;
        }
        
        return in_array($key, $this->fillable);
    }
    
    /**
     * Set attribute value
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function setAttribute($key, $value) {
        // Check if there's a mutator method
        $mutator = 'set' . ucfirst(camelCase($key)) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $value = $this->$mutator($value);
        }
        
        // Cast the value if needed
        $this->attributes[$key] = $this->castAttribute($key, $value);
    }
    
    /**
     * Get attribute value
     * 
     * @param string $key Attribute key
     * @return mixed Attribute value
     */
    public function getAttribute($key) {
        // Check if there's an accessor method
        $accessor = 'get' . ucfirst(camelCase($key)) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor($this->attributes[$key] ?? null);
        }
        
        return $this->attributes[$key] ?? null;
    }
    
    /**
     * Cast attribute to appropriate type
     * 
     * @param string $key Attribute key
     * @param mixed $value Raw value
     * @return mixed Casted value
     */
    protected function castAttribute($key, $value) {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }
        
        $cast_type = $this->casts[$key];
        
        switch ($cast_type) {
            case 'int':
            case 'integer':
                return (int) $value;
                
            case 'float':
            case 'double':
                return (float) $value;
                
            case 'string':
                return (string) $value;
                
            case 'bool':
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                
            case 'array':
                return is_array($value) ? $value : json_decode($value, true);
                
            case 'json':
                return is_string($value) ? json_decode($value, true) : $value;
                
            case 'date':
                return $this->asDate($value);
                
            case 'datetime':
                return $this->asDateTime($value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Convert value to date string
     * 
     * @param mixed $value Date value
     * @return string|null Formatted date
     */
    protected function asDate($value) {
        if (empty($value)) {
            return null;
        }
        
        if (is_numeric($value)) {
            return date($this->date_format, $value);
        }
        
        $timestamp = strtotime($value);
        return $timestamp ? date($this->date_format, $timestamp) : null;
    }
    
    /**
     * Convert value to datetime string
     * 
     * @param mixed $value DateTime value
     * @return string|null Formatted datetime
     */
    protected function asDateTime($value) {
        if (empty($value)) {
            return null;
        }
        
        if (is_numeric($value)) {
            return date($this->datetime_format, $value);
        }
        
        $timestamp = strtotime($value);
        return $timestamp ? date($this->datetime_format, $timestamp) : null;
    }
    
    /**
     * Validate model attributes
     * 
     * @throws ValidationException If validation fails
     * @return bool Validation passed
     */
    public function validate() {
        if (empty($this->validation_rules)) {
            return true;
        }
        
        $validator = new ValidationHelper();
        $errors = [];
        
        foreach ($this->validation_rules as $field => $rules) {
            $value = $this->getAttribute($field);
            $field_errors = $validator->validate_field($field, $value, $rules);
            
            if (!empty($field_errors)) {
                $errors[$field] = $field_errors;
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Model validation failed', $errors);
        }
        
        return true;
    }
    
    /**
     * Check if model is valid
     * 
     * @return bool Is valid
     */
    public function isValid() {
        try {
            return $this->validate();
        } catch (ValidationException $e) {
            return false;
        }
    }
    
    /**
     * Get validation errors
     * 
     * @return array Validation errors
     */
    public function getValidationErrors() {
        try {
            $this->validate();
            return [];
        } catch (ValidationException $e) {
            return $e->getErrors();
        }
    }
    
    /**
     * Check if model has been changed
     * 
     * @param string|null $key Specific attribute key
     * @return bool Has changes
     */
    public function isDirty($key = null) {
        if ($key !== null) {
            return isset($this->attributes[$key]) && 
                   $this->attributes[$key] !== ($this->original[$key] ?? null);
        }
        
        foreach ($this->attributes as $attr_key => $value) {
            if ($value !== ($this->original[$attr_key] ?? null)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get changed attributes
     * 
     * @return array Changed attributes
     */
    public function getDirtyAttributes() {
        $dirty = [];
        
        foreach ($this->attributes as $key => $value) {
            if ($value !== ($this->original[$key] ?? null)) {
                $dirty[$key] = $value;
            }
        }
        
        return $dirty;
    }
    
    /**
     * Sync current attributes as original
     * 
     * @return self
     */
    public function syncOriginal() {
        $this->original = $this->attributes;
        return $this;
    }
    
    /**
     * Get primary key value
     * 
     * @return mixed Primary key value
     */
    public function getKey() {
        return $this->getAttribute($this->primary_key);
    }
    
    /**
     * Get primary key name
     * 
     * @return string Primary key name
     */
    public function getKeyName() {
        return $this->primary_key;
    }
    
    /**
     * Set primary key value
     * 
     * @param mixed $value Primary key value
     * @return self
     */
    public function setKey($value) {
        $this->setAttribute($this->primary_key, $value);
        return $this;
    }
    
    /**
     * Check if model exists in database
     * 
     * @return bool Exists in database
     */
    public function exists() {
        return $this->exists;
    }
    
    /**
     * Mark model as existing in database
     * 
     * @param bool $exists Exists status
     * @return self
     */
    public function setExists($exists = true) {
        $this->exists = $exists;
        return $this;
    }
    
    /**
     * Convert model to array
     * 
     * @return array Model as array
     */
    public function toArray() {
        $array = [];
        
        foreach ($this->attributes as $key => $value) {
            // Skip hidden attributes
            if (in_array($key, $this->hidden)) {
                continue;
            }
            
            // Use accessor if available
            $array[$key] = $this->getAttribute($key);
        }
        
        return $array;
    }
    
    /**
     * Convert model to JSON
     * 
     * @param int $options JSON encode options
     * @return string JSON representation
     */
    public function toJson($options = 0) {
        return json_encode($this->toArray(), $options);
    }
    
    /**
     * Create model from array
     * 
     * @param array $attributes Attributes
     * @return static New model instance
     */
    public static function make(array $attributes = []) {
        return new static($attributes);
    }
    
    /**
     * Magic getter
     * 
     * @param string $key Attribute key
     * @return mixed Attribute value
     */
    public function __get($key) {
        return $this->getAttribute($key);
    }
    
    /**
     * Magic setter
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function __set($key, $value) {
        $this->setAttribute($key, $value);
    }
    
    /**
     * Magic isset
     * 
     * @param string $key Attribute key
     * @return bool Attribute is set
     */
    public function __isset($key) {
        return isset($this->attributes[$key]);
    }
    
    /**
     * Magic unset
     * 
     * @param string $key Attribute key
     * @return void
     */
    public function __unset($key) {
        unset($this->attributes[$key]);
    }
    
    /**
     * Magic toString
     * 
     * @return string JSON representation
     */
    public function __toString() {
        return $this->toJson();
    }
}

/**
 * Convert snake_case to camelCase
 * 
 * @param string $string Snake case string
 * @return string Camel case string
 */
function camelCase($string) {
    return lcfirst(str_replace('_', '', ucwords($string, '_')));
}