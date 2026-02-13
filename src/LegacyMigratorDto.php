<?php

namespace Mreycode\LegacyMigrator;

use Illuminate\Contracts\Support\Arrayable;
use Mreycode\LegacyMigrator\Enums\MigratorStatus;

class LegacyMigratorDto implements Arrayable
{
    public string $migrate;
    public MigratorStatus $status;
    public int $batch;
    public ?string $message;
    public array $meta;
    public ?string $createdAt;
    public ?string $updatedAt;

    public ?int $id = null;

    public function __construct(
        string $migrate,
        MigratorStatus $status,
        int $batch,
        ?string $message = null,
        array $meta = [],
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?int $id = null
    ) {
        $this->migrate = $migrate;
        $this->status = $status;
        $this->batch = $batch;
        $this->message = $message;
        $this->meta = $meta;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->id = $id;
    }

    /**
     * Convert DTO to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'migrate' => $this->migrate,
            'status' => $this->status->value,
            'batch' => $this->batch,
            'message' => $this->message,
            'meta' => $this->meta,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
