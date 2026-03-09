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

namespace TYPO3\CMS\Frontend\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ModelService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;

/**
 * Meta-data class handling cacheable states for generic frontend functionality.
 *
 * @internal
 */
#[Autoconfigure(public: true)]
readonly class MetaDataState
{
    public function __construct(
        private ModelService $modelService,
        private PolicyRegistry $policyRegistry,
        private DirectiveHashCollection $directiveHashCollection,
    ) {}

    public function getState(): array
    {
        return [
            'PolicyRegistry::$mutationCollections' => json_encode($this->policyRegistry->getMutationCollections()),
            'HashCollection' => json_encode($this->directiveHashCollection),
        ];
    }

    public function updateState(array $state): void
    {
        foreach ($state as $name => $value) {
            switch ($name) {
                case 'PolicyRegistry::$mutationCollections':
                    $this->updatePolicyRegistryMutationCollections($value);
                    break;
                case 'HashCollection':
                    $this->updateHashCollection($value);
                    break;
            }
        }
    }

    private function updatePolicyRegistryMutationCollections(mixed $value): void
    {
        $array = $this->decodeJsonString($value);
        if (is_array($array)) {
            $this->policyRegistry->setMutationsCollections(
                ...array_map($this->modelService->buildMutationCollectionFromArray(...), $array)
            );
        }
    }

    private function updateHashCollection(mixed $value): void
    {
        $array = $this->decodeJsonString($value);
        if (is_array($array)) {
            $this->directiveHashCollection->updateFromJson($array);
        }
    }

    private function decodeJsonString(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $array = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($array) ? $array : null;
    }
}
