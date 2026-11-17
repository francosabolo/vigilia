<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * K8 — a camera found on the network that the technician asked to add. The stream URL carries the camera's
 * credentials: encrypted at rest and wiped as soon as the edge confirms.
 */
class CameraSetup extends Model
{
    protected $fillable = ['edge_device_id', 'name', 'host', 'stream_url', 'status', 'result', 'requested_by'];

    protected $hidden = ['stream_url'];

    protected function casts(): array
    {
        return ['stream_url' => 'encrypted'];
    }

    public function edgeDevice(): BelongsTo
    {
        return $this->belongsTo(EdgeDevice::class);
    }

    /**
     * RTSP main stream of the common brands, from the host and the credentials the technician typed.
     */
    public static function streamUrl(string $brand, string $host, string $user, string $password, ?string $custom): string
    {
        $auth = rawurlencode($user).':'.rawurlencode($password).'@';

        return match ($brand) {
            'hikvision' => "rtsp://{$auth}{$host}:554/Streaming/Channels/101",
            'dahua' => "rtsp://{$auth}{$host}:554/cam/realmonitor?channel=1&subtype=0",
            'tapo' => "rtsp://{$auth}{$host}:554/stream1",
            'reolink' => "rtsp://{$auth}{$host}:554/h264Preview_01_main",
            default => str_replace(['{user}', '{password}', '{host}'], [rawurlencode($user), rawurlencode($password), $host], (string) $custom),
        };
    }
}
