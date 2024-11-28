<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Frontend\Tests\Functional\SiteHandling\Framework\Builder;

class ExceptionExpectation implements Applicable
{
    private ?string $className = null;
    private ?int $code = null;
    private ?string $message = null;

    public static function create(string $identifier): self
    {
        return new static($identifier);
    }

    private function __construct(private readonly string $identifier) {}

    public function withClassName(string $className): self
    {
        $target = clone $this;
        $target->className = $className;
        return $target;
    }

    public function withCode(int $code): self
    {
        $target = clone $this;
        $target->code = $code;
        return $target;
    }

    public function withMessage(string $message): self
    {
        $target = clone $this;
        $target->message = $message;
        return $target;
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }

    public function getCode(): ?int
    {
        return $this->code;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function describe(): string
    {
        return sprintf('Exception %s', $this->identifier);
    }
}
