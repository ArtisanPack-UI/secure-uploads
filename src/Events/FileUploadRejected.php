<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Events;

use ArtisanPackUI\SecureUploads\FileUpload\RequestContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadRejected
{
    use Dispatchable;use InteractsWithSockets;use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $originalName,
        public readonly array $reasons,
        public readonly ?Authenticatable $user,
        public readonly RequestContext $context,
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [];
    }
}
