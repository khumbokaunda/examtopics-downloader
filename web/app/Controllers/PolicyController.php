<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Policies;

/** The platform's policies and notices, public, each with its version. */
final class PolicyController extends Controller
{
    public function index(): Response
    {
        return $this->render('policies/index', ['title' => 'Policies and notices', 'policies' => Policies::POLICIES]);
    }

    public function show(string $key): Response
    {
        $p = Policies::get($key) ?? throw HttpException::notFound('Policy');
        return $this->render('policies/show', ['title' => $p['title'], 'policy' => $p, 'key' => $key]);
    }
}
