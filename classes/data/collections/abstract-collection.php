<?php
/**
 * Abstract Collection Class
 * 
 * Base collection class for all data collections in InterSoccer Reports & Rosters.
 * Provides advanced filtering, sorting, and manipulation capabilities for model collections.
 * 
 * @package InterSoccer\ReportsRosters\Data\Collections
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Collections;

use InterSoccer\ReportsRosters\Data\Models\AbstractModel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Collection Class
 * 
 * Base class for model collections with advanced operations
 */
abstract class AbstractCollection implements \Iterator, \Countable, \ArrayAccess {
    
    /**
     * Collection items
     * 
     * @var array
     */
    protected $items = [];
    
    /**
     * Current iterator position
     * 
     * @var int
     */
    protected $position = 0;
    
    /**
     * Constructor
     * 
     * @param array $items Initial items
     */
    public function __construct(array $items = []) {
        $this->items = array_values($items); // Re-index to ensure numeric keys
    }
    
    /**
     * Add an item to the collection
     * 
     * @param AbstractModel $item Item to add
     * @return self
     */
    public function add(AbstractModel $item) {
        $this->items[] = $item;
        return $this;
    }
    
    /**
     * Push an item onto the end of the collection
     * 
     * @param AbstractModel $item Item to push
     * @return self
     */
    public function push(AbstractModel $item) {
        return $this->add($item);
    }
    
    /**
     * Prepend an item to the beginning of the collection
     * 
     * @param AbstractModel $item Item to prepend
     * @return self
     */
    public function prepend(AbstractModel $item) {
        array_unshift($this->items, $item);
        return $this;
    }
    
    /**
     * Pop an item off the end of the collection
     * 
     * @return AbstractModel|null Popped item or null if empty
     */
    public function pop() {
        return array_pop($this->items);
    }
    
    /**
     * Shift an item off the beginning of the collection
     * 
     * @return AbstractModel|null Shifted item or null if empty
     */
    public function shift() {
        return array_shift($this->items);
    }
    
