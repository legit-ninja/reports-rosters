<?php
/**
 * Database Class
 * 
 * Reliable database operations for the InterSoccer Reports & Rosters plugin.
 * Provides transaction support, error handling, and data validation.
 * 
 * @package InterSoccer\ReportsRosters\Core
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Core;

use InterSoccer\ReportsRosters\Exceptions\DatabaseException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Class
 * 
 * Enhanced WordPress database operations with reliability features
 */
class Database {
    
    /**
     * WordPress database instance
     * 
     * @var \wpdb
     */
    private $wpdb;
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Transaction nesting level
     * 
     * @var int
     */
    private $transaction_level = 0;
    
    /**
     * Table schema definitions
     * 
     * @var array
     */
    private $table_schemas = [];
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        global $wpdb;
        
        $this->wpdb = $wpdb;
        $this->logger = $logger;
        
        $this->init_table_schemas();
        
        $this->logger->debug('Database class initialized');
    }
    
    /**
     * Initialize table schema definitions
     * 
     * @return void
     */
    private function init_table_schemas() {
        $this->table_schemas = [
            'intersoccer_rosters' => [
                'columns' => [
                    'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                    'order_id' => 'int(11) NOT NULL',
                    'order_item_id' => 'int(11) NOT NULL',
                    'variation_id' => 'int(11) DEFAULT NULL',
                    'product_id' => 'int(11) NOT NULL',
                    'customer_id' => 'int(11) NOT NULL',
                    'player_index' => 'int(11) DEFAULT 0',
                    'first_name' => 'varchar(100) DEFAULT NULL',
                    'last_name' => 'varchar(100) DEFAULT NULL',
                    'dob' => 'date DEFAULT NULL',
                    'gender' => 'enum("male","female","other") DEFAULT NULL',
                    'medical_conditions' => 'text DEFAULT NULL',
                    'dietary_needs' => 'text DEFAULT NULL',
                    'emergency_contact' => 'varchar(200) DEFAULT NULL',
                    'emergency_phone' => 'varchar(50) DEFAULT NULL',
                    'parent_email' => 'varchar(200) DEFAULT NULL',
                    'parent_phone' => 'varchar(50) DEFAULT NULL',
                    'event_type' => 'varchar(50) DEFAULT NULL',
                    'activity_type' => 'varchar(50) DEFAULT NULL',
                    'venue' => 'varchar(200) DEFAULT NULL',
                    'age_group' => 'varchar(50) DEFAULT NULL',
                    'start_date' => 'date DEFAULT NULL',
                    'end_date' => 'date DEFAULT NULL',
                    'event_details' => 'longtext DEFAULT NULL',
                    'booking_type' => 'varchar(50) DEFAULT NULL',
                    'selected_days' => 'varchar(200) DEFAULT NULL',
                    'season' => 'varchar(50) DEFAULT NULL',
                    'region' => 'varchar(100) DEFAULT NULL',
                    'city' => 'varchar(100) DEFAULT NULL',
                    'course_day' => 'varchar(20) DEFAULT NULL',
                    'course_times' => 'varchar(50) DEFAULT NULL',
                    'camp_times' => 'varchar(50) DEFAULT NULL',
                    'discount_applied' => 'varchar(100) DEFAULT NULL',
                    'order_status' => 'varchar(50) DEFAULT "completed"',
                    'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => 'id',
                'indexes' => [
                    'order_id_idx' => ['order_id'],
                    'customer_id_idx' => ['customer_id'],
                    'event_type_idx' => ['event_type'],
                    'activity_type_idx' => ['activity_type'],
                    'venue_idx' => ['venue'],
                    'start_date_idx' => ['start_date'],
                    'end_date_idx' => ['end_date'],
                    'order_status_idx' => ['order_status'],
                    'player_lookup_idx' => ['customer_id', 'player_index'],
                    'event_lookup_idx' => ['event_type', 'start_date', 'venue'],
                ],
                'unique_indexes' => [
                    'unique_roster_entry' => ['order_id', 'order_item_id', 'player_index']
                ]
            ],
            'intersoccer_roster_cache' => [
                'columns' => [
                    'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                    'cache_key' => 'varchar(255) NOT NULL',
                    'cache_value' => 'longtext NOT NULL',
                    'expiry' => 'datetime NOT NULL',
                    'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => 'id',
                'indexes' => [
                    'cache_key_idx' => ['cache_key'],
                    'expiry_idx' => ['expiry'],
                ],
                'unique_indexes' => [
                    'unique_cache_key' => ['cache_key']
                ]
            ]
        ];
    }
    
    /**
     * Start database transaction
     * 
     * @return bool Success status
     */
    public function begin_transaction() {
        try {
            if ($this->transaction_level === 0) {
                $result = $this->wpdb->query('START TRANSACTION');
                if ($result === false) {
                    throw new DatabaseException('Failed to start transaction: ' . $this->wpdb->last_error);
                }
                $this->logger->debug('Database transaction started');
            }
            
            $this->transaction_level++;
            $this->logger->debug('Transaction nesting level: ' . $this->transaction_level);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to begin transaction', [
                'error' => $e->getMessage(),
                'level' => $this->transaction_level
            ]);
            return false;
        }
    }
    
    /**
     * Commit database transaction
     * 
     * @return bool Success status
     */
    public function commit_transaction() {
        try {
            if ($this->transaction_level <= 0) {
                $this->logger->warning('Attempt to commit without active transaction');
                return false;
            }
            
            $this->transaction_level--;
            
            if ($this->transaction_level === 0) {
                $result = $this->wpdb->query('COMMIT');
                if ($result === false) {
                    throw new DatabaseException('Failed to commit transaction: ' . $this->wpdb->last_error);
                }
                $this->logger->debug('Database transaction committed');
            }
            
            $this->logger->debug('Transaction nesting level: ' . $this->transaction_level);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to commit transaction', [
                'error' => $e->getMessage(),
                'level' => $this->transaction_level
            ]);
            return false;
        }
    }
    
    /**
     * Rollback database transaction
     * 
     * @return bool Success status
     */
    public function rollback_transaction() {
        try {
            if ($this->transaction_level <= 0) {
                $this->logger->warning('Attempt to rollback without active transaction');
                return false;
            }
            
            $result = $this->wpdb->query('ROLLBACK');
            if ($result === false) {
                throw new DatabaseException('Failed to rollback transaction: ' . $this->wpdb->last_error);
            }
            
            $this->transaction_level = 0;
            $this->logger->info('Database transaction rolled back');
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to rollback transaction', [
                'error' => $e->getMessage(),
                'level' => $this->transaction_level
            ]);
            return false;
        }
    }
    
    /**
     * Execute database operation within transaction
     * 
     * @param callable $callback Operation to execute
     * @return mixed Result of callback or false on error
     */
    public function transaction(callable $callback) {
        if (!$this->begin_transaction()) {
            return false;
        }
        
        try {
            $result = $callback($this);
            
            if ($result === false || $this->wpdb->last_error) {
                throw new DatabaseException('Operation failed: ' . $this->wpdb->last_error);
            }
            
            if (!$this->commit_transaction()) {
                throw new DatabaseException('Failed to commit transaction');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->rollback_transaction();
            $this->logger->error('Transaction failed and rolled back', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Create or update database tables
     * 
     * @return bool Success status
     */
    public function create_tables() {
        try {
            $this->logger->info('Starting table creation/update process');
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $charset_collate = $this->wpdb->get_charset_collate();
            $success = true;
            
            foreach ($this->table_schemas as $table_name => $schema) {
                $full_table_name = $this->wpdb->prefix . $table_name;
                
                // Build CREATE TABLE SQL
                $sql = $this->build_create_table_sql($full_table_name, $schema, $charset_collate);
                
                $this->logger->debug('Creating/updating table: ' . $full_table_name);
                
                if (!$this->table_exists($table_name)) {
                    // Table does not exist: create it directly. dbDelta() only alters existing tables
                    // (it DESCRIBEs first and skips when the table is missing in some code paths).
                    $created = $this->wpdb->query($sql);
                    if ($created === false) {
                        $this->logger->error('Failed to create table: ' . $full_table_name, [
                            'db_error' => $this->wpdb->last_error,
                        ]);
                        $success = false;
                        continue;
                    }
                } else {
                    // Table exists: use dbDelta to apply schema updates
                    $result = dbDelta($sql);
                    if (!empty($this->wpdb->last_error)) {
                        $this->logger->error('dbDelta error for table: ' . $full_table_name, [
                            'db_error' => $this->wpdb->last_error,
                        ]);
                        $success = false;
                        continue;
                    }
                }
                
                // Verify table exists
                if (!$this->table_exists($table_name)) {
                    $this->logger->error('Table creation verification failed: ' . $full_table_name, [
                        'db_error' => $this->wpdb->last_error,
                    ]);
                    $success = false;
                    continue;
                }
                
                // Create additional indexes
                $this->create_table_indexes($full_table_name, $schema);
                
                $this->logger->info('Successfully processed table: ' . $full_table_name);
            }
            
            if ($success) {
                $this->logger->info('All tables created/updated successfully');
            } else {
                $this->logger->warning('Some tables failed to create/update');
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->logger->error('Table creation process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Build CREATE TABLE SQL statement
     * 
     * @param string $table_name Full table name with prefix
     * @param array $schema Table schema definition
     * @param string $charset_collate WordPress charset/collate
     * @return string SQL statement
     */
    private function build_create_table_sql($table_name, array $schema, $charset_collate) {
        $columns = [];
        
        // Add columns
        foreach ($schema['columns'] as $column_name => $column_def) {
            $columns[] = "{$column_name} {$column_def}";
        }
        
        // Add primary key
        if (isset($schema['primary_key'])) {
            $columns[] = "PRIMARY KEY ({$schema['primary_key']})";
        }
        
        // Add unique indexes
        if (isset($schema['unique_indexes'])) {
            foreach ($schema['unique_indexes'] as $index_name => $index_columns) {
                $columns_str = implode(',', $index_columns);
                $columns[] = "UNIQUE KEY {$index_name} ({$columns_str})";
            }
        }
        
        // Add regular indexes
        if (isset($schema['indexes'])) {
            foreach ($schema['indexes'] as $index_name => $index_columns) {
                $columns_str = implode(',', $index_columns);
                $columns[] = "KEY {$index_name} ({$columns_str})";
            }
        }
        
        $columns_sql = implode(",\n    ", $columns);
        
        return "CREATE TABLE {$table_name} (
    {$columns_sql}
) {$charset_collate};";
    }
    
    /**
     * Create additional table indexes
     * 
     * @param string $table_name Full table name
     * @param array $schema Table schema
     * @return void
     */
    private function create_table_indexes($table_name, array $schema) {
        // Additional indexes that might not be handled by dbDelta
        $additional_indexes = [
            // Add any complex indexes here
        ];
        
        foreach ($additional_indexes as $index_sql) {
            $sql = str_replace('{table}', $table_name, $index_sql);
            $this->wpdb->query($sql);
        }
    }
    
    /**
     * Check if table exists
     * 
     * @param string $table_name Table name without prefix
     * @return bool Table exists
     */
    public function table_exists($table_name) {
        $full_table_name = $this->wpdb->prefix . $table_name;
        $result = $this->wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");
        return $result === $full_table_name;
    }
    
    /**
     * Validate table schema
     * 
     * @param string $table_name Table name without prefix
     * @return array Validation results
     */
    public function validate_table_schema($table_name) {
        $full_table_name = $this->wpdb->prefix . $table_name;
        $validation = [
            'exists' => false,
            'columns' => [],
            'missing_columns' => [],
            'extra_columns' => [],
            'indexes' => [],
            'missing_indexes' => []
        ];
        
        try {
            // Check if table exists
            if (!$this->table_exists($table_name)) {
                $this->logger->warning("Table does not exist: {$full_table_name}");
                return $validation;
            }
            
            $validation['exists'] = true;
            
            // Get actual table structure
            $columns = $this->wpdb->get_results("DESCRIBE {$full_table_name}", ARRAY_A);
            $actual_columns = [];
            foreach ($columns as $column) {
                $actual_columns[$column['Field']] = $column;
                $validation['columns'][] = $column['Field'];
            }
            
            // Check for missing/extra columns
            if (isset($this->table_schemas[$table_name])) {
                $expected_columns = array_keys($this->table_schemas[$table_name]['columns']);
                $validation['missing_columns'] = array_diff($expected_columns, $validation['columns']);
                $validation['extra_columns'] = array_diff($validation['columns'], $expected_columns);
            }
            
            // Get table indexes
            $indexes = $this->wpdb->get_results("SHOW INDEX FROM {$full_table_name}", ARRAY_A);
            foreach ($indexes as $index) {
                $validation['indexes'][] = $index['Key_name'];
            }
            $validation['indexes'] = array_unique($validation['indexes']);
            
            $this->logger->debug("Table validation completed: {$full_table_name}", $validation);
            
        } catch (\Exception $e) {
            $this->logger->error("Table validation failed: {$full_table_name}", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $validation;
    }
    
    /**
     * Insert roster entry with validation
     * 
     * @param array $data Roster data
     * @return int|false Inserted ID or false on failure
     */
    public function insert_roster_entry(array $data) {
        try {
            // Validate required fields
            $required_fields = ['order_id', 'order_item_id', 'product_id', 'customer_id'];
            foreach ($required_fields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    throw new DatabaseException("Required field missing: {$field}");
                }
            }
            
            // Sanitize and validate data
            $sanitized_data = $this->sanitize_roster_data($data);
            
            // Check for duplicate entry
            if ($this->roster_entry_exists($data['order_id'], $data['order_item_id'], $data['player_index'] ?? 0)) {
                $this->logger->info('Roster entry already exists, updating instead', [
                    'order_id' => $data['order_id'],
                    'order_item_id' => $data['order_item_id'],
                    'player_index' => $data['player_index'] ?? 0
                ]);
                return $this->update_roster_entry($data);
            }
            
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            $result = $this->wpdb->insert($table_name, $sanitized_data);
            
            if ($result === false) {
                throw new DatabaseException('Insert failed: ' . $this->wpdb->last_error);
            }
            
            $inserted_id = $this->wpdb->insert_id;
            
            $this->logger->debug('Roster entry inserted', [
                'id' => $inserted_id,
                'order_id' => $data['order_id'],
                'customer_id' => $data['customer_id']
            ]);
            
            return $inserted_id;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to insert roster entry', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }
    
    /**
     * Update roster entry
     * 
     * @param array $data Roster data with ID or unique identifiers
     * @return bool Success status
     */
    public function update_roster_entry(array $data) {
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            // Sanitize data
            $sanitized_data = $this->sanitize_roster_data($data);
            
            // Determine where clause
            if (isset($data['id'])) {
                $where = ['id' => $data['id']];
                unset($sanitized_data['id']);
            } else {
                $where = [
                    'order_id' => $data['order_id'],
                    'order_item_id' => $data['order_item_id'],
                    'player_index' => $data['player_index'] ?? 0
                ];
                unset($sanitized_data['order_id'], $sanitized_data['order_item_id'], $sanitized_data['player_index']);
            }
            
            $result = $this->wpdb->update($table_name, $sanitized_data, $where);
            
            if ($result === false) {
                throw new DatabaseException('Update failed: ' . $this->wpdb->last_error);
            }
            
            $this->logger->debug('Roster entry updated', [
                'affected_rows' => $result,
                'where' => $where
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update roster entry', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }
    
    /**
     * Delete roster entries
     * 
     * @param array $where Where conditions
     * @return int|false Number of deleted rows or false on failure
     */
    public function delete_roster_entries(array $where) {
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            $result = $this->wpdb->delete($table_name, $where);
            
            if ($result === false) {
                throw new DatabaseException('Delete failed: ' . $this->wpdb->last_error);
            }
            
            $this->logger->info('Roster entries deleted', [
                'deleted_count' => $result,
                'where' => $where
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete roster entries', [
                'error' => $e->getMessage(),
                'where' => $where
            ]);
            return false;
        }
    }
    
    /**
     * Get roster entries with filters
     * 
     * @param array $filters Filter conditions
     * @param array $options Query options (limit, offset, order_by)
     * @return array|false Roster entries or false on failure
     */
    public function get_roster_entries(array $filters = [], array $options = []) {
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            // Build WHERE clause
            $where_conditions = [];
            $where_values = [];
            
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '%s'));
                    $where_conditions[] = "{$field} IN ({$placeholders})";
                    $where_values = array_merge($where_values, $value);
                } elseif ($value === null) {
                    $where_conditions[] = "{$field} IS NULL";
                } else {
                    $where_conditions[] = "{$field} = %s";
                    $where_values[] = $value;
                }
            }
            
            // Build query
            $sql = "SELECT * FROM {$table_name}";
            
            if (!empty($where_conditions)) {
                $sql .= " WHERE " . implode(' AND ', $where_conditions);
            }
            
            // Add ordering
            if (isset($options['order_by'])) {
                $sql .= " ORDER BY " . sanitize_sql_orderby($options['order_by']);
            } else {
                $sql .= " ORDER BY created_at DESC";
            }
            
            // Add limit
            if (isset($options['limit'])) {
                $sql .= " LIMIT %d";
                $where_values[] = $options['limit'];
                
                if (isset($options['offset'])) {
                    $sql .= " OFFSET %d";
                    $where_values[] = $options['offset'];
                }
            }
            
            // Prepare and execute query
            if (!empty($where_values)) {
                $sql = $this->wpdb->prepare($sql, $where_values);
            }
            
            $results = $this->wpdb->get_results($sql, ARRAY_A);
            
            if ($results === null) {
                throw new DatabaseException('Query failed: ' . $this->wpdb->last_error);
            }
            
            $this->logger->debug('Retrieved roster entries', [
                'count' => count($results),
                'filters' => $filters
            ]);
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get roster entries', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'options' => $options
            ]);
            return false;
        }
    }
    
    /**
     * Get roster entries count with filters
     * 
     * @param array $filters Filter conditions
     * @return int|false Count or false on failure
     */
    public function get_roster_entries_count(array $filters = []) {
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            // Build WHERE clause
            $where_conditions = [];
            $where_values = [];
            
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '%s'));
                    $where_conditions[] = "{$field} IN ({$placeholders})";
                    $where_values = array_merge($where_values, $value);
                } elseif ($value === null) {
                    $where_conditions[] = "{$field} IS NULL";
                } else {
                    $where_conditions[] = "{$field} = %s";
                    $where_values[] = $value;
                }
            }
            
            $sql = "SELECT COUNT(*) FROM {$table_name}";
            
            if (!empty($where_conditions)) {
                $sql .= " WHERE " . implode(' AND ', $where_conditions);
            }
            
            if (!empty($where_values)) {
                $sql = $this->wpdb->prepare($sql, $where_values);
            }
            
            $count = $this->wpdb->get_var($sql);
            
            if ($count === null) {
                throw new DatabaseException('Count query failed: ' . $this->wpdb->last_error);
            }
            
            return (int) $count;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to count roster entries', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            return false;
        }
    }
    
    /**
     * Check if roster entry exists
     * 
     * @param int $order_id Order ID
     * @param int $order_item_id Order item ID
     * @param int $player_index Player index
     * @return bool Entry exists
     */
    public function roster_entry_exists($order_id, $order_item_id, $player_index = 0) {
        $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
        
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE order_id = %d AND order_item_id = %d AND player_index = %d",
            $order_id,
            $order_item_id,
            $player_index
        ));
        
        return $count > 0;
    }
    
    /**
     * Sanitize roster data
     * 
     * @param array $data Raw data
     * @return array Sanitized data
     */
    private function sanitize_roster_data(array $data) {
        $sanitized = [];
        
        // Define field types for sanitization (all table columns so inserts are complete)
        $field_types = [
            'order_id' => 'int',
            'order_item_id' => 'int',
            'variation_id' => 'int',
            'product_id' => 'int',
            'customer_id' => 'int',
            'player_index' => 'int',
            'first_name' => 'text',
            'last_name' => 'text',
            'dob' => 'date',
            'gender' => 'text',
            'medical_conditions' => 'textarea',
            'dietary_needs' => 'textarea',
            'emergency_contact' => 'text',
            'emergency_phone' => 'text',
            'parent_email' => 'email',
            'parent_phone' => 'text',
            'parent_first_name' => 'text',
            'parent_last_name' => 'text',
            'event_type' => 'text',
            'activity_type' => 'text',
            'venue' => 'text',
            'age_group' => 'text',
            'start_date' => 'date',
            'end_date' => 'date',
            'event_details' => 'json',
            'booking_type' => 'text',
            'selected_days' => 'text',
            'season' => 'text',
            'region' => 'text',
            'city' => 'text',
            'course_day' => 'text',
            'course_times' => 'text',
            'camp_times' => 'text',
            'camp_terms' => 'text',
            'times' => 'text',
            'term' => 'text',
            'days_selected' => 'text',
            'canton_region' => 'text',
            'discount_applied' => 'text',
            'order_status' => 'text',
            'event_signature' => 'text',
            'product_name' => 'text',
            'player_name' => 'text',
            'player_first_name' => 'text',
            'player_last_name' => 'text',
            'player_dob' => 'date',
            'player_gender' => 'text',
            'player_medical' => 'textarea',
            'player_dietary' => 'textarea',
            'age' => 'int',
            'base_price' => 'decimal',
            'discount_amount' => 'decimal',
            'final_price' => 'decimal',
            'reimbursement' => 'decimal',
            'discount_codes' => 'text',
            'registration_timestamp' => 'datetime',
        ];
        
        foreach ($data as $key => $value) {
            if (!isset($field_types[$key])) {
                continue; // Skip unknown fields
            }
            
            $sanitized[$key] = $this->sanitize_field($value, $field_types[$key]);
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize individual field
     * 
     * @param mixed $value Field value
     * @param string $type Field type
     * @return mixed Sanitized value
     */
    private function sanitize_field($value, $type) {
        if ($value === null || $value === '') {
            return null;
        }
        
        switch ($type) {
            case 'int':
                return (int) $value;
                
            case 'text':
                return sanitize_text_field($value);
                
            case 'textarea':
                return sanitize_textarea_field($value);
                
            case 'email':
                return sanitize_email($value);
                
            case 'date':
                return $this->sanitize_date($value);
                
            case 'json':
                return is_string($value) ? $value : json_encode($value);
                
            case 'decimal':
                if (is_numeric($value)) {
                    return (float) $value;
                }
                if (is_string($value)) {
                    $stripped = wp_strip_all_tags($value);
                    $numeric = preg_replace('/[^0-9.]/', '', $stripped);
                    return $numeric !== '' ? (float) $numeric : 0.0;
                }
                return 0.0;
                
            case 'datetime':
                if (empty($value)) {
                    return null;
                }
                $ts = is_numeric($value) ? (int) $value : strtotime($value);
                return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
                
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Sanitize date value
     * 
     * @param mixed $value Date value
     * @return string|null Formatted date or null
     */
    private function sanitize_date($value) {
        if (empty($value)) {
            return null;
        }
        
        // Try to parse the date
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        
        return date('Y-m-d', $timestamp);
    }
    
    /**
     * Rebuild rosters from WooCommerce orders
     * 
     * @param array $options Rebuild options
     * @return array Rebuild results
     */
    public function rebuild_rosters(array $options = []) {
        try {
            $this->logger->info('Starting roster rebuild process');
            
            $results = [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
                'start_time' => current_time('mysql'),
                'end_time' => null
            ];
            
            // Clear existing data if requested
            if (isset($options['clear_existing']) && $options['clear_existing']) {
                $this->logger->info('Clearing existing roster data');
                $this->wpdb->query("TRUNCATE TABLE {$this->wpdb->prefix}intersoccer_rosters");
            }
            
            // Process orders in transaction
            $this->transaction(function($db) use (&$results, $options) {
                // This would integrate with the OrderProcessor service
                // For now, we'll just update the end time
                $results['end_time'] = current_time('mysql');
                return true;
            });
            
            $this->logger->info('Roster rebuild process completed', $results);
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('Roster rebuild process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'errors' => 1,
                'start_time' => current_time('mysql'),
                'end_time' => current_time('mysql'),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Clean up old roster entries
     * 
     * @param int $days_old Days old threshold
     * @return int Number of deleted entries
     */
    public function cleanup_old_entries($days_old = 365) {
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            $cutoff_date = date('Y-m-d', strtotime("-{$days_old} days"));
            
            $deleted = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < %s AND order_status IN ('cancelled', 'refunded')",
                $cutoff_date
            ));
            
            $this->logger->info('Cleaned up old roster entries', [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoff_date
            ]);
            
            return $deleted;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup old entries', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Get database statistics
     * 
     * @return array Database statistics
     */
    public function get_statistics() {
        $stats = [];
        
        try {
            $table_name = $this->wpdb->prefix . 'intersoccer_rosters';
            
            // Total entries
            $stats['total_entries'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            
            // Entries by activity type
            $activity_stats = $this->wpdb->get_results(
                "SELECT activity_type, COUNT(*) as count FROM {$table_name} GROUP BY activity_type",
                ARRAY_A
            );
            $stats['by_activity_type'] = $activity_stats;
            
            // Recent entries (last 30 days)
            $stats['recent_entries'] = $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            
            // Table size
            $table_status = $this->wpdb->get_row(
                "SELECT table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table_name}'",
                ARRAY_A
            );
            $stats['table_size'] = $table_status;
            
            $this->logger->debug('Retrieved database statistics', $stats);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get database statistics', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $stats;
    }
    
    /**
     * Get WordPress database instance
     * 
     * @return \wpdb
     */
    public function get_wpdb() {
        return $this->wpdb;
    }
}