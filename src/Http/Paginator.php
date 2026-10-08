<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Paginator
{
    /**
     * @param Page<array<string, mixed>> $page
     */
    public static function respond(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ListQuery $query,
        Page $page,
    ): ResponseInterface {
        $totalPages = max(1, (int) ceil($page->total / $query->perPage));
        $link = static function (int $number) use ($request, $query): string {
            $params = array_merge($request->getQueryParams(), ['page' => $number, 'per_page' => $query->perPage]);

            return $request->getUri()->getPath() . '?' . http_build_query($params);
        };

        return Json::response($response, [
            'data' => $page->items,
            'meta' => [
                'page' => $query->page,
                'per_page' => $query->perPage,
                'total' => $page->total,
                'total_pages' => $totalPages,
            ],
            'links' => [
                'self' => $link($query->page),
                'first' => $link(1),
                'prev' => $query->page > 1 ? $link(min($query->page - 1, $totalPages)) : null,
                'next' => $query->page < $totalPages ? $link($query->page + 1) : null,
                'last' => $link($totalPages),
            ],
        ]);
    }
}
