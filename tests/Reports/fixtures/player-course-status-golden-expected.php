<?php

return array (
  'result' => 
  array (
    'rows' => 
    array (
      0 => 
      array (
        'player' => 
        array (
          'name' => 'Ada Lovelace',
          'first_name' => 'Ada',
          'last_name' => 'Lovelace',
          'player_index' => 0,
          'key' => 'idx:10:0',
          'tier' => 'idx',
        ),
        'parent' => 
        array (
          'id' => 10,
          'email' => 'booked@test',
        ),
        'dob' => '2015-01-01',
        'dob_restricted' => false,
        'age' => 11,
        'booked' => true,
        'booking_status' => 'booked',
        'booking_status_label' => 'Booked',
        'bookings' => 
        array (
          0 => 
          array (
            'order_id' => 55001,
            'order_item_id' => 59001,
            'product' => 'Autumn Skills',
            'venue' => 'Geneva Centre',
            'venue_slug' => 'geneva-centre',
            'age_group' => '5-12y',
            'age_group_slug' => '5-12y',
            'course_day' => 'Sunday',
            'course_day_slug' => 'sunday',
            'times' => '10:00-11:30',
            'season' => 'Autumn 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-15',
            'order_status' => 'wc-completed',
            'girls_only' => false,
            'label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15',
          ),
          1 => 
          array (
            'order_id' => 55001,
            'order_item_id' => 59020,
            'product' => 'Autumn Skills',
            'venue' => 'Geneva Centre',
            'venue_slug' => 'geneva-centre',
            'age_group' => '5-12y',
            'age_group_slug' => '5-12y',
            'course_day' => 'Sunday',
            'course_day_slug' => 'sunday',
            'times' => '10:00-11:30',
            'season' => 'Autumn',
            'start_date' => '',
            'end_date' => '',
            'order_status' => 'wc-completed',
            'girls_only' => false,
            'label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn',
          ),
        ),
        'pending_bookings' => 
        array (
        ),
        'bookings_label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15; Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn',
      ),
      1 => 
      array (
        'player' => 
        array (
          'name' => 'Pen Ding',
          'first_name' => 'Pen',
          'last_name' => 'Ding',
          'player_index' => 0,
          'key' => 'idx:11:0',
          'tier' => 'idx',
        ),
        'parent' => 
        array (
          'id' => 11,
          'email' => 'pending@test',
        ),
        'dob' => '2015-02-02',
        'dob_restricted' => false,
        'age' => 11,
        'booked' => false,
        'booking_status' => 'pending_payment',
        'booking_status_label' => 'Pending payment',
        'bookings' => 
        array (
        ),
        'pending_bookings' => 
        array (
          0 => 
          array (
            'order_id' => 55002,
            'order_item_id' => 59002,
            'product' => 'Autumn Skills',
            'venue' => 'Geneva Centre',
            'venue_slug' => 'geneva-centre',
            'age_group' => '5-12y',
            'age_group_slug' => '5-12y',
            'course_day' => 'Sunday',
            'course_day_slug' => 'sunday',
            'times' => '10:00-11:30',
            'season' => 'Autumn 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-15',
            'order_status' => 'wc-pending',
            'girls_only' => false,
            'label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15',
          ),
        ),
        'bookings_label' => '',
      ),
      2 => 
      array (
        'player' => 
        array (
          'name' => 'On Hold',
          'first_name' => 'On',
          'last_name' => 'Hold',
          'player_index' => 0,
          'key' => 'idx:12:0',
          'tier' => 'idx',
        ),
        'parent' => 
        array (
          'id' => 12,
          'email' => 'hold@test',
        ),
        'dob' => '2015-03-03',
        'dob_restricted' => false,
        'age' => 11,
        'booked' => false,
        'booking_status' => 'on_hold',
        'booking_status_label' => 'On hold',
        'bookings' => 
        array (
        ),
        'pending_bookings' => 
        array (
          0 => 
          array (
            'order_id' => 55003,
            'order_item_id' => 59003,
            'product' => 'Autumn Skills',
            'venue' => 'Geneva Centre',
            'venue_slug' => 'geneva-centre',
            'age_group' => '5-12y',
            'age_group_slug' => '5-12y',
            'course_day' => 'Sunday',
            'course_day_slug' => 'sunday',
            'times' => '10:00-11:30',
            'season' => 'Autumn 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-15',
            'order_status' => 'wc-on-hold',
            'girls_only' => false,
            'label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15',
          ),
        ),
        'bookings_label' => '',
      ),
      3 => 
      array (
        'player' => 
        array (
          'name' => 'Not Booked',
          'first_name' => 'Not',
          'last_name' => 'Booked',
          'player_index' => 0,
          'key' => '',
          'tier' => '',
        ),
        'parent' => 
        array (
          'id' => 13,
          'email' => 'free@test',
        ),
        'dob' => '2015-04-04',
        'dob_restricted' => false,
        'age' => 11,
        'booked' => false,
        'booking_status' => 'not_booked',
        'booking_status_label' => 'Not booked',
        'bookings' => 
        array (
        ),
        'pending_bookings' => 
        array (
        ),
        'bookings_label' => '',
      ),
    ),
    'unmatched_bookings' => 
    array (
      0 => 
      array (
        'order_id' => 55005,
        'order_item_id' => 59030,
        'product' => 'Autumn Skills',
        'venue' => 'Geneva Centre',
        'venue_slug' => 'geneva-centre',
        'age_group' => '5-12y',
        'age_group_slug' => '5-12y',
        'course_day' => 'Sunday',
        'course_day_slug' => 'sunday',
        'times' => '10:00-11:30',
        'season' => 'Autumn 2026',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-15',
        'order_status' => 'wc-completed',
        'girls_only' => false,
        'label' => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15',
        'player_name' => 'Ghost Roster',
        'customer_id' => 0,
      ),
    ),
    'filter_options' => 
    array (
      'venues' => 
      array (
        'geneva-centre' => 'Geneva Centre',
      ),
      'course_days' => 
      array (
        0 => 'sunday',
      ),
      'age_groups' => 
      array (
        '5-12y' => '5-12y',
      ),
      'seasons' => 
      array (
        0 => 'Autumn',
      ),
    ),
    'totals' => 
    array (
      'players' => 4,
      'booked' => 1,
      'not_booked' => 3,
      'pending_payment' => 1,
      'on_hold' => 1,
      'unmatched_bookings' => 1,
    ),
    'warnings' => 
    array (
    ),
  ),
  'export_rows' => 
  array (
    0 => 
    array (
      0 => 'Ada Lovelace',
      1 => 'booked@test',
      2 => 10,
      3 => '2015-01-01',
      4 => 11,
      5 => 'Yes',
      6 => 'Booked',
      7 => 'Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn 2026 · 2026-09-01–2026-12-15; Autumn Skills · Geneva Centre · Sunday · 10:00-11:30 · Autumn',
      8 => 'idx',
    ),
    1 => 
    array (
      0 => 'Pen Ding',
      1 => 'pending@test',
      2 => 11,
      3 => '2015-02-02',
      4 => 11,
      5 => 'No',
      6 => 'Pending payment',
      7 => '',
      8 => 'idx',
    ),
    2 => 
    array (
      0 => 'On Hold',
      1 => 'hold@test',
      2 => 12,
      3 => '2015-03-03',
      4 => 11,
      5 => 'No',
      6 => 'On hold',
      7 => '',
      8 => 'idx',
    ),
    3 => 
    array (
      0 => 'Not Booked',
      1 => 'free@test',
      2 => 13,
      3 => '2015-04-04',
      4 => 11,
      5 => 'No',
      6 => 'Not booked',
      7 => '',
      8 => '',
    ),
  ),
);
