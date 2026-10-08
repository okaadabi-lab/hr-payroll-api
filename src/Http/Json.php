<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use HrPayroll\Domain\Exception\BadRequest;
use HrPayroll\Infrastructure\JsonCodec;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Json
{
    /**
     * @param array<mixed> $data
     */
    public static function response(
        ResponseInterface $response,
        array $data,
        int $status = 200,
        string $contentType = 'application/json',
    ): ResponseInterface {
        $response->getBody()->write(JsonCodec::encode($data));

        return $response->withStatus($status)->withHeader('Content-Type', $contentType);
    }

    /**
     * Decodes a JSON object request body. An empty body is treated as {}.
     *
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequest('The request body is not valid JSON.', 'invalid_json');
        }
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new BadRequest('The request body must be a JSON object.', 'invalid_json');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
