<?php
/**
 * DataValidator Test
 * 
 * Tests for the DataValidator service
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class DataValidatorTest extends TestCase {
    
    private $validator;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->validator = new DataValidator($this->logger);
    }
    
    public function test_validator_initialization() {
        $this->assertInstanceOf(DataValidator::class, $this->validator);
    }
    
    public function test_validate_field_required() {
        $errors = $this->validator->validate_field('name', '', ['required']);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('required', strtolower($errors[0]));
    }
    
    public function test_validate_field_required_passes() {
        $errors = $this->validator->validate_field('name', 'John', ['required']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_field_min_length() {
        $errors = $this->validator->validate_field('name', 'Jo', ['min:3']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_field_max_length() {
        $errors = $this->validator->validate_field('name', 'VeryLongNameThatExceedsLimit', ['max:10']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_field_email() {
        $errors = $this->validator->validate_field('email', 'invalid-email', ['email']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_field_email_passes() {
        $errors = $this->validator->validate_field('email', 'test@example.com', ['email']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_field_integer() {
        $errors = $this->validator->validate_field('age', 'not-a-number', ['integer']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_field_integer_passes() {
        $errors = $this->validator->validate_field('age', '25', ['integer']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_avs_number() {
        // Valid AVS number format: 756.1234.5678.97
        $errors = $this->validator->validate_field('avs', '756.1234.5678.97', ['avs_number']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_avs_number_invalid() {
        $errors = $this->validator->validate_field('avs', '123.456.789', ['avs_number']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_swiss_phone() {
        $errors = $this->validator->validate_field('phone', '+41 12 345 67 89', ['swiss_phone']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_swiss_phone_invalid() {
        $errors = $this->validator->validate_field('phone', '123', ['swiss_phone']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_age_group_format() {
        $errors = $this->validator->validate_field('age_group', 'U10', ['age_group_format']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_age_group_format_invalid() {
        $errors = $this->validator->validate_field('age_group', 'Invalid', ['age_group_format']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_gender_value() {
        $errors = $this->validator->validate_field('gender', 'male', ['gender_value']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_gender_value_invalid() {
        $errors = $this->validator->validate_field('gender', 'invalid', ['gender_value']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_activity_type() {
        $errors = $this->validator->validate_field('activity', 'Camp', ['activity_type']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_activity_type_invalid() {
        $errors = $this->validator->validate_field('activity', 'InvalidType', ['activity_type']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_date() {
        $errors = $this->validator->validate_field('date', '2024-12-31', ['date']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_date_invalid() {
        $errors = $this->validator->validate_field('date', 'not-a-date', ['date']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_date_before_today() {
        $past_date = date('Y-m-d', strtotime('-1 day'));
        $errors = $this->validator->validate_field('dob', $past_date, ['date', 'before:today']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_date_after() {
        $future_date = date('Y-m-d', strtotime('+1 day'));
        $today = date('Y-m-d');
        $errors = $this->validator->validate_field('start_date', $future_date, ['date', "after:$today"]);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_in_array() {
        $errors = $this->validator->validate_field('status', 'active', ['in:active,inactive,pending']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_in_array_fails() {
        $errors = $this->validator->validate_field('status', 'invalid', ['in:active,inactive,pending']);
        
        $this->assertNotEmpty($errors);
    }
    
    public function test_validate_nullable_field() {
        $errors = $this->validator->validate_field('optional', null, ['nullable', 'email']);
        
        $this->assertEmpty($errors);
    }
    
    public function test_validate_roster_data() {
        $roster_data = [
            'order_id' => 1,
            'customer_id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2010-01-01',
            'gender' => 'male',
            'activity_type' => 'Camp',
            'venue' => 'Zurich',
            'start_date' => '2024-06-01',
            'end_date' => '2024-06-07'
        ];
        
        $result = $this->validator->validateRosterData($roster_data);
        
        $this->assertTrue($result);
    }
    
    public function test_validate_roster_data_with_missing_fields() {
        $this->expectException(\Exception::class);
        
        $incomplete_data = [
            'order_id' => 1,
            // Missing required fields
        ];
        
        $this->validator->validateRosterData($incomplete_data);
    }
    
    public function test_validate_multiple_rules() {
        $errors = $this->validator->validate_field('username', 'ab', ['required', 'min:3', 'max:20']);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('min', strtolower($errors[0]));
    }
    
    public function test_custom_validation_rule() {
        $customRule = function($value) {
            return $value === 'special';
        };
        
        $this->validator->addCustomRule('is_special', $customRule);
        
        $errors = $this->validator->validate_field('field', 'special', ['is_special']);
        
        $this->assertEmpty($errors);
    }
}

