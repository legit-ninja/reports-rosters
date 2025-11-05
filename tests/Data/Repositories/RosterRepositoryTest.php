<?php
/**
 * RosterRepository Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Repositories;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use Mockery;

class RosterRepositoryTest extends TestCase {
    private $repository;
    private $wpdb;
    
    protected function setUp(): void {
        parent::setUp();
        
        global $wpdb;
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $wpdb = $this->wpdb;
        
        $this->repository = new RosterRepository();
    }
    
    public function test_create_roster() {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 123;
        
        $result = $this->repository->create(['order_id' => 1]);
        
        $this->assertNotNull($result);
    }
    
    public function test_find_roster() {
        $this->wpdb->shouldReceive('get_row')->once()->andReturn((object)['id' => 1, 'order_id' => 1]);
        
        $roster = $this->repository->find(1);
        
        $this->assertNotNull($roster);
    }
    
    public function test_get_rosters_by_venue() {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);
        
        $rosters = $this->repository->where(['venue' => 'Zurich']);
        
        $this->assertInstanceOf(RostersCollection::class, $rosters);
    }
    
    public function test_delete_roster() {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);
        
        $result = $this->repository->delete(1);
        
        $this->assertTrue($result);
    }
}

