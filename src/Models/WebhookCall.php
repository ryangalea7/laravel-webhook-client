<?php

namespace Spatie\WebhookClient\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidConfig;
use Spatie\WebhookClient\WebhookConfig;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Class WebhookCall
 * @package Spatie\WebhookClient\Models
 *
 * @property-read int $id
 * @property string $name
 * @property string $url
 * @property array|null $headers
 * @property array|null $payload
 * @property array|null $exception
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder|WebhookCall newModelQuery()
 * @method static Builder|WebhookCall newQuery()
 * @method static Builder|WebhookCall query()
 * @method static Builder|WebhookCall whereId($value)
 * @method static Builder|WebhookCall whereName($value)
 * @method static Builder|WebhookCall wherePayload($value)
 * @method static Builder|WebhookCall whereException($value)
 * @method static Builder|WebhookCall whereCreatedAt($value)
 * @method static Builder|WebhookCall whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class WebhookCall extends Model
{
    use MassPrunable;

    public $guarded = [];

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'exception' => 'array',
    ];

    public static function storeWebhook(WebhookConfig $config, Request $request): WebhookCall
    {
        $headers = self::headersToStore($config, $request);
        $payload = self::buildPayloadFromRequest($request);

        return self::create([
            'name' => $config->name,
            'url' => $request->fullUrl(),
            'headers' => $headers,
            'payload' => $payload,
            'exception' => null,
        ]);
    }

    protected static function buildPayloadFromRequest(Request $request): array
    {
        $payload = $request->input();

        if ($request->allFiles()) {
            $payload['attachments'] = self::processRequestFiles($request->allFiles());
        }

        return $payload;
    }

    protected static function processRequestFiles(array $files): array
    {
        return collect($files)
            ->flatMap(function ($fieldFiles) {
                if (!is_array($fieldFiles)) {
                    return [self::processUploadedFile($fieldFiles)];
                }

                return collect($fieldFiles)->map(function ($file) {
                    return self::processUploadedFile($file);
                });
            })
            ->toArray();
    }

    protected static function processUploadedFile($file): array
    {
        return [
            'originalName' => $file->getClientOriginalName(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'error' => $file->getError(),
            'path' => $file->getPathname(),
            'content' => base64_encode(file_get_contents($file->getPathname())),
        ];
    }

    public static function headersToStore(WebhookConfig $config, Request $request): array
    {
        $headerNamesToStore = $config->storeHeaders;

        if ($headerNamesToStore === '*') {
            return $request->headers->all();
        }

        $headerNamesToStore = array_map(fn(string $headerName) => strtolower($headerName), $headerNamesToStore);

        return collect($request->headers->all())
            ->filter(fn(array $headerValue, string $headerName) => in_array($headerName, $headerNamesToStore))
            ->toArray();
    }

    public function headerBag(): HeaderBag
    {
        return new HeaderBag($this->headers ?? []);
    }

    public function headers(): HeaderBag
    {
        return $this->headerBag();
    }

    public function saveException(Exception $exception): self
    {
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->save();

        return $this;
    }

    public function clearException(): self
    {
        $this->exception = null;

        $this->save();

        return $this;
    }

    public function prunable()
    {
        $days = config('webhook-client.delete_after_days');

        if (!is_int($days)) {
            throw InvalidConfig::invalidPrunable($days);
        }

        return static::where('created_at', '<', now()->subDays($days));
    }

    /**
     * Convert stored file metadata back into UploadedFile objects
     *
     * @return array
     */
    public function getAttachments(): array
    {
        if (!isset($this->payload['attachments'])) {
            return [];
        }

        return collect($this->payload['attachments'])
            ->map(function ($attachment) {
                return $this->createUploadedFileFromAttachment($attachment);
            })
            ->toArray();
    }

    protected function createUploadedFileFromAttachment(array $attachment): \Illuminate\Http\UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'webhook_');
        file_put_contents($tempFile, base64_decode($attachment['content']));

        return new \Illuminate\Http\UploadedFile($tempFile, $attachment['originalName'], $attachment['mimeType'], $attachment['error'], true);
    }
}
