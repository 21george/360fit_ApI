<?php

declare(strict_types=1);

namespace App\Helpers;

use MongoDB\Collection;

/**
 * Row-Level Security wrapper around a MongoDB collection.
 * Automatically injects ownership filters based on the current request context.
 *
 * Usage:
 *   $col = ScopedCollection::for('clients');
 *   $col->findOne(['name' => 'John']); // automatically adds coach_id or _id filter
 */
class ScopedCollection
{
    private string $name;
    private Collection $collection;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->collection = \App\Config\Database::collection($name);
    }

    public static function for(string $name): self
    {
        return new self($name);
    }

    /**
     * Get the raw MongoDB collection (bypass RLS — use with caution).
     */
    public function raw(): Collection
    {
        return $this->collection;
    }

    /* ── Read operations ──────────────────────────────────────────────── */

    public function findOne(array $filter = [], array $options = []): ?object
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->findOne($filter, $options);
    }

    public function find(array $filter = [], array $options = []): \MongoDB\Driver\Cursor
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->find($filter, $options);
    }

    public function countDocuments(array $filter = [], array $options = []): int
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->countDocuments($filter, $options);
    }

    public function distinct(string $fieldName, array $filter = [], array $options = []): array
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->distinct($fieldName, $filter, $options);
    }

    public function aggregate(array $pipeline = [], array $options = []): \MongoDB\Driver\Cursor
    {
        // For aggregations, inject a $match stage at the beginning
        $ownership = RlsScope::ownershipFilter($this->name);
        if ($ownership !== null) {
            array_unshift($pipeline, ['$match' => $ownership]);
        }
        return $this->collection->aggregate($pipeline, $options);
    }

    /* ── Write operations ─────────────────────────────────────────────── */

    public function insertOne(array|object $document, array $options = []): \MongoDB\InsertOneResult
    {
        $ownership = RlsScope::ownershipFilter($this->name);
        if ($ownership !== null && is_array($document)) {
            // Unconditionally stamp ownership from the authenticated
            // context. This is derived from the JWT, never from the
            // request body, so overwriting is safe and prevents a
            // caller from spoofing ownership via a pre-set field.
            foreach ($ownership as $field => $value) {
                $document[$field] = $value;
            }
        }
        return $this->collection->insertOne($document, $options);
    }

    public function insertMany(array $documents, array $options = []): \MongoDB\InsertManyResult
    {
        $ownership = RlsScope::ownershipFilter($this->name);
        if ($ownership !== null) {
            foreach ($documents as &$doc) {
                if (is_array($doc)) {
                    // Same rationale as insertOne: ownership is
                    // derived from auth context, always stamp it.
                    foreach ($ownership as $field => $value) {
                        $doc[$field] = $value;
                    }
                }
            }
            // Break the reference once the loop is done so the
            // last $doc doesn't leak into the next caller.
            unset($doc);
        }
        return $this->collection->insertMany($documents, $options);
    }

    public function updateOne(array $filter, array|object $update, array $options = []): \MongoDB\UpdateResult
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->updateOne($filter, $update, $options);
    }

    public function updateMany(array $filter, array|object $update, array $options = []): \MongoDB\UpdateResult
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->updateMany($filter, $update, $options);
    }

    public function replaceOne(array $filter, array|object $replacement, array $options = []): \MongoDB\UpdateResult
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->replaceOne($filter, $replacement, $options);
    }

    public function deleteOne(array $filter = [], array $options = []): \MongoDB\DeleteResult
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->deleteOne($filter, $options);
    }

    public function deleteMany(array $filter = [], array $options = []): \MongoDB\DeleteResult
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->deleteMany($filter, $options);
    }

    public function findOneAndDelete(array $filter = [], array $options = []): array|object|null
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->findOneAndDelete($filter, $options);
    }

    public function findOneAndReplace(array $filter, array|object $replacement, array $options = []): array|object|null
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->findOneAndReplace($filter, $replacement, $options);
    }

    public function findOneAndUpdate(array $filter, array|object $update, array $options = []): array|object|null
    {
        $filter = RlsScope::apply($this->name, $filter);
        return $this->collection->findOneAndUpdate($filter, $update, $options);
    }
}