    /**
     * Get the first item in the collection
     * 
     * @param callable|null $callback Optional callback to filter
     * @return AbstractModel|null First item or null if empty
     */
    public function first(callable $callback = null) {
        if ($callback === null) {
            return $this->items[0] ?? null;
        }
        
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Get the first item where a field equals a value
     * 
     * @param string $field Field name
     * @param mixed $value Value to match
     * @return AbstractModel|null First matching item or null
     */
    public function firstWhere($field, $value) {
        return $this->first(function($item) use ($field, $value) {
            return $item->getAttribute($field) === $value;
        });
    }
    
    /**
     * Get the last item in the collection
     * 
     * @param callable|null $callback Optional callback to filter
     * @return AbstractModel|null Last item or null if empty
     */
    public function last(callable $callback = null) {
        if ($callback === null) {
            return end($this->items) ?: null;
        }
        
        $reversed = array_reverse($this->items);
        foreach ($reversed as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Filter the collection using a callback
     * 
     * @param callable $callback Filter callback
     * @return static New filtered collection
     */
    public function filter(callable $callback) {
        $filtered = array_filter($this->items, $callback);
        return new static(array_values($filtered));
    }
    
    /**
     * Filter items where a field equals a value
     * 
     * @param string $field Field name
     * @param mixed $value Value to match
     * @return static New filtered collection
     */
    public function where($field, $value) {
        return $this->filter(function($item) use ($field, $value) {
            return $item->getAttribute($field) === $value;
        });
    }
    
    /**
     * Filter items where a field is in an array of values
     * 
     * @param string $field Field name
     * @param array $values Values to match
     * @return static New filtered collection
     */
    public function whereIn($field, array $values) {
        return $this->filter(function($item) use ($field, $values) {
            return in_array($item->getAttribute($field), $values);
        });
    }
    
    /**
     * Filter items where a field is not in an array of values
     * 
     * @param string $field Field name
     * @param array $values Values to exclude
     * @return static New filtered collection
     */
    public function whereNotIn($field, array $values) {
        return $this->filter(function($item) use ($field, $values) {
            return !in_array($item->getAttribute($field), $values);
        });
    }
    
    /**
     * Filter items where a field is null
     * 
     * @param string $field Field name
     * @return static New filtered collection
     */
    public function whereNull($field) {
        return $this->filter(function($item) use ($field) {
            return $item->getAttribute($field) === null;
        });
    }
    
    /**
     * Filter items where a field is not null
     * 
     * @param string $field Field name
     * @return static New filtered collection
     */
    public function whereNotNull($field) {
        return $this->filter(function($item) use ($field) {
            return $item->getAttribute($field) !== null;
        });
    }
    
    /**
     * Reject items using a callback (opposite of filter)
     * 
     * @param callable $callback Rejection callback
     * @return static New filtered collection
     */
    public function reject(callable $callback) {
        return $this->filter(function($item) use ($callback) {
            return !$callback($item);
        });
    }
    
    /**
     * Transform each item using a callback
     * 
     * @param callable $callback Transformation callback
     * @return static New transformed collection
     */
    public function map(callable $callback) {
        $mapped = array_map($callback, $this->items);
        return new static(array_values($mapped));
    }
    
    /**
     * Sort the collection using a callback
     * 
     * @param callable|string $callback Sort callback or field name
     * @param int $sort_flags Sort flags
     * @param bool $descending Sort descending
     * @return static New sorted collection
     */
    public function sortBy($callback, $sort_flags = SORT_REGULAR, $descending = false) {
        $items = $this->items;
        
        if (is_string($callback)) {
            $field = $callback;
            $callback = function($item) use ($field) {
                return $item->getAttribute($field);
            };
        }
        
        // Create array of [sort_value => original_index]
        $sort_values = [];
        foreach ($items as $index => $item) {
            $sort_values[$index] = $callback($item);
        }
        
        // Sort maintaining keys
        if ($descending) {
            arsort($sort_values, $sort_flags);
        } else {
            asort($sort_values, $sort_flags);
        }
        
        // Rebuild items array in new order
        $sorted_items = [];
        foreach (array_keys($sort_values) as $original_index) {
            $sorted_items[] = $items[$original_index];
        }
        
        return new static($sorted_items);
    }
    
    /**
     * Sort the collection in descending order
     * 
     * @param callable|string $callback Sort callback or field name
     * @param int $sort_flags Sort flags
     * @return static New sorted collection
     */
    public function sortByDesc($callback, $sort_flags = SORT_REGULAR) {
        return $this->sortBy($callback, $sort_flags, true);
    }

    /**
     * Sort the collection by natural value order (for scalar items, e.g. after pluck).
     *
     * @param int $sort_flags Sort flags (e.g. SORT_REGULAR, SORT_NATURAL)
     * @return static New sorted collection
     */
    public function sort($sort_flags = SORT_REGULAR) {
        $items = $this->items;
        sort($items, $sort_flags);
        return new static($items);
    }

    /**
     * Reverse the order of items
     * 
     * @return static New reversed collection
     */
    public function reverse() {
        return new static(array_reverse($this->items));
    }
    
    /**
     * Shuffle the collection
     * 
     * @return static New shuffled collection
     */
    public function shuffle() {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }
    
    /**
     * Take the first n items
     * 
     * @param int $limit Number of items to take
     * @return static New collection with limited items
     */
    public function take($limit) {
        return new static(array_slice($this->items, 0, $limit));
    }
    
    /**
     * Skip the first n items
     * 
     * @param int $offset Number of items to skip
     * @return static New collection without skipped items
     */
    public function skip($offset) {
        return new static(array_slice($this->items, $offset));
    }
    
    /**
     * Get a slice of the collection
     * 
     * @param int $offset Starting offset
     * @param int|null $length Number of items (null for all remaining)
     * @return static New sliced collection
     */
    public function slice($offset, $length = null) {
        return new static(array_slice($this->items, $offset, $length));
    }
    
    /**
     * Split the collection into chunks
     * 
     * @param int $size Chunk size
     * @return array Array of collections
     */
    public function chunk($size) {
        $chunks = array_chunk($this->items, $size);
        return array_map(function($chunk) {
            return new static($chunk);
        }, $chunks);
    }
    
    /**
     * Group items by a field or callback result
     * 
     * @param callable|string $callback Grouping callback or field name
     * @return array Array of collections keyed by group value
     */
    public function groupBy($callback) {
        if (is_string($callback)) {
            $field = $callback;
            $callback = function($item) use ($field) {
                return $item->getAttribute($field);
            };
        }
        
        $groups = [];
        foreach ($this->items as $item) {
            $key = $callback($item);
            if (!isset($groups[$key])) {
                $groups[$key] = new static();
            }
            $groups[$key]->add($item);
        }
        
        return $groups;
    }
    
    /**
     * Count items by a field or callback result
     * 
     * @param callable|string|null $callback Counting callback, field name, or null for total count
     * @return array|int Array of counts or total count
     */
    public function countBy($callback = null) {
        if ($callback === null) {
            return $this->count();
        }
        
        if (is_string($callback)) {
            $field = $callback;
            $callback = function($item) use ($field) {
                return $item->getAttribute($field);
            };
        }
        
        $counts = [];
        foreach ($this->items as $item) {
            $key = $callback($item);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        
        return $counts;
    }
    
    /**
     * Get unique items by a field or callback result
     * 
     * @param callable|string|null $callback Uniqueness callback or field name
     * @return static New collection with unique items
     */
    public function unique($callback = null) {
        if ($callback === null) {
            return new static(array_values(array_unique($this->items, SORT_REGULAR)));
        }
        
        if (is_string($callback)) {
            $field = $callback;
            $callback = function($item) use ($field) {
                return $item->getAttribute($field);
            };
        }
        
        $seen = [];
        $unique = [];
        
        foreach ($this->items as $item) {
            $key = $callback($item);
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $unique[] = $item;
            }
        }
        
        return new static($unique);
    }
    
    /**
     * Pluck values from items
     * 
     * @param string $field Field to pluck
     * @param string|null $key_field Field to use as array keys
     * @return static New collection of plucked values
     */
    public function pluck($field, $key_field = null) {
        $plucked = [];
        
        foreach ($this->items as $item) {
            $value = $item->getAttribute($field);
            
            if ($key_field !== null) {
                $key = $item->getAttribute($key_field);
                $plucked[$key] = $value;
            } else {
                $plucked[] = $value;
            }
        }
        
        // For pluck, we return a simple array collection instead of model collection
        return new static($plucked);
    }
    
    /**
     * Get the minimum value of a field
     * 
     * @param string $field Field name
     * @return mixed Minimum value
     */
    public function min($field) {
        if ($this->isEmpty()) {
            return null;
        }
        
        $values = $this->pluck($field)->toArray();
        return min($values);
    }
    
    /**
     * Get the maximum value of a field
     * 
     * @param string $field Field name
     * @return mixed Maximum value
     */
    public function max($field) {
        if ($this->isEmpty()) {
            return null;
        }
        
        $values = $this->pluck($field)->toArray();
        return max($values);
    }
    
    /**
     * Get the sum of a field
     * 
     * @param string $field Field name
     * @return int|float Sum of values
     */
    public function sum($field) {
        if ($this->isEmpty()) {
            return 0;
        }
        
        $values = $this->pluck($field)->toArray();
        return array_sum($values);
    }
    
    /**
     * Get the average of a field
     * 
     * @param string $field Field name
     * @return float|null Average value
     */
    public function avg($field) {
        if ($this->isEmpty()) {
            return null;
        }
        
        return $this->sum($field) / $this->count();
    }
    
    /**
     * Merge with another collection
     * 
     * @param AbstractCollection $other Other collection
     * @return static New merged collection
     */
    public function merge(AbstractCollection $other) {
        return new static(array_merge($this->items, $other->toArray()));
    }
    
    /**
     * Get the intersection with another collection
     * 
     * @param AbstractCollection $other Other collection
     * @return static New collection with intersection
     */
    public function intersect(AbstractCollection $other) {
        $other_items = $other->toArray();
        $intersected = array_intersect($this->items, $other_items);
        return new static(array_values($intersected));
    }
    
    /**
     * Get the difference with another collection
     * 
     * @param AbstractCollection $other Other collection
     * @return static New collection with difference
     */
    public function diff(AbstractCollection $other) {
        $other_items = $other->toArray();
        $diff = array_diff($this->items, $other_items);
        return new static(array_values($diff));
    }
    
    /**
     * Check if the collection contains an item
     * 
     * @param AbstractModel|callable $item Item to search for or callback
     * @return bool Contains the item
     */
    public function contains($item) {
        if ($item instanceof AbstractModel) {
            return in_array($item, $this->items, true);
        }
        
        if (is_callable($item)) {
            foreach ($this->items as $collection_item) {
                if ($item($collection_item)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if the collection is empty
     * 
     * @return bool Is empty
     */
    public function isEmpty() {
        return empty($this->items);
    }
    
    /**
     * Check if the collection is not empty
     * 
     * @return bool Is not empty
     */
    public function isNotEmpty() {
        return !$this->isEmpty();
    }
    
    /**
     * Convert collection to array
     * 
     * @return array Array of items
     */
    public function toArray() {
        return array_map(function($item) {
            return $item instanceof AbstractModel ? $item->toArray() : $item;
        }, $this->items);
    }
    
    /**
     * Convert collection to JSON
     * 
     * @param int $options JSON encode options
     * @return string JSON representation
     */
    public function toJson($options = 0) {
        return json_encode($this->toArray(), $options);
    }
    
    /**
     * Get all items as array
     * 
     * @return array All items
     */
    public function all() {
        return $this->items;
    }
    
    /**
     * Get item values as array (for non-model items)
     * 
     * @return array Item values
     */
    public function values() {
        return array_values($this->items);
    }
    
    /**
     * Clear all items
     * 
     * @return self
     */
    public function clear() {
        $this->items = [];
        $this->position = 0;
        return $this;
    }
    
    // Iterator interface implementation
    
    public function rewind(): void {
        $this->position = 0;
    }
    
    public function current() {
        return $this->items[$this->position] ?? null;
    }
    
    public function key() {
        return $this->position;
    }
    
    public function next(): void {
        ++$this->position;
    }
    
    public function valid(): bool {
        return isset($this->items[$this->position]);
    }
    
    // Countable interface implementation
    
    public function count(): int {
        return count($this->items);
    }
    
    // ArrayAccess interface implementation
    
    public function offsetExists($offset): bool {
        return isset($this->items[$offset]);
    }
    
    public function offsetGet($offset) {
        return $this->items[$offset] ?? null;
    }
    
    public function offsetSet($offset, $value): void {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }
    
    public function offsetUnset($offset): void {
        unset($this->items[$offset]);
        $this->items = array_values($this->items); // Re-index
    }
    
    // Magic methods
    
    public function __toString() {
        return $this->toJson();
    }
    
    public function __debugInfo() {
        return [
            'count' => $this->count(),
            'items' => $this->items
        ];
    }
}