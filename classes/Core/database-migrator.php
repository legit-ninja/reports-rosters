<?php
/**
 * Database Migrator Class
 * 
 * Handles database schema migrations and upgrades.
 * Provides safe, versioned migrations with rollback support.
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
 * Database Migrator Class
 * 
 * Manages database schema migrations
 */
class DatabaseMigrator {
    
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
     * Current database version option name
     * 
     * @var string
     */
    const VERSION_OPTION = 'intersoccer_db_version';
    
    /**
     * Current schema version
     * 
     * @var string
     */
    const CURRENT_VERSION = '2.1.0';
    
    /**
     * Constructor
     * 
     * @param Logger|null $logger Logger instance
     */
    public function __construct(Logger $logger = null) {
        global $wpdb;
        
        $this->wpdb = $wpdb;
        $this->logger = $logger ?: new Logger();
    }
    
    /**
     * Run database migrations
     * 
     * @return bool Success status
     */
    public function migrate() {
        try {
            $current_version = $this->getCurrentVersion();
            $target_version = self::CURRENT_VERSION;
            
            $this->logger->info('Starting database migration', [
                'current_version' => $current_version,
                'target_version' => $target_version
            ]);
            
            // If already at current version, skip
            if (version_compare($current_version, $target_version, '>=')) {
                $this->logger->info('Database already at current version');
                return true;
            }
            
            // Run migrations in order
            $migrations = $this->getMigrations();
            
            foreach ($migrations as $version => $migration) {
                // Skip if already at or past this version
                if (version_compare($current_version, $version, '>=')) {
                    continue;
                }
                
                $this->logger->info("Running migration: {$version}");
                
                try {
                    $result = call_user_func($migration);
                    
                    if ($result === false) {
                        throw new DatabaseException("Migration {$version} failed");
                    }
                    
                    // Update version after successful migration
                    $this->updateVersion($version);
                    
                    $this->logger->info("Migration {$version} completed successfully");
                    
                } catch (\Exception $e) {
                    $this->logger->error("Migration {$version} failed", [
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
            
            // Set final version
            $this->updateVersion($target_version);
            
            $this->logger->info('All database migrations completed successfully');
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Database migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Get current database version
     * 
     * @return string Current version
     */
    public function getCurrentVersion() {
        return get_option(self::VERSION_OPTION, '1.0.0');
    }
    
    /**
     * Update database version
     * 
     * @param string $version Version to set
     * @return bool Success status
     */
    private function updateVersion($version) {
        return update_option(self::VERSION_OPTION, $version);
    }
    
    /**
     * Get migration functions
     * 
     * @return array Migration functions keyed by version
     */
    private function getMigrations() {
        return [
            '2.0.0' => [$this, 'migrate_2_0_0'],
            '2.1.0' => [$this, 'migrate_2_1_0'],
        ];
    }
    
    /**
     * Migration for version 2.0.0
     * Adds event_signature and is_placeholder columns
     * 
     * @return bool Success status
     */
    private function migrate_2_0_0() {
        $table = $this->wpdb->prefix . 'intersoccer_rosters';
        
        try {
            // Add event_signature column
            if (!$this->columnExists($table, 'event_signature')) {
                $this->logger->info('Adding event_signature column');
                
                $result = $this->wpdb->query(
                    "ALTER TABLE {$table} 
                     ADD COLUMN event_signature varchar(255) DEFAULT '' AFTER girls_only"
                );
                
                if ($result === false) {
                    throw new DatabaseException('Failed to add event_signature column');
                }
                
                // Add index
                $this->addIndex($table, 'idx_event_signature', 'event_signature(100)');
                
                $this->logger->info('event_signature column added successfully');
            }
            
            // Add is_placeholder column
            if (!$this->columnExists($table, 'is_placeholder')) {
                $this->logger->info('Adding is_placeholder column');
                
                $result = $this->wpdb->query(
                    "ALTER TABLE {$table} 
                     ADD COLUMN is_placeholder TINYINT(1) DEFAULT 0 AFTER event_signature"
                );
                
                if ($result === false) {
                    throw new DatabaseException('Failed to add is_placeholder column');
                }
                
                // Add index
                $this->addIndex($table, 'idx_is_placeholder', 'is_placeholder');
                
                $this->logger->info('is_placeholder column added successfully');
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Migration 2.0.0 failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Migration for version 2.1.0
     * Adds event_completed column
     * 
     * @return bool Success status
     */
    private function migrate_2_1_0() {
        $table = $this->wpdb->prefix . 'intersoccer_rosters';
        
        try {
            // Add event_completed column
            if (!$this->columnExists($table, 'event_completed')) {
                $this->logger->info('Adding event_completed column');
                
                $result = $this->wpdb->query(
                    "ALTER TABLE {$table} 
                     ADD COLUMN event_completed TINYINT(1) DEFAULT 0 AFTER is_placeholder"
                );
                
                if ($result === false) {
                    throw new DatabaseException('Failed to add event_completed column');
                }
                
                // Add index
                $this->addIndex($table, 'idx_event_completed', 'event_completed');
                
                $this->logger->info('event_completed column added successfully');
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Migration 2.1.0 failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if column exists in table
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @return bool Column exists
     */
    public function columnExists($table, $column) {
        $columns = $this->wpdb->get_col("DESCRIBE {$table}", 0);
        return in_array($column, $columns);
    }
    
    /**
     * Check if index exists on table
     * 
     * @param string $table Table name
     * @param string $index_name Index name
     * @return bool Index exists
     */
    public function indexExists($table, $index_name) {
        $indexes = $this->wpdb->get_results("SHOW INDEX FROM {$table}");
        $index_names = array_column($indexes, 'Key_name');
        return in_array($index_name, $index_names);
    }
    
    /**
     * Add column to table if it doesn't exist
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @param string $definition Column definition
     * @param string $after Add after this column (optional)
     * @return bool Success status
     */
    public function addColumn($table, $column, $definition, $after = null) {
        try {
            if ($this->columnExists($table, $column)) {
                $this->logger->debug("Column {$column} already exists in {$table}");
                return true;
            }
            
            $sql = "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}";
            
            if ($after) {
                $sql .= " AFTER {$after}";
            }
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                throw new DatabaseException("Failed to add column {$column}: " . $this->wpdb->last_error);
            }
            
            $this->logger->info("Added column {$column} to {$table}");
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to add column {$column}", [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Add index to table if it doesn't exist
     * 
     * @param string $table Table name
     * @param string $index_name Index name
     * @param string $columns Columns to index
     * @param string $type Index type (KEY, UNIQUE, FULLTEXT)
     * @return bool Success status
     */
    public function addIndex($table, $index_name, $columns, $type = 'KEY') {
        try {
            if ($this->indexExists($table, $index_name)) {
                $this->logger->debug("Index {$index_name} already exists on {$table}");
                return true;
            }
            
            $sql = "ALTER TABLE {$table} ADD {$type} {$index_name} ({$columns})";
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                throw new DatabaseException("Failed to add index {$index_name}: " . $this->wpdb->last_error);
            }
            
            $this->logger->info("Added index {$index_name} to {$table}");
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to add index {$index_name}", [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Drop column from table if it exists
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @return bool Success status
     */
    public function dropColumn($table, $column) {
        try {
            if (!$this->columnExists($table, $column)) {
                $this->logger->debug("Column {$column} doesn't exist in {$table}");
                return true;
            }
            
            $sql = "ALTER TABLE {$table} DROP COLUMN {$column}";
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                throw new DatabaseException("Failed to drop column {$column}: " . $this->wpdb->last_error);
            }
            
            $this->logger->info("Dropped column {$column} from {$table}");
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to drop column {$column}", [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Rename column in table
     * 
     * @param string $table Table name
     * @param string $old_name Old column name
     * @param string $new_name New column name
     * @param string $definition Column definition
     * @return bool Success status
     */
    public function renameColumn($table, $old_name, $new_name, $definition) {
        try {
            if (!$this->columnExists($table, $old_name)) {
                throw new DatabaseException("Column {$old_name} doesn't exist");
            }
            
            if ($this->columnExists($table, $new_name)) {
                $this->logger->debug("Column {$new_name} already exists");
                return true;
            }
            
            $sql = "ALTER TABLE {$table} CHANGE {$old_name} {$new_name} {$definition}";
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                throw new DatabaseException("Failed to rename column: " . $this->wpdb->last_error);
            }
            
            $this->logger->info("Renamed column {$old_name} to {$new_name} in {$table}");
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to rename column", [
                'table' => $table,
                'old_name' => $old_name,
                'new_name' => $new_name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get list of all migrations that have been run
     * 
     * @return array Migration history
     */
    public function getMigrationHistory() {
        return get_option('intersoccer_migration_history', []);
    }
    
    /**
     * Record migration in history
     * 
     * @param string $version Migration version
     * @param bool $success Success status
     * @return bool Update success
     */
    private function recordMigration($version, $success = true) {
        $history = $this->getMigrationHistory();
        
        $history[] = [
            'version' => $version,
            'success' => $success,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ];
        
        return update_option('intersoccer_migration_history', $history);
    }
}



