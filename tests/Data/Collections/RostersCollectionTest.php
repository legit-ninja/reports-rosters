<?php
/**
 * RostersCollection Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Collections;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use Mockery;

class RostersCollectionTest extends TestCase {
    public function test_collection_creation() {
        $collection = new RostersCollection();
        $this->assertInstanceOf(RostersCollection::class, $collection);
    }
    
    public function test_group_by_venue() {
        $roster1 = Mockery::mock();
        $roster1->venue = 'Zurich';
        $roster2 = Mockery::mock();
        $roster2->venue = 'Zurich';
        $roster3 = Mockery::mock();
        $roster3->venue = 'Geneva';
        
        $collection = new RostersCollection([$roster1, $roster2, $roster3]);
        $grouped = $collection->groupBy('venue');
        
        $this->assertIsArray($grouped);
        $this->assertCount(2, $grouped['Zurich']);
        $this->assertCount(1, $grouped['Geneva']);
    }
    
    public function test_merge_collections() {
        $collection1 = new RostersCollection([Mockery::mock()]);
        $collection2 = new RostersCollection([Mockery::mock()]);
        
        $merged = $collection1->merge($collection2);
        
        $this->assertEquals(2, $merged->count());
    }
}

