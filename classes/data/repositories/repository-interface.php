<?php
/**
 * Repository Interface
 * 
 * Base interface for all repository classes
 * 
 * @package InterSoccer\ReportsRosters\Data\Repositories
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Repositories;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository Interface
 * 
 * Defines contract for all repository implementations
 */
interface RepositoryInterface {
    
    /**
     * Find a record by ID
     * 
     * @param mixed $id Record ID
     * @return mixed|null
     */
    public function find($id);
    
    /**
     * Get all records
     * 
     * @return array
     */
    public function all();
    
    /**
     * Create a new record
     * 
     * @param array $data Record data
     * @return mixed
     */
    public function create(array $data);
    
    /**
     * Update an existing record
     * 
     * @param mixed $id Record ID
     * @param array $data Updated data
     * @return bool
     */
    public function update($id, array $data);
    
    /**
     * Delete a record
     * 
     * @param mixed $id Record ID
     * @return bool
     */
    public function delete($id);
    
    /**
     * Find records matching criteria
     * 
     * @param array $criteria Search criteria
     * @return array
     */
    public function where(array $criteria);
}
