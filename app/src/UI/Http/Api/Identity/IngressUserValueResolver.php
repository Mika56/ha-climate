<?php

declare(strict_types=1);

namespace App\UI\Http\Api\Identity;

use App\Application\Api\IngressUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Lets a controller ask for the `?IngressUser` of the request.
 */
final readonly class IngressUserValueResolver implements ValueResolverInterface
{
    public function __construct(private IngressUserFactory $users)
    {
    }

    /**
     * @return iterable<IngressUser|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (IngressUser::class !== $argument->getType()) {
            return [];
        }

        return [$this->users->fromRequest($request)];
    }
}
